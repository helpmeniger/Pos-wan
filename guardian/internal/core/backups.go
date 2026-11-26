package core

import (
	"bufio"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	"marwa/guardian/internal/store"
)

// Sauvegardes de POS Marwa : fichiers « poswan_AAAAMMJJ_HHMMSS_<motif>.sql.gz » (export logique cohérent fait par
// POS Marwa, empreinte SHA-256 dans « .sha256 », marqueur « -- FIN » en dernière ligne) et, à côté, les photos
// « …_fichiers.zip ». MARWA GUARDIAN les lit sans jamais les modifier.

var backupName = regexp.MustCompile(`^poswan_(\d{8})_(\d{6})_([a-z0-9_-]+)\.sql\.gz$`)

// BackupFile : une sauvegarde trouvée, avec le résultat de sa vérification.
type BackupFile struct {
	Name    string        `json:"name"`
	Path    string        `json:"-"`
	Size    int64         `json:"size"`
	Date    time.Time     `json:"date"`
	Reason  string        `json:"reason"` // auto, manuel, avant_maj_…, support_externe…
	Zip     string        `json:"zip"`    // archive des photos, s'il y en a une
	ZipSize int64         `json:"zip_size"`
	Check   *Verification `json:"check"`
}

// Verification : contrôle réel d'une sauvegarde (jamais supposé).
type Verification struct {
	Size      int64     `json:"size"`
	ShaOK     bool      `json:"sha_ok"`   // empreinte identique à celle enregistrée par POS Marwa
	Complete  bool      `json:"complete"` // décompressée entièrement jusqu'au marqueur « -- FIN »
	Tables    int       `json:"tables"`   // tables présentes dans la sauvegarde
	Version   string    `json:"version"`  // version de POS Marwa qui l'a faite
	Error     string    `json:"error"`
	CheckedAt time.Time `json:"checked_at"`
}

// OK : sauvegarde vérifiée et utilisable.
func (v *Verification) OK() bool { return v != nil && v.ShaOK && v.Complete && v.Tables > 0 }

// BackupStatus : état affiché par la console.
type BackupStatus struct {
	Dir          string         `json:"dir"`
	Found        bool           `json:"found"` // dossier des sauvegardes trouvé
	Count        int            `json:"count"`
	TotalSize    int64          `json:"total_size"`
	Latest       *BackupFile    `json:"latest"`        // la plus récente (vérifiée ou non)
	LastVerified *BackupFile    `json:"last_verified"` // la plus récente vérifiée
	DueBefore    *time.Time     `json:"due_before"`    // une nouvelle sauvegarde est attendue avant cette date
	Recent       []BackupFile   `json:"recent"`
	LastUpdate   *BackupFile    `json:"last_update"`  // dernière sauvegarde faite avant une mise à jour
	AutoFailure  string         `json:"auto_failure"` // dernier échec de la sauvegarde automatique (journal du lanceur)
	Restore      *RestoreResult `json:"restore"`
	RestoreBusy  bool           `json:"restore_busy"`
	Copies       []CopyStatus   `json:"copies"`
	Note         string         `json:"note"`
}

// BackupDir : dossier des sauvegardes de POS Marwa (vérifié, jamais supposé).
func (a *Agent) BackupDir(p PosStatus) string {
	var cands []string
	if p.Kind == "bureau" && p.Dir != "" {
		cands = append(cands, filepath.Join(p.Dir, "app", "storage", "backups"))
	}
	if d := a.cfg().PosAppDir; d != "" {
		cands = append(cands, filepath.Join(d, "storage", "backups"))
	}
	for _, c := range cands {
		if st, err := os.Stat(c); err == nil && st.IsDir() {
			return c
		}
	}
	return ""
}

// ScanBackups : sauvegardes du dossier, de la plus récente à la plus ancienne.
func ScanBackups(dir string) ([]BackupFile, error) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	var out []BackupFile
	for _, e := range entries {
		m := backupName.FindStringSubmatch(e.Name())
		if m == nil || e.IsDir() {
			continue
		}
		info, err := e.Info()
		if err != nil {
			continue
		}
		d, _ := time.ParseInLocation("20060102150405", m[1]+m[2], time.Local)
		b := BackupFile{Name: e.Name(), Path: filepath.Join(dir, e.Name()), Size: info.Size(), Date: d, Reason: m[3]}
		zip := strings.TrimSuffix(e.Name(), ".sql.gz") + "_fichiers.zip"
		if zi, err := os.Stat(filepath.Join(dir, zip)); err == nil {
			b.Zip, b.ZipSize = zip, zi.Size()
		}
		out = append(out, b)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name > out[j].Name })
	return out, nil
}

// VerifyBackup : empreinte SHA-256 comparée à celle de POS Marwa, puis lecture complète de l'archive jusqu'au
// marqueur de fin (une sauvegarde interrompue ou abîmée n'a pas ce marqueur).
func VerifyBackup(path string) Verification {
	v := Verification{CheckedAt: time.Now()}
	st, err := os.Stat(path)
	if err != nil {
		v.Error = "fichier illisible : " + err.Error()
		return v
	}
	v.Size = st.Size()
	want, err := os.ReadFile(path + ".sha256")
	if err != nil {
		v.Error = "empreinte (.sha256) absente : la sauvegarde n'a pas été terminée par POS Marwa"
		return v
	}
	f, err := os.Open(path)
	if err != nil {
		v.Error = "fichier illisible : " + err.Error()
		return v
	}
	defer f.Close()
	h := sha256.New()
	gz, err := gzip.NewReader(io.TeeReader(f, h))
	if err != nil {
		v.Error = "archive illisible : " + err.Error()
		return v
	}
	r := bufio.NewReaderSize(gz, 1<<20)
	last := ""
	first := true
	for {
		line, err := r.ReadString('\n')
		t := strings.TrimSpace(line)
		if first && strings.HasPrefix(t, "-- POS Marwa sauvegarde") {
			if i := strings.Index(t, " version "); i > 0 {
				v.Version = strings.TrimSpace(t[i+9:])
			}
			first = false
		}
		if strings.HasPrefix(t, "DROP TABLE IF EXISTS ") {
			v.Tables++
		}
		if t != "" {
			last = t
		}
		if err == io.EOF {
			break
		}
		if err != nil {
			v.Error = "archive abîmée : " + err.Error()
			return v
		}
	}
	_, _ = io.Copy(h, f) // reste éventuel du fichier après la fin de l'archive
	v.ShaOK = strings.EqualFold(strings.TrimSpace(string(want)), hex.EncodeToString(h.Sum(nil)))
	v.Complete = last == "-- FIN"
	switch {
	case !v.ShaOK:
		v.Error = "l'empreinte ne correspond pas : le fichier a été modifié ou abîmé après la sauvegarde"
	case !v.Complete:
		v.Error = "sauvegarde incomplète : le marqueur de fin est absent"
	case v.Tables == 0:
		v.Error = "aucune table dans la sauvegarde"
	}
	return v
}

// verification : résultat gardé (une sauvegarde n'est relue que si sa taille change).
func (a *Agent) verification(b BackupFile) *Verification {
	key := "verif:" + b.Name
	var v Verification
	if raw := a.S.Get1(key); raw != "" && json.Unmarshal([]byte(raw), &v) == nil && v.Size == b.Size {
		return &v
	}
	v = VerifyBackup(b.Path)
	if raw, err := json.Marshal(v); err == nil {
		_ = a.S.Set(key, string(raw))
	}
	return &v
}

func ago(d time.Duration) string {
	switch {
	case d < 0:
		return "moins d'une minute"
	case d < 2*time.Hour:
		return fmt.Sprintf("%d minutes", int(d.Minutes()))
	case d < 48*time.Hour:
		return fmt.Sprintf("%d heures", int(d.Hours()))
	}
	return fmt.Sprintf("%d jours", int(d.Hours()/24))
}

func size(b int64) string {
	switch {
	case b >= 1<<30:
		return strings.Replace(fmt.Sprintf("%.1f Go", float64(b)/(1<<30)), ".", ",", 1)
	case b >= 1<<20:
		return strings.Replace(fmt.Sprintf("%.1f Mo", float64(b)/(1<<20)), ".", ",", 1)
	}
	return fmt.Sprintf("%d Ko", b/1024)
}

// CheckBackups : présence, vérification et fraîcheur des sauvegardes ; échecs de la sauvegarde automatique.
func (a *Agent) CheckBackups() {
	cfg := a.cfg()
	now := a.Now()
	p := a.Status().Pos
	if p.Kind == "" {
		p = a.posInfo()
	}
	st := BackupStatus{}
	defer func() {
		st.RestoreBusy = a.isBusy("restauration")
		st.Restore = a.lastRestore()
		st.Copies = a.copyStatuses()
		a.set(func(s *Status) { s.Backups = st })
	}()
	if p.Kind == "absent" {
		st.Note = "POS Marwa n'a pas été trouvé sur cet ordinateur : aucune sauvegarde à surveiller."
		return
	}
	dir := a.BackupDir(p)
	if dir == "" {
		st.Note = "Dossier des sauvegardes de POS Marwa introuvable."
		if p.Kind == "wamp" {
			st.Note += " Indiquez le dossier de l'application POS Marwa dans la configuration (WampServer)."
		}
		a.fail("sauvegardes", fmt.Errorf("%s", st.Note))
		return
	}
	st.Dir, st.Found = dir, true
	list, err := ScanBackups(dir)
	if err != nil {
		a.fail("sauvegardes", err)
		return
	}
	a.ok("sauvegardes")
	st.Count = len(list)
	for i := range list {
		st.TotalSize += list[i].Size + list[i].ZipSize
		if i < 15 {
			list[i].Check = a.verification(list[i]) // les 15 plus récentes : vérifiées (une seule fois chacune)
		}
		if st.LastUpdate == nil && strings.HasPrefix(list[i].Reason, "avant_maj") {
			b := list[i]
			st.LastUpdate = &b
		}
	}
	if len(list) > 0 {
		st.Latest = &list[0]
	}
	for i := range list {
		if list[i].Check.OK() {
			st.LastVerified = &list[i]
			break
		}
	}
	st.Recent = list[:min(len(list), 15)]

	// Échec de la sauvegarde automatique, d'après le journal du lanceur de l'édition Bureau
	if p.Kind == "bureau" {
		if msg, at := lastAutoBackupFailure(filepath.Join(p.Dir, "donnees", "journaux", "lanceur.log"), now.Add(-48*time.Hour)); msg != "" {
			st.AutoFailure = "Une sauvegarde automatique a échoué le " + at.Format("02/01/2006 à 15:04") + " : " + msg
			a.raise(store.Incident{Code: "sauvegarde_auto_echec", Key: at.Format("2006-01-02T15:04"), Severity: store.Warning, Title: "Sauvegarde automatique échouée",
				Problem:         "La dernière sauvegarde automatique n'a pas abouti (" + at.Format("02/01/2006 à 15:04") + ") : " + msg,
				Consequences:    "Vos données récentes pourraient ne pas être récupérables en cas de panne.",
				Recommendations: []string{"Consultez les détails, vérifiez l'espace disque et le support de copie, puis relancez la sauvegarde (POS Marwa → Sauvegardes)."},
				Details:         map[string]string{"Journal": "donnees\\journaux\\lanceur.log"}, Confidence: "certain"})
		}
	}
	// Sauvegarde abîmée (la plus récente) : critique, avec la dernière bonne
	if st.Latest != nil && st.Latest.Check != nil && !st.Latest.Check.OK() {
		prev := "aucune sauvegarde vérifiée n'est disponible"
		if st.LastVerified != nil {
			prev = "la dernière sauvegarde vérifiée date du " + st.LastVerified.Date.Format("02/01/2006 à 15:04")
		}
		a.raise(store.Incident{Code: "sauvegarde_abimee", Key: st.Latest.Name, Severity: store.Critical, Title: "Sauvegarde abîmée",
			Problem:      fmt.Sprintf("La sauvegarde du %s ne peut pas être utilisée : %s. Pour une restauration, %s.", st.Latest.Date.Format("02/01/2006 à 15:04"), st.Latest.Check.Error, prev),
			Consequences: "En cas de panne, les données enregistrées depuis la dernière sauvegarde vérifiée ne seraient pas récupérables.",
			Recommendations: []string{"Relancez une sauvegarde depuis POS Marwa (Sauvegardes → Sauvegarder maintenant).",
				"Vérifiez l'espace disque et l'état du disque (voir les autres alertes).", "Ne supprimez pas les anciennes sauvegardes vérifiées."},
			Details: map[string]string{"Fichier": st.Latest.Name, "Taille": size(st.Latest.Size), "Dossier": dir}, Confidence: "certain"})
	}
	// Fraîcheur : comptée depuis la dernière sauvegarde VÉRIFIÉE (une sauvegarde non vérifiée ne compte pas)
	if st.LastVerified == nil {
		if len(list) == 0 {
			a.raise(store.Incident{Code: "sauvegarde_absente", Key: "", Severity: store.Critical, Title: "Aucune sauvegarde de POS Marwa",
				Problem:         "Aucune sauvegarde de la base de POS Marwa n'existe sur cet ordinateur.",
				Consequences:    "En cas de panne du disque, de vol ou de virus, toutes les ventes, articles et clients seraient perdus.",
				Recommendations: []string{"Ouvrez POS Marwa → Sauvegardes → Sauvegarder maintenant.", "Activez la sauvegarde automatique et la copie sur une clé USB (POS Marwa → Sauvegardes)."},
				Details:         map[string]string{"Dossier": dir}, Confidence: "certain"})
		}
	} else {
		a.clear("sauvegarde_absente", "", "sauvegarde vérifiée trouvée")
		age := now.Sub(st.LastVerified.Date)
		due := st.LastVerified.Date.Add(time.Duration(cfg.BackupWarnHours) * time.Hour)
		st.DueBefore = &due
		sev := 0
		switch {
		case age > time.Duration(cfg.BackupCritHours)*time.Hour:
			sev = store.Critical
		case age > time.Duration(cfg.BackupWarnHours)*time.Hour:
			sev = store.Warning
		}
		if sev == 0 {
			a.clear("sauvegarde_ancienne", "", "sauvegarde récente")
		} else {
			problem := fmt.Sprintf("Votre dernière sauvegarde vérifiée date de %s (%s).", ago(age), st.LastVerified.Date.Format("02/01/2006 à 15:04"))
			if st.AutoFailure != "" {
				problem += " " + st.AutoFailure
			}
			a.raise(store.Incident{Code: "sauvegarde_ancienne", Key: "", Severity: sev, Title: "Sauvegarde trop ancienne",
				Problem:      problem,
				Consequences: "Les ventes enregistrées depuis cette date ne seraient pas récupérables en cas de panne.",
				Recommendations: []string{"Vérifiez l'espace disque, puis lancez une sauvegarde avant de poursuivre vos activités (POS Marwa → Sauvegardes).",
					"Vérifiez que la sauvegarde automatique est activée (POS Marwa → Sauvegardes → Sauvegarde et copie automatiques)."},
				Details: map[string]string{"Dernière sauvegarde vérifiée": st.LastVerified.Name, "Seuils": fmt.Sprintf("avertissement après %d h, critique après %d h", cfg.BackupWarnHours, cfg.BackupCritHours)}, Confidence: "certain"})
		}
	}
}

// lastAutoBackupFailure : dernier message « Sauvegarde automatique : … » d'échec du lanceur depuis une date, s'il n'a pas été
// suivi d'une réussite. Format : « 2026/11/26 14:02:00 Sauvegarde automatique : <erreur> ».
func lastAutoBackupFailure(path string, since time.Time) (string, time.Time) {
	b, err := os.ReadFile(path)
	if err != nil {
		return "", time.Time{}
	}
	text := string(b)
	if len(text) > 2<<20 {
		text = text[len(text)-(2<<20):]
	}
	msg, at := "", time.Time{}
	for _, l := range strings.Split(text, "\n") {
		if len(l) < 20 || !strings.Contains(l, " Sauvegarde automatique : ") {
			continue
		}
		t, err := time.ParseInLocation("2006/01/02 15:04:05", l[:19], time.Local)
		if err != nil || t.Before(since) {
			continue
		}
		rest := strings.TrimSpace(l[strings.Index(l, " Sauvegarde automatique : ")+len(" Sauvegarde automatique : "):])
		if strings.Contains(rest, "poswan_") || strings.HasPrefix(rest, "; support") { // « poswan_… ; support : … » : réussite
			msg, at = "", time.Time{}
			continue
		}
		msg, at = rest, t
	}
	return msg, at
}

func (a *Agent) isBusy(k string) bool {
	_, ok := a.busy.Load(k)
	return ok
}
