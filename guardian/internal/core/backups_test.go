package core

import (
	"archive/zip"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
	"time"

	"marwa/guardian/internal/store"
)

// dump : contenu d'une sauvegarde au format de POS Marwa (BackupService::create).
func dump(rows int, broken bool) string {
	var b strings.Builder
	b.WriteString("-- POS Marwa sauvegarde 2026-11-26T08:00:00+01:00 version 3.17.1\n")
	b.WriteString("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\nSET FOREIGN_KEY_CHECKS=0;\n")
	for _, t := range []string{"customers", "products", "sales", "users"} {
		fmt.Fprintf(&b, "DROP TABLE IF EXISTS `%s`;\nCREATE TABLE `%s` (`id` int unsigned NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n", t, t)
		var vals []string
		for i := 1; i <= rows; i++ {
			vals = append(vals, fmt.Sprintf("(%d,'%s n° %d — l''essai')", i, t, i))
		}
		fmt.Fprintf(&b, "INSERT INTO `%s` (`id`,`name`) VALUES %s;\n", t, strings.Join(vals, ","))
	}
	if broken {
		b.WriteString("INSERT INTO `inexistante` VALUES (1);\n")
	}
	b.WriteString("SET FOREIGN_KEY_CHECKS=1;\n-- FIN\n")
	return b.String()
}

// writeBackup : sauvegarde (et son empreinte), datée.
func writeBackup(t *testing.T, dir string, at time.Time, reason, content string, withZip bool) string {
	t.Helper()
	name := "poswan_" + at.Format("20060102_150405") + "_" + reason + ".sql.gz"
	var buf bytes.Buffer
	zw := gzip.NewWriter(&buf)
	zw.Write([]byte(content))
	zw.Close()
	p := filepath.Join(dir, name)
	if err := os.WriteFile(p, buf.Bytes(), 0o644); err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256(buf.Bytes())
	_ = os.WriteFile(p+".sha256", []byte(hex.EncodeToString(sum[:])), 0o644)
	if withZip {
		z := strings.TrimSuffix(p, ".sql.gz") + "_fichiers.zip"
		_ = os.WriteFile(z, []byte("PK photos"), 0o644)
		s2 := sha256.Sum256([]byte("PK photos"))
		_ = os.WriteFile(z+".sha256", []byte(hex.EncodeToString(s2[:])), 0o644)
	}
	return p
}

// bureau : installation POS Marwa Bureau factice (dossier, sauvegardes, journaux) déclarée à l'agent.
func bureau(t *testing.T, a *Agent) (pos, bk string) {
	t.Helper()
	pos = t.TempDir()
	bk = filepath.Join(pos, "app", "storage", "backups")
	for _, d := range []string{bk, filepath.Join(pos, "donnees", "journaux"), filepath.Join(pos, "donnees", "secret")} {
		_ = os.MkdirAll(d, 0o755)
	}
	_ = os.WriteFile(filepath.Join(pos, "pos-marwa.ini"), []byte("PORT_WEB=1\n"), 0o644)
	_ = os.WriteFile(filepath.Join(pos, "donnees", "secret", "bd.dat"), []byte("PMS1:chiffre"), 0o600)
	a.Cfg.PosDir = pos
	a.CheckPos()
	return
}

func TestVerifyBackup(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	good := writeBackup(t, dir, now, "auto", dump(3, false), false)
	if v := VerifyBackup(good); !v.OK() || v.Tables != 4 || v.Version != "3.17.1" {
		t.Fatalf("sauvegarde saine : %+v", v)
	}
	// Un octet modifié : empreinte différente
	b, _ := os.ReadFile(good)
	b[len(b)/2] ^= 0xff
	bad := filepath.Join(dir, "poswan_20261126_090000_auto.sql.gz")
	_ = os.WriteFile(bad, b, 0o644)
	sha, _ := os.ReadFile(good + ".sha256")
	_ = os.WriteFile(bad+".sha256", sha, 0o644)
	if v := VerifyBackup(bad); v.OK() || v.Error == "" {
		t.Fatalf("sauvegarde modifiée non détectée : %+v", v)
	}
	// Interrompue (pas de marqueur de fin), empreinte cohérente
	cut := writeBackup(t, dir, now.Add(time.Hour), "auto", strings.TrimSuffix(dump(3, false), "-- FIN\n"), false)
	if v := VerifyBackup(cut); v.OK() || !strings.Contains(v.Error, "incomplète") {
		t.Fatalf("sauvegarde incomplète non détectée : %+v", v)
	}
	// Sans empreinte
	_ = os.Remove(cut + ".sha256")
	if v := VerifyBackup(cut); v.OK() || !strings.Contains(v.Error, ".sha256") {
		t.Fatalf("empreinte absente : %+v", v)
	}
}

func TestCheckBackupsFreshnessAndDamage(t *testing.T) {
	fixtures(t, map[string]string{})
	a, s := testAgent(t)
	now := a.Now()
	_, bk := bureau(t, a)
	a.CheckBackups()
	if in := open(s, "sauvegarde_absente"); len(in) != 1 || in[0].Severity != store.Critical {
		t.Fatalf("aucune sauvegarde : incident critique attendu : %+v", in)
	}
	writeBackup(t, bk, now.Add(-30*time.Hour), "auto", dump(2, false), true)
	a.CheckBackups()
	if len(open(s, "sauvegarde_absente")) != 0 {
		t.Fatal("sauvegarde trouvée : alerte « aucune sauvegarde » résolue")
	}
	in := open(s, "sauvegarde_ancienne")
	if len(in) != 1 || in[0].Severity != store.Warning || !strings.Contains(in[0].Problem, "date de 30 heures") {
		t.Fatalf("sauvegarde de 30 h : avertissement attendu : %+v", in)
	}
	// La plus récente est abîmée : elle ne compte pas, la précédente vérifiée est citée
	p := writeBackup(t, bk, now.Add(-1*time.Hour), "auto", dump(2, false), false)
	_ = os.WriteFile(p+".sha256", []byte(strings.Repeat("0", 64)), 0o644)
	a.CheckBackups()
	ab := open(s, "sauvegarde_abimee")
	if len(ab) != 1 || !strings.Contains(ab[0].Problem, "la dernière sauvegarde vérifiée date du") {
		t.Fatalf("sauvegarde abîmée : %+v", ab)
	}
	st := a.Status().Backups
	if st.Latest == nil || st.LastVerified == nil || st.Latest.Name == st.LastVerified.Name || st.Count != 2 {
		t.Fatalf("dernière / dernière vérifiée : %+v", st)
	}
	if len(open(s, "sauvegarde_ancienne")) != 1 {
		t.Fatal("une sauvegarde abîmée ne remet pas le compteur à zéro")
	}
	// Bonne sauvegarde récente : plus d'alerte d'ancienneté
	writeBackup(t, bk, now.Add(-10*time.Minute), "manuel", dump(2, false), false)
	a.CheckBackups()
	if len(open(s, "sauvegarde_ancienne")) != 0 {
		t.Fatal("sauvegarde récente vérifiée : alerte d'ancienneté résolue")
	}
	// Critique au-delà de 72 h
	a2, s2 := testAgent(t)
	_, bk2 := bureau(t, a2)
	writeBackup(t, bk2, a2.Now().Add(-80*time.Hour), "auto", dump(1, false), false)
	a2.CheckBackups()
	if in := open(s2, "sauvegarde_ancienne"); len(in) != 1 || in[0].Severity != store.Critical || !strings.Contains(in[0].Problem, "date de 3 jours") {
		t.Fatalf("sauvegarde de 3 jours : critique attendu : %+v", in)
	}
}

func TestAutoBackupFailureFromLauncherLog(t *testing.T) {
	fixtures(t, map[string]string{})
	a, s := testAgent(t)
	pos, bk := bureau(t, a)
	writeBackup(t, bk, a.Now().Add(-2*time.Hour), "auto", dump(1, false), false)
	at := time.Now().Add(-3 * time.Hour).Format("2006/01/02 15:04:05")
	log := at + " Sauvegarde automatique : Espace insuffisant sur le disque\n"
	_ = os.WriteFile(filepath.Join(pos, "donnees", "journaux", "lanceur.log"), []byte(log), 0o644)
	a.Now = time.Now
	a.CheckBackups()
	in := open(s, "sauvegarde_auto_echec")
	if len(in) != 1 || !strings.Contains(in[0].Problem, "Espace insuffisant") {
		t.Fatalf("échec de la sauvegarde automatique attendu : %+v", in)
	}
	// Une réussite après l'échec : plus rien à signaler
	ok := time.Now().Add(-time.Hour).Format("2006/01/02 15:04:05") + " Sauvegarde automatique : poswan_x_auto.sql.gz ; support : aucun (0 fichier(s) copié(s))\n"
	_ = os.WriteFile(filepath.Join(pos, "donnees", "journaux", "lanceur.log"), []byte(log+ok), 0o644)
	if msg, _ := lastAutoBackupFailure(filepath.Join(pos, "donnees", "journaux", "lanceur.log"), time.Now().Add(-48*time.Hour)); msg != "" {
		t.Fatalf("réussite après l'échec : rien attendu, %q", msg)
	}
}

func TestCopiesRotationAndConfig(t *testing.T) {
	usb := t.TempDir()
	fixtures(t, map[string]string{"volumes.json": `[{"root": "` + usb + `", "label": "CLE-CAISSE", "removable": true}]`})
	a, s := testAgent(t)
	pos, bk := bureau(t, a)
	now := a.Now()
	for i := 4; i >= 1; i-- {
		writeBackup(t, bk, now.Add(-time.Duration(i)*24*time.Hour), "auto", dump(i, false), i == 1)
	}
	other := t.TempDir()
	a.Cfg.Destinations = []Destination{{ID: "d1", Kind: "dossier", Path: other, Keep: 2}, {ID: "d2", Kind: "support", Label: "CLE-CAISSE", Keep: 30}}
	a.CheckBackups()
	a.RunCopies()
	host := hostname()
	f1 := filepath.Join(other, "MARWA GUARDIAN", host)
	m, _ := filepath.Glob(filepath.Join(f1, "poswan_*.sql.gz"))
	if len(m) != 2 {
		t.Fatalf("dossier : 2 sauvegardes gardées attendues, %d", len(m))
	}
	sort.Strings(m)
	if !strings.Contains(m[1], now.Add(-24*time.Hour).Format("20060102")) {
		t.Fatalf("les plus récentes sont gardées : %v", m)
	}
	if _, err := os.Stat(filepath.Join(f1, filepath.Base(strings.TrimSuffix(m[1], ".sql.gz"))+"_fichiers.zip")); err != nil {
		t.Fatal("archive des photos copiée avec la sauvegarde")
	}
	m2, _ := filepath.Glob(filepath.Join(usb, "MARWA GUARDIAN", host, "poswan_*.sql.gz"))
	if len(m2) != 4 {
		t.Fatalf("clé USB : 4 sauvegardes attendues, %d", len(m2))
	}
	// Copies contrôlées : identiques à l'original
	for _, c := range m2 {
		a1, _ := fileSHA(c)
		a2, _ := fileSHA(filepath.Join(bk, filepath.Base(c)))
		if a1 != a2 {
			t.Fatalf("copie différente de l'original : %s", c)
		}
	}
	// Originaux intacts
	orig, _ := filepath.Glob(filepath.Join(bk, "poswan_*.sql.gz"))
	if len(orig) != 4 {
		t.Fatal("les sauvegardes d'origine ne sont jamais supprimées")
	}
	// Copie de la configuration
	cz, _ := filepath.Glob(filepath.Join(f1, "configuration_*.zip"))
	if len(cz) != 1 {
		t.Fatalf("copie de la configuration attendue : %v", cz)
	}
	zr, err := zip.OpenReader(cz[0])
	if err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, f := range zr.File {
		names = append(names, f.Name)
	}
	zr.Close()
	if strings.Join(names, ",") != "pos-marwa.ini,donnees/secret/bd.dat" {
		t.Fatalf("contenu de la configuration : %v", names)
	}
	// Second passage : rien à recopier ; un fichier étranger au dossier n'est jamais supprimé
	_ = os.WriteFile(filepath.Join(f1, "note-du-gerant.txt"), []byte("à garder"), 0o644)
	a.RunCopies()
	for _, c := range a.Status().Backups.Copies {
		if c.Copied != 0 || c.Error != "" || c.LastOK == nil {
			t.Fatalf("second passage : %+v", c)
		}
	}
	if _, err := os.Stat(filepath.Join(f1, "note-du-gerant.txt")); err != nil {
		t.Fatal("un fichier qui n'est pas une copie de MARWA GUARDIAN n'est jamais supprimé")
	}
	// Clé débranchée : signalée sans erreur, alerte après 7 jours sans copie (jamais copiée ici : alerte)
	fixtures(t, map[string]string{"volumes.json": `[]`})
	a.Cfg.Destinations = []Destination{{ID: "d3", Kind: "support", Label: "AUTRE-CLE", Keep: 5}}
	a.RunCopies()
	if in := open(s, "copie_absente"); len(in) != 1 || !strings.Contains(in[0].Recommendations[0], "AUTRE-CLE") {
		t.Fatalf("support jamais branché : alerte attendue : %+v", in)
	}
	_ = pos
}

func TestRestoreTestRealMariaDB(t *testing.T) {
	if _, err := os.Stat("/usr/sbin/mariadbd"); err != nil {
		t.Skip("MariaDB absent")
	}
	fixtures(t, map[string]string{})
	a, s := testAgent(t)
	a.Now = time.Now
	_, bk := bureau(t, a)
	writeBackup(t, bk, time.Now().Add(-time.Hour), "auto", dump(25, false), false)
	a.CheckBackups()
	lv := a.Status().Backups.LastVerified
	if lv == nil {
		t.Fatal("sauvegarde vérifiée attendue")
	}
	r := a.RestoreTest(*lv)
	if !r.OK || r.Tables != 4 || r.Counts["sales"] != 25 || r.Counts["customers"] != 25 {
		t.Fatalf("restauration réelle : %+v", r)
	}
	if _, err := os.Stat(filepath.Join(a.Dir, "test-restauration")); !os.IsNotExist(err) {
		t.Fatal("base temporaire effacée après le test")
	}
	// Sauvegarde qui ne se restaure pas (requête invalide) : échec signalé
	bad := writeBackup(t, bk, time.Now(), "manuel", dump(2, true), false)
	r = a.RestoreTest(BackupFile{Name: filepath.Base(bad), Path: bad})
	if r.OK || !strings.Contains(r.Error, "ne se restaure pas") {
		t.Fatalf("échec de restauration attendu : %+v", r)
	}
	a.runRestore(BackupFile{Name: filepath.Base(bad), Path: bad, Date: time.Now()})
	if len(open(s, "restauration_echec")) != 1 {
		t.Fatal("incident critique après un test en échec")
	}
	a.runRestore(*lv)
	if len(open(s, "restauration_echec")) != 0 || a.lastRestore() == nil || !a.lastRestore().OK {
		t.Fatal("test réussi : incident résolu, résultat gardé")
	}
}
