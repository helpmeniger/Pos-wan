package core

import (
	"bytes"
	"compress/gzip"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"

	"marwa/guardian/internal/platform"
	"marwa/guardian/internal/store"
)

// Test de restauration : la sauvegarde est rejouée dans une base MariaDB TEMPORAIRE, propre à MARWA GUARDIAN
// (son propre dossier, un port libre sur 127.0.0.1, un mot de passe aléatoire), jamais dans la base de POS Marwa.
// La base temporaire est arrêtée puis effacée à la fin (son dossier ne contient que la copie de test).

// RestoreResult : résultat du dernier test.
type RestoreResult struct {
	Backup   string           `json:"backup"`
	At       time.Time        `json:"at"`
	OK       bool             `json:"ok"`
	Duration float64          `json:"duration_s"`
	Tables   int              `json:"tables"`
	Counts   map[string]int64 `json:"counts"` // ventes, articles, clients… réellement relus
	Error    string           `json:"error"`
	Expected int              `json:"expected_tables"`
}

// mariaBins : programmes MariaDB à utiliser (POS Marwa Bureau, réglage, ou système pour les essais).
func (a *Agent) mariaBins() (map[string]string, error) {
	var dirs []string
	if d := a.cfg().MariaDBBin; d != "" {
		dirs = append(dirs, d)
	}
	if d := os.Getenv("GUARDIAN_MARIADB_BIN"); d != "" {
		dirs = append(dirs, d)
	}
	if p := a.Status().Pos; p.Kind == "bureau" && p.Dir != "" {
		dirs = append(dirs, filepath.Join(p.Dir, "mariadb", "bin"))
	}
	out := map[string]string{}
	for _, n := range []string{"mariadbd", "mariadb-install-db", "mariadb", "mariadb-admin"} {
		for _, d := range dirs {
			p := filepath.Join(d, platform.ExeName(n))
			if _, err := os.Stat(p); err == nil {
				out[n] = p
				break
			}
		}
		if out[n] == "" && runtime.GOOS != "windows" {
			if p, err := exec.LookPath(n); err == nil {
				out[n] = p
			} else if _, err := os.Stat("/usr/sbin/" + n); err == nil {
				out[n] = "/usr/sbin/" + n
			}
		}
		if out[n] == "" {
			return nil, fmt.Errorf("programme %s introuvable : indiquez le dossier des programmes MariaDB dans la configuration", platform.ExeName(n))
		}
	}
	return out, nil
}

func freePort() (int, error) {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return 0, err
	}
	defer l.Close()
	return l.Addr().(*net.TCPAddr).Port, nil
}

func tail(b []byte, n int) string {
	s := strings.TrimSpace(string(b))
	if len(s) > n {
		s = "…" + s[len(s)-n:]
	}
	return s
}

// RestoreTest : rejoue une sauvegarde vérifiée dans une base temporaire et relit son contenu.
func (a *Agent) RestoreTest(b BackupFile) (res RestoreResult) {
	start := time.Now()
	res = RestoreResult{Backup: b.Name, At: a.Now(), Counts: map[string]int64{}}
	defer func() { res.Duration = time.Since(start).Seconds() }()
	if b.Check != nil {
		res.Expected = b.Check.Tables
	}
	bins, err := a.mariaBins()
	if err != nil {
		res.Error = err.Error()
		return
	}
	work := filepath.Join(a.Dir, "test-restauration")
	_ = os.RemoveAll(work) // reste d'un test interrompu : dossier propre à MARWA GUARDIAN
	data := filepath.Join(work, "donnees")
	if err := os.MkdirAll(data, 0o700); err != nil {
		res.Error = err.Error()
		return
	}
	defer os.RemoveAll(work)
	// Place disponible : environ 10 fois la sauvegarde compressée
	if free, err := platform.FreeSpace(work); err == nil && free < uint64(b.Size)*10+(200<<20) {
		res.Error = fmt.Sprintf("espace insuffisant pour le test (%s libres)", size(int64(free)))
		return
	}
	pw := make([]byte, 12)
	_, _ = rand.Read(pw)
	pass := hex.EncodeToString(pw)
	port, err := freePort()
	if err != nil {
		res.Error = err.Error()
		return
	}
	// 1. Base vide
	var inst *exec.Cmd
	if runtime.GOOS == "windows" {
		inst = exec.Command(bins["mariadb-install-db"], "--datadir="+data, "--password="+pass, "--port="+strconv.Itoa(port))
	} else {
		base := os.Getenv("GUARDIAN_MARIADB_BASEDIR")
		if base == "" {
			base = "/usr"
		}
		inst = exec.Command(bins["mariadb-install-db"], "--no-defaults", "--datadir="+data, "--auth-root-authentication-method=normal", "--skip-test-db", "--basedir="+base)
		pass = "" // essais sous Linux : root sans mot de passe, base locale temporaire
	}
	platform.LowPriority(inst)
	if out, err := inst.CombinedOutput(); err != nil {
		res.Error = "création de la base de test impossible : " + tail(out, 400)
		return
	}
	// 2. Serveur temporaire : 127.0.0.1, port libre, petite mémoire
	args := []string{"--no-defaults", "--datadir=" + data, "--port=" + strconv.Itoa(port), "--bind-address=127.0.0.1",
		"--innodb-buffer-pool-size=64M", "--skip-log-bin", "--character-set-server=utf8mb4", "--collation-server=utf8mb4_unicode_ci", "--max-allowed-packet=256M"}
	if runtime.GOOS != "windows" {
		// Fichier de connexion locale : chemin court obligatoire (107 caractères au plus sous Linux)
		sock := filepath.Join(os.TempDir(), "guardian-verif-"+strconv.Itoa(port)+".sock")
		defer os.Remove(sock)
		args = append(args, "--socket="+sock, "--pid-file="+filepath.Join(work, "m.pid"))
		if os.Geteuid() == 0 {
			args = append(args, "--user=root")
		}
	}
	var srvLog bytes.Buffer
	srv := platform.LowPriority(exec.Command(bins["mariadbd"], args...))
	srv.Stdout, srv.Stderr = &srvLog, &srvLog
	if err := srv.Start(); err != nil {
		res.Error = "démarrage de la base de test impossible : " + err.Error()
		return
	}
	exited := make(chan struct{})
	go func() { _ = srv.Wait(); close(exited) }()
	defer func() {
		admin := platform.LowPriority(exec.Command(bins["mariadb-admin"], "--no-defaults", "-h127.0.0.1", "-P"+strconv.Itoa(port), "-uroot", "shutdown"))
		admin.Env = append(os.Environ(), "MYSQL_PWD="+pass)
		_ = admin.Run()
		select {
		case <-exited:
		case <-time.After(30 * time.Second):
			_ = srv.Process.Kill()
			<-exited
		}
	}()
	ready := false
	for i := 0; i < 120 && !ready; i++ {
		select {
		case <-exited:
			res.Error = "la base de test s'est arrêtée : " + tail(srvLog.Bytes(), 400)
			return
		case <-time.After(500 * time.Millisecond):
		}
		if c, err := net.DialTimeout("tcp", "127.0.0.1:"+strconv.Itoa(port), time.Second); err == nil {
			c.Close()
			ready = true
		}
	}
	if !ready {
		res.Error = "la base de test ne répond pas"
		return
	}
	client := func(stdin *gzip.Reader, sql string, db string) ([]byte, error) {
		ca := []string{"--no-defaults", "-h127.0.0.1", "-P" + strconv.Itoa(port), "-uroot", "--default-character-set=utf8mb4", "-N", "-B"}
		if db != "" {
			ca = append(ca, db)
		}
		if sql != "" {
			ca = append(ca, "-e", sql)
		}
		c := platform.LowPriority(exec.Command(bins["mariadb"], ca...))
		c.Env = append(os.Environ(), "MYSQL_PWD="+pass)
		if stdin != nil {
			c.Stdin = stdin
		}
		return c.CombinedOutput()
	}
	if out, err := client(nil, "CREATE DATABASE verif CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", ""); err != nil {
		res.Error = "base de test : " + tail(out, 300)
		return
	}
	// 3. Rejeu de la sauvegarde (décompressée au fil de l'eau)
	f, err := os.Open(b.Path)
	if err != nil {
		res.Error = err.Error()
		return
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		res.Error = "archive illisible : " + err.Error()
		return
	}
	if out, err := client(gz, "", "verif"); err != nil {
		res.Error = "la sauvegarde ne se restaure pas : " + tail(out, 500)
		return
	}
	// 4. Relecture : tables et lignes principales réellement présentes
	out, err := client(nil, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'verif'", "")
	if err != nil {
		res.Error = "relecture : " + tail(out, 300)
		return
	}
	res.Tables, _ = strconv.Atoi(strings.TrimSpace(string(out)))
	for _, t := range []string{"sales", "sale_items", "products", "customers", "users"} {
		o, err := client(nil, "SELECT COUNT(*) FROM `"+t+"`", "verif")
		if err == nil {
			n, _ := strconv.ParseInt(strings.TrimSpace(string(o)), 10, 64)
			res.Counts[t] = n
		}
	}
	switch {
	case res.Tables == 0:
		res.Error = "aucune table restaurée"
	case res.Expected > 0 && res.Tables < res.Expected:
		res.Error = fmt.Sprintf("%d tables restaurées sur %d", res.Tables, res.Expected)
	case len(res.Counts) == 0:
		res.Error = "tables principales de POS Marwa absentes"
	default:
		res.OK = true
	}
	return
}

func (a *Agent) lastRestore() *RestoreResult {
	var r RestoreResult
	if raw := a.S.Get1("restauration_derniere"); raw != "" && json.Unmarshal([]byte(raw), &r) == nil {
		return &r
	}
	return nil
}

// ErrBusy : une tâche du même type est déjà en cours.
var ErrBusy = errors.New("un test de restauration est déjà en cours")

// StartRestoreTest : lance le test en arrière-plan (la surveillance continue pendant ce temps).
func (a *Agent) StartRestoreTest() error {
	b := a.Status().Backups.LastVerified
	if b == nil {
		return errors.New("aucune sauvegarde vérifiée à tester")
	}
	if _, loaded := a.busy.LoadOrStore("restauration", true); loaded {
		return ErrBusy
	}
	bf := *b
	a.set(func(s *Status) { s.Backups.RestoreBusy = true })
	go func() {
		defer a.busy.Delete("restauration")
		a.runRestore(bf)
	}()
	return nil
}

func (a *Agent) runRestore(b BackupFile) {
	a.Log.Printf("Test de restauration : %s", b.Name)
	r := a.RestoreTest(b)
	if raw, err := json.Marshal(r); err == nil {
		_ = a.S.Set("restauration_derniere", string(raw))
	}
	// État affiché à jour tout de suite (sans attendre le prochain contrôle des sauvegardes)
	a.set(func(s *Status) { rr := r; s.Backups.Restore = &rr; s.Backups.RestoreBusy = false })
	_ = a.S.AddJournal("test de restauration", "agent", fmt.Sprintf("%s : %s", b.Name, map[bool]string{true: "réussi", false: "échec — " + r.Error}[r.OK]), a.Now())
	if r.OK {
		a.Log.Printf("Test de restauration réussi : %d tables, %v (%.0f s)", r.Tables, r.Counts, r.Duration)
		a.clear("restauration_echec", "", "test réussi avec "+b.Name)
		return
	}
	a.Log.Printf("Test de restauration en échec : %s", r.Error)
	a.raise(store.Incident{Code: "restauration_echec", Key: "", Severity: store.Critical, Title: "Le test de restauration a échoué",
		Problem:      fmt.Sprintf("La sauvegarde du %s n'a pas pu être restaurée dans la base de test : %s.", b.Date.Format("02/01/2006 à 15:04"), r.Error),
		Consequences: "Si cette sauvegarde était nécessaire après une panne, la restauration pourrait échouer.",
		Recommendations: []string{"Faites une nouvelle sauvegarde dans POS Marwa, puis relancez le test (MARWA GUARDIAN → Sauvegardes).",
			"Si l'échec se répète, conservez la sauvegarde et contactez l'assistance de POS Marwa."},
		Details: map[string]string{"Sauvegarde": b.Name, "Erreur": r.Error}, Confidence: "certain"})
}

// CheckRestoreSchedule : test automatique si le dernier test réussi date de plus de « restore_test_days » jours.
func (a *Agent) CheckRestoreSchedule() {
	days := a.cfg().RestoreTestDays
	if days <= 0 || a.isBusy("restauration") {
		return
	}
	b := a.Status().Backups.LastVerified
	if b == nil {
		return
	}
	if r := a.lastRestore(); r != nil && r.OK && a.Now().Sub(r.At) < time.Duration(days)*24*time.Hour {
		return
	}
	if r := a.lastRestore(); r != nil && !r.OK && r.Backup == b.Name {
		return // déjà essayée sans succès : nouvel essai avec la prochaine sauvegarde, ou à la demande
	}
	_ = a.StartRestoreTest()
}
