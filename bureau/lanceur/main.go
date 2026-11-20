// POS Marwa Bureau — lanceur « POS Marwa.exe ».
//
// Tout vit dans le dossier du logiciel (aucune installation de serveur ailleurs) :
//
//	POS Marwa.exe        ce programme
//	php\                 PHP portable (php.exe, php.ini)
//	mariadb\             MariaDB portable (serveur de base de données)
//	app\                 POS Marwa (code, storage\, public\uploads\)
//	donnees\             base de données, journaux, profil de la fenêtre, secrets
//	pos-marwa.ini        réglages facultatifs (ports, réseau, impression directe)
//
// Au démarrage : MariaDB (port local, écoute 127.0.0.1 seulement) → plusieurs serveurs PHP (127.0.0.1) → un
// répartiteur qui reçoit les requêtes de la fenêtre et les confie au serveur PHP le moins occupé → fenêtre
// « application » (Microsoft Edge ou Google Chrome en mode application, profil propre au logiciel).
// À la fermeture de la fenêtre : arrêt propre des serveurs PHP puis de MariaDB.
//
// Premier démarrage : base de données créée, mot de passe administrateur MariaDB et mot de passe de l'application
// tirés au hasard (jamais de mot de passe par défaut), fichier app\.env écrit ; POS Marwa affiche ensuite son
// assistant d'installation (nom de l'entreprise, compte administrateur).
package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"runtime/debug"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

// Version de POS Marwa, fixée à la construction (-ldflags "-X main.version=…")
var version = "dev"

// ---------------------------------------------------------------------------------------------------------------
// Réglages

type config struct {
	root, app, data, logs, php, phpIni, mysqld, installDB, client, admin string
	portWeb, portBD, workers                                             int
	reseau, kiosquePrint, fenetre                                        bool
	navigateur                                                           string
}

func exeExt() string {
	if runtime.GOOS == "windows" {
		return ".exe"
	}
	return ""
}

// Chemins par défaut (dossier du programme) ; variables POS_BUREAU_* pour les essais sous Linux.
func loadConfig(root string) *config {
	c := &config{root: root, portWeb: 8740, portBD: 3407, workers: 8, navigateur: "auto", fenetre: true}
	c.app = filepath.Join(root, "app")
	c.data = filepath.Join(root, "donnees")
	c.logs = filepath.Join(c.data, "journaux")
	c.php = env("POS_BUREAU_PHP", filepath.Join(root, "php", "php"+exeExt()))
	c.phpIni = env("POS_BUREAU_PHP_INI", filepath.Join(root, "php", "php.ini"))
	mb := filepath.Join(root, "mariadb", "bin")
	c.mysqld = env("POS_BUREAU_MARIADBD", filepath.Join(mb, "mariadbd"+exeExt()))
	c.installDB = env("POS_BUREAU_INSTALLDB", filepath.Join(mb, "mariadb-install-db"+exeExt()))
	c.client = env("POS_BUREAU_CLIENT", filepath.Join(mb, "mariadb"+exeExt()))
	c.admin = env("POS_BUREAU_ADMIN", filepath.Join(mb, "mariadb-admin"+exeExt()))
	if f, err := os.Open(filepath.Join(root, "pos-marwa.ini")); err == nil {
		sc := bufio.NewScanner(f)
		for sc.Scan() {
			line := strings.TrimSpace(sc.Text())
			if line == "" || strings.HasPrefix(line, ";") || strings.HasPrefix(line, "#") || strings.HasPrefix(line, "[") {
				continue
			}
			k, v, ok := strings.Cut(line, "=")
			if !ok {
				continue
			}
			k, v = strings.ToUpper(strings.TrimSpace(k)), strings.TrimSpace(v)
			n, _ := strconv.Atoi(v)
			switch k {
			case "PORT_WEB":
				if n > 1023 && n < 65536 {
					c.portWeb = n
				}
			case "PORT_BD":
				if n > 1023 && n < 65536 {
					c.portBD = n
				}
			case "SERVEURS_PHP":
				if n >= 2 && n <= 32 {
					c.workers = n
				}
			case "RESEAU":
				c.reseau = v == "1" || strings.EqualFold(v, "oui")
			case "IMPRESSION_DIRECTE":
				c.kiosquePrint = v == "1" || strings.EqualFold(v, "oui")
			case "NAVIGATEUR":
				c.navigateur = strings.ToLower(v)
			}
		}
		f.Close()
	}
	return c
}

func env(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}

func randomHex(n int) string {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b)
}

// ---------------------------------------------------------------------------------------------------------------
// MariaDB

type secrets struct{ root, app string }

func (c *config) secretFile() string { return filepath.Join(c.data, "secret", "bd.ini") }

func (c *config) loadSecrets() (secrets, bool) {
	b, err := os.ReadFile(c.secretFile())
	if err != nil {
		return secrets{}, false
	}
	var s secrets
	for _, l := range strings.Split(string(b), "\n") {
		k, v, _ := strings.Cut(strings.TrimSpace(l), "=")
		switch k {
		case "root":
			s.root = v
		case "app":
			s.app = v
		}
	}
	return s, s.root != "" && s.app != ""
}

func (c *config) saveSecrets(s secrets) error {
	if err := os.MkdirAll(filepath.Dir(c.secretFile()), 0o700); err != nil {
		return err
	}
	return os.WriteFile(c.secretFile(), []byte("; Mots de passe de la base de données POS Marwa (générés au premier démarrage).\n; Ne pas modifier ni communiquer.\nroot="+s.root+"\napp="+s.app+"\n"), 0o600)
}

func slash(p string) string { return strings.ReplaceAll(p, `\`, "/") }

func (c *config) myIni() string { return filepath.Join(c.data, "mariadb.ini") }

func (c *config) writeMyIni() error {
	var b strings.Builder
	b.WriteString("# POS Marwa Bureau — réglages du serveur MariaDB (réécrits à chaque démarrage)\n[mysqld]\n")
	fmt.Fprintf(&b, "basedir=%s\n", slash(filepath.Dir(filepath.Dir(c.mysqld))))
	fmt.Fprintf(&b, "datadir=%s\n", slash(filepath.Join(c.data, "mysql")))
	fmt.Fprintf(&b, "port=%d\n", c.portBD)
	b.WriteString("bind-address=127.0.0.1\n") // écoute locale seulement : la base n'est jamais joignable depuis le réseau
	b.WriteString("character-set-server=utf8mb4\ncollation-server=utf8mb4_unicode_ci\n")
	b.WriteString("innodb_buffer_pool_size=256M\ninnodb_log_file_size=64M\nmax_allowed_packet=256M\nmax_connections=100\n")
	fmt.Fprintf(&b, "log-error=%s\n", slash(filepath.Join(c.logs, "mariadb.err")))
	if runtime.GOOS != "windows" && os.Geteuid() == 0 {
		b.WriteString("user=root\n") // essais sous Linux en tant que root uniquement
	}
	if runtime.GOOS != "windows" {
		// Linux (essais) : chemin court pour le fichier de connexion locale (107 caractères au plus)
		fmt.Fprintf(&b, "socket=%s\npid-file=%s\n", slash(filepath.Join(os.TempDir(), fmt.Sprintf("posmarwa-%d.sock", c.portBD))), slash(filepath.Join(c.data, "mariadb.pid")))
	}
	fmt.Fprintf(&b, "[client]\nport=%d\nhost=127.0.0.1\n", c.portBD)
	return os.WriteFile(c.myIni(), []byte(b.String()), 0o600)
}

// Fichier d'identification temporaire (le mot de passe ne passe jamais sur la ligne de commande)
func (c *config) credFile(user, pass string) (string, func(), error) {
	f, err := os.CreateTemp(filepath.Join(c.data, "secret"), "client-*.ini")
	if err != nil {
		return "", func() {}, err
	}
	fmt.Fprintf(f, "[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\nport=%d\nprotocol=TCP\n", user, pass, c.portBD)
	f.Close()
	return f.Name(), func() { os.Remove(f.Name()) }, nil
}

func (c *config) sql(user, pass, query string) (string, error) {
	cf, done, err := c.credFile(user, pass)
	if err != nil {
		return "", err
	}
	defer done()
	cmd := exec.Command(c.client, "--defaults-extra-file="+cf, "-N", "-B", "-e", query)
	hide(cmd)
	out, err := cmd.CombinedOutput()
	return strings.TrimSpace(string(out)), err
}

func portOpen(port int) bool {
	conn, err := net.DialTimeout("tcp", fmt.Sprintf("127.0.0.1:%d", port), 400*time.Millisecond)
	if err == nil {
		conn.Close()
		return true
	}
	return false
}

func freePort(port int) bool {
	l, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", port))
	if err != nil {
		return false
	}
	l.Close()
	return true
}

type launcher struct {
	c        *config
	sec      secrets
	mysqld   *exec.Cmd
	ownDB    bool
	workers  []*worker
	jeton    string
	t0       time.Time    // départ (horloge monotone : le temps de mise en veille du PC n'est pas compté)
	lastBeat atomic.Int64 // dernier signe de vie d'une fenêtre (requête ou battement), en ns depuis t0
	ready    atomic.Bool
	stopping atomic.Bool
	stopOnce sync.Once
	done     chan struct{}
	srv      *http.Server
	browser  string
	nReq     atomic.Int64
	mu       sync.Mutex
	recent   []string // dernières requêtes (diagnostic)
	check    string   // résultat du contrôle de démarrage
}

func (l *launcher) startDB() error {
	c := l.c
	// La base n'est considérée comme créée qu'une fois tout terminé (témoin « posmarwa-prete » DANS le dossier de la
	// base). Un premier démarrage interrompu (coupure de courant, erreur) est refait ; le dossier inachevé est mis de
	// côté sous un autre nom, jamais supprimé.
	dir := filepath.Join(c.data, "mysql")
	ready := filepath.Join(dir, "posmarwa-prete")
	first := false
	if _, err := os.Stat(ready); err != nil {
		first = true
		if _, err := os.Stat(dir); err == nil {
			if portOpen(c.portBD) {
				return fmt.Errorf("une création de base inachevée est encore en cours d'utilisation (port %d) : redémarrez le PC puis relancez POS Marwa", c.portBD)
			}
			aside := filepath.Join(c.data, "mysql-inacheve-"+time.Now().Format("20060102-150405"))
			log.Printf("Création de base inachevée trouvée : mise de côté dans %s, puis refaite", aside)
			if err := os.Rename(dir, aside); err != nil {
				return fmt.Errorf("impossible de mettre de côté la base inachevée : %w", err)
			}
		}
	}
	s, ok := c.loadSecrets()
	if first {
		s = secrets{root: randomHex(16), app: randomHex(16)}
		if err := c.saveSecrets(s); err != nil {
			return fmt.Errorf("impossible d'écrire les secrets de la base : %w", err)
		}
	} else if !ok {
		return errors.New("la base de données existe mais son fichier de mots de passe (donnees\\secret\\bd.ini) est absent : restaurez-le depuis votre sauvegarde ou contactez l'assistance")
	}
	l.sec = s
	if err := c.writeMyIni(); err != nil {
		return err
	}
	// Un serveur MariaDB de POS Marwa tourne déjà (lanceur arrêté brutalement) : on le réutilise
	if !first && portOpen(c.portBD) {
		if _, err := c.sql("root", s.root, "SELECT 1"); err == nil {
			log.Printf("MariaDB déjà démarrée sur le port %d : réutilisée", c.portBD)
			return nil
		}
		return fmt.Errorf("le port %d de la base de données est occupé par un autre programme : changez PORT_BD dans pos-marwa.ini", c.portBD)
	}
	if first {
		log.Printf("Premier démarrage : création de la base de données dans %s", dir)
		var cmd *exec.Cmd
		if runtime.GOOS == "windows" {
			cmd = exec.Command(c.installDB, "--datadir="+dir, "--password="+s.root, fmt.Sprintf("--port=%d", c.portBD))
		} else {
			cmd = exec.Command(c.installDB, "--no-defaults", "--datadir="+dir, "--auth-root-authentication-method=normal", "--skip-test-db",
				"--basedir="+env("POS_BUREAU_MARIADB_BASEDIR", "/usr"))
		}
		hide(cmd)
		if out, err := cmd.CombinedOutput(); err != nil {
			return fmt.Errorf("création de la base impossible : %v\n%s", err, tail(string(out), 1500))
		}
	}
	cmd := exec.Command(c.mysqld, "--defaults-file="+c.myIni())
	hide(cmd)
	errLog, _ := os.OpenFile(filepath.Join(c.logs, "mariadb-console.log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
	cmd.Stdout, cmd.Stderr = errLog, errLog
	if err := cmd.Start(); err != nil {
		return fmt.Errorf("démarrage de MariaDB impossible : %w", err)
	}
	l.mysqld, l.ownDB = cmd, true
	exited := make(chan error, 1)
	go func() { exited <- cmd.Wait() }()
	deadline := time.Now().Add(90 * time.Second)
	for !portOpen(c.portBD) {
		select {
		case err := <-exited:
			return fmt.Errorf("MariaDB s'est arrêtée au démarrage (%v) : voir donnees\\journaux\\mariadb.err", err)
		case <-time.After(300 * time.Millisecond):
		}
		if time.Now().After(deadline) {
			return errors.New("MariaDB ne répond pas après 90 secondes : voir donnees\\journaux\\mariadb.err")
		}
	}
	go func() {
		err := <-exited
		if !l.stopping.Load() {
			log.Printf("MariaDB s'est arrêtée de façon inattendue : %v", err)
		}
	}()
	if first {
		rootPass := s.root
		if runtime.GOOS != "windows" {
			rootPass = "" // Linux (essais) : mot de passe root posé maintenant
			if out, err := c.sql("root", "", "ALTER USER 'root'@'localhost' IDENTIFIED BY '"+s.root+"'"); err != nil {
				return fmt.Errorf("mot de passe administrateur de la base : %v %s", err, out)
			}
			rootPass = s.root
		}
		q := "CREATE DATABASE IF NOT EXISTS poswan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" +
			"CREATE USER IF NOT EXISTS 'poswan'@'127.0.0.1' IDENTIFIED BY '" + s.app + "';" +
			"CREATE USER IF NOT EXISTS 'poswan'@'localhost' IDENTIFIED BY '" + s.app + "';" +
			"GRANT ALL PRIVILEGES ON poswan.* TO 'poswan'@'127.0.0.1';" +
			"GRANT ALL PRIVILEGES ON poswan.* TO 'poswan'@'localhost';" +
			"DELETE FROM mysql.global_priv WHERE User = '';" + // aucun compte anonyme
			"FLUSH PRIVILEGES;"
		if out, err := c.sql("root", rootPass, q); err != nil {
			return fmt.Errorf("création de la base « poswan » impossible : %v %s", err, out)
		}
		if err := os.WriteFile(ready, []byte(time.Now().Format(time.RFC3339)+"\n"), 0o600); err != nil {
			return err
		}
		log.Printf("Base « poswan » et compte de l'application créés")
	}
	return nil
}

func (l *launcher) stopDB() {
	if !l.ownDB || l.mysqld == nil || l.mysqld.Process == nil {
		return
	}
	cf, done, err := l.c.credFile("root", l.sec.root)
	if err == nil {
		cmd := exec.Command(l.c.admin, "--defaults-extra-file="+cf, "shutdown")
		hide(cmd)
		_ = cmd.Run()
		done()
	}
	for i := 0; i < 60 && portOpen(l.c.portBD); i++ {
		time.Sleep(500 * time.Millisecond)
	}
	if portOpen(l.c.portBD) {
		log.Printf("MariaDB ne s'arrête pas : arrêt forcé")
		_ = l.mysqld.Process.Kill()
	}
}

// app\.env : créé au premier démarrage ; le port de la base est tenu à jour s'il change dans pos-marwa.ini
func (l *launcher) ensureEnv() error {
	path := filepath.Join(l.c.app, ".env")
	b, err := os.ReadFile(path)
	if err != nil {
		content := "# POS Marwa Bureau — configuration générée au premier démarrage (ne pas communiquer).\n" +
			"APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=" + randomHex(32) + "\nAPP_BASE_URL=\nAPP_TIMEZONE=Africa/Niamey\n\n" +
			"DB_HOST=127.0.0.1\nDB_PORT=" + strconv.Itoa(l.c.portBD) + "\nDB_DATABASE=poswan\nDB_USERNAME=poswan\nDB_PASSWORD=\"" + l.sec.app + "\"\n\n" +
			"SESSION_IDLE_MINUTES=30\nSESSION_MAX_HOURS=12\nSESSION_SECURE=false\nSAAS_MODE=false\n\n" +
			"# Édition Bureau : menu de gestion en tuiles (modifiable dans Configuration → Paramètres)\nMENU_STYLE=tuiles\nPOS_EDITION=bureau\n"
		return os.WriteFile(path, []byte(content), 0o600)
	}
	// Réglages de la base toujours réalignés sur donnees\secret\bd.ini et pos-marwa.ini (une base recréée a un
	// nouveau mot de passe : l'ancien app\.env empêcherait l'application de s'y connecter)
	want := map[string]string{"DB_HOST": "127.0.0.1", "DB_PORT": strconv.Itoa(l.c.portBD), "DB_DATABASE": "poswan",
		"DB_USERNAME": "poswan", "DB_PASSWORD": "\"" + l.sec.app + "\""}
	lines := strings.Split(strings.ReplaceAll(string(b), "\r\n", "\n"), "\n")
	changed := false
	seen := map[string]bool{}
	for i, ln := range lines {
		k, v, ok := strings.Cut(strings.TrimSpace(ln), "=")
		if w, known := want[k]; ok && known {
			seen[k] = true
			if v != w {
				lines[i], changed = k+"="+w, true
				log.Printf("app\\.env : %s réaligné sur la configuration de la base", k)
			}
		}
	}
	for _, k := range []string{"DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"} {
		if !seen[k] {
			lines, changed = append(lines, k+"="+want[k]), true
			log.Printf("app\\.env : %s ajouté", k)
		}
	}
	if changed {
		return os.WriteFile(path, []byte(strings.Join(lines, "\n")), 0o600)
	}
	return nil
}

// ---------------------------------------------------------------------------------------------------------------
// Serveurs PHP et répartiteur

type worker struct {
	port  int
	busy  atomic.Int64
	alive atomic.Bool
	proxy *httputil.ReverseProxy
	mu    sync.Mutex
	cmd   *exec.Cmd
}

func (l *launcher) startWorker(w *worker) error {
	c := l.c
	// extension_dir en chemin complet : PHP chercherait sinon « ext » dans le dossier courant (app\), pas à côté de php.exe
	args := []string{"-c", c.phpIni, "-d", "extension_dir=" + filepath.Join(filepath.Dir(c.phpIni), "ext"), "-d", "error_log=" + filepath.Join(c.logs, "php.log"),
		"-S", fmt.Sprintf("127.0.0.1:%d", w.port), "-t", filepath.Join(c.app, "public"), filepath.Join(c.app, "routeur.php")}
	cmd := exec.Command(c.php, args...)
	cmd.Dir = c.app
	cmd.Env = append(os.Environ(), "POS_BUREAU_JETON="+l.jeton, "POS_BUREAU=1", "PHP_CLI_SERVER_WORKERS=1")
	if runtime.GOOS == "windows" {
		cmd.Env = append(cmd.Env, "PHP_INI_SCAN_DIR=") // seul php\php.ini compte (pas celui d'un autre PHP installé sur le PC)
		// OPcache sous Windows : plusieurs php.exe partagent sa mémoire ; s'il ne peut s'y rattacher, il se replie sur
		// ce cache disque au lieu d'échouer
		oc := filepath.Join(c.data, "cache", "opcache")
		if os.MkdirAll(oc, 0o700) == nil {
			args = append([]string{"-d", "opcache.file_cache=" + oc, "-d", "opcache.file_cache_fallback=1"}, args...)
			cmd.Args = append([]string{c.php}, args...)
		}
	}
	cmd.Stdout, cmd.Stderr = io.Discard, io.Discard // journal d'accès inutile ; les erreurs vont dans php.log
	hide(cmd)
	if err := cmd.Start(); err != nil {
		return err
	}
	attachJob(cmd)
	w.mu.Lock()
	w.cmd = cmd
	w.mu.Unlock()
	go func() {
		err := cmd.Wait()
		w.alive.Store(false)
		if l.stopping.Load() {
			return
		}
		log.Printf("Serveur PHP %d arrêté (%v) : redémarrage", w.port, err)
		time.Sleep(time.Second)
		if !l.stopping.Load() {
			if err := l.startWorker(w); err != nil {
				log.Printf("Redémarrage du serveur PHP %d impossible : %v", w.port, err)
			}
		}
	}()
	go func() {
		for i := 0; i < 150 && !l.stopping.Load(); i++ {
			if portOpen(w.port) {
				w.alive.Store(true)
				return
			}
			time.Sleep(200 * time.Millisecond)
		}
	}()
	return nil
}

func (l *launcher) startPHP() error {
	if _, err := os.Stat(l.c.php); err != nil {
		return fmt.Errorf("PHP introuvable (%s) : réinstallez POS Marwa", l.c.php)
	}
	port := 18800
	for i := 0; i < l.c.workers; i++ {
		for !freePort(port) {
			port++
		}
		w := &worker{port: port}
		target, _ := url.Parse(fmt.Sprintf("http://127.0.0.1:%d", port))
		rp := httputil.NewSingleHostReverseProxy(target)
		base := rp.Director
		rp.Director = func(r *http.Request) {
			ip, _, _ := net.SplitHostPort(r.RemoteAddr)
			base(r) // l'en-tête Host d'origine est conservé (adresses des pages)
			r.Header.Del("X-Forwarded-For")
			r.Header.Set("X-Bureau-Ip", ip)
			r.Header.Set("X-Bureau-Jeton", l.jeton)
		}
		rp.FlushInterval = -1 // réponses en continu (assistant MARWA AI, flux)
		rp.ModifyResponse = injectBeat
		rp.ErrorHandler = func(rw http.ResponseWriter, r *http.Request, err error) {
			log.Printf("Requête %s %s : %v", r.Method, r.URL.Path, err)
			rw.Header().Set("Content-Type", "text/html; charset=utf-8")
			rw.Header().Set("Retry-After", "2")
			rw.WriteHeader(http.StatusBadGateway)
			io.WriteString(rw, pageAttente("Un instant : le serveur redémarre…"))
		}
		w.proxy = rp
		if err := l.startWorker(w); err != nil {
			return fmt.Errorf("démarrage de PHP impossible : %w", err)
		}
		l.workers = append(l.workers, w)
		port++
	}
	deadline := time.Now().Add(30 * time.Second)
	for time.Now().Before(deadline) {
		n := 0
		for _, w := range l.workers {
			if w.alive.Load() {
				n++
			}
		}
		if n == len(l.workers) {
			return nil
		}
		time.Sleep(200 * time.Millisecond)
	}
	return errors.New("les serveurs PHP ne répondent pas : voir donnees\\journaux\\php.log")
}

func (l *launcher) stopPHP() {
	for _, w := range l.workers {
		w.mu.Lock()
		if w.cmd != nil && w.cmd.Process != nil {
			_ = w.cmd.Process.Kill()
		}
		w.mu.Unlock()
	}
}

// Serveur PHP le moins occupé (chaque serveur PHP traite une requête à la fois)
func (l *launcher) pick() *worker {
	var best *worker
	for _, w := range l.workers {
		if !w.alive.Load() {
			continue
		}
		if best == nil || w.busy.Load() < best.busy.Load() {
			best = w
		}
	}
	return best
}

func pageAttente(msg string) string {
	return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta http-equiv="refresh" content="1"><title>POS Marwa</title>
<style>body{margin:0;height:100vh;display:flex;align-items:center;justify-content:center;flex-direction:column;background:#14213d;color:#fff;font-family:Segoe UI,Arial,sans-serif}
.r{width:46px;height:46px;border:5px solid rgba(255,255,255,.25);border-top-color:#f39c12;border-radius:50%;animation:t 1s linear infinite;margin-bottom:18px}@keyframes t{to{transform:rotate(360deg)}}
b{color:#f39c12}</style></head><body><div class="r"></div><div style="font-size:1.6rem">POS <b>Marwa</b></div><p>` + msg + `</p></body></html>`
}

func (l *launcher) ServeHTTP(rw http.ResponseWriter, r *http.Request) {
	if !strings.HasPrefix(r.URL.Path, "/__bureau/") {
		l.beat()
	}
	switch r.URL.Path {
	case "/__bureau/battement":
		l.beat()
		rw.Header().Set("Cache-Control", "no-store")
		rw.WriteHeader(http.StatusNoContent)
		return
	case "/__bureau/battement.js":
		rw.Header().Set("Content-Type", "application/javascript; charset=utf-8")
		rw.Header().Set("Cache-Control", "no-store")
		io.WriteString(rw, beatJS)
		return
	case "/__bureau/sante":
		rw.Header().Set("Content-Type", "text/plain; charset=utf-8")
		fmt.Fprintf(rw, "POS Marwa Bureau %s pret=%v inactif=%ds", version, l.ready.Load(), int((time.Since(l.t0)-time.Duration(l.lastBeat.Load()))/time.Second))
		return
	case "/__bureau/diagnostic":
		host, _, _ := net.SplitHostPort(r.RemoteAddr)
		if host != "127.0.0.1" && host != "::1" { // rapport réservé à ce PC
			http.Error(rw, "refusé", http.StatusForbidden)
			return
		}
		rw.Header().Set("Content-Type", "text/plain; charset=utf-8")
		rw.Header().Set("Cache-Control", "no-store")
		io.WriteString(rw, l.report())
		return
	case "/__bureau/arret":
		// Arrêt demandé par « POS Marwa.exe --arreter », seulement depuis ce PC et avec le jeton du fichier local
		host, _, _ := net.SplitHostPort(r.RemoteAddr)
		tok, _ := os.ReadFile(filepath.Join(l.c.data, "secret", "arret.jeton"))
		if r.Method != http.MethodPost || (host != "127.0.0.1" && host != "::1") || len(tok) == 0 || r.Header.Get("X-Jeton") != strings.TrimSpace(string(tok)) {
			http.Error(rw, "refusé", http.StatusForbidden)
			return
		}
		io.WriteString(rw, "arrêt")
		go l.stop("demande d'arrêt")
		return
	}
	if !l.ready.Load() {
		rw.Header().Set("Content-Type", "text/html; charset=utf-8")
		rw.Header().Set("Cache-Control", "no-store")
		rw.WriteHeader(http.StatusServiceUnavailable)
		io.WriteString(rw, pageAttente("Démarrage de POS Marwa…"))
		return
	}
	w := l.pick()
	if w == nil {
		rw.Header().Set("Content-Type", "text/html; charset=utf-8")
		rw.WriteHeader(http.StatusServiceUnavailable)
		io.WriteString(rw, pageAttente("Un instant : le serveur redémarre…"))
		return
	}
	l.beat()
	w.busy.Add(1)
	defer w.busy.Add(-1)
	sr := &statusRW{ResponseWriter: rw}
	t := time.Now()
	w.proxy.ServeHTTP(sr, r)
	l.note(r, sr.code, w.port, time.Since(t))
}

// Code de réponse transmis (journal des requêtes) ; Flush conservé pour les réponses en continu
type statusRW struct {
	http.ResponseWriter
	code int
}

func (s *statusRW) WriteHeader(c int) {
	if s.code == 0 {
		s.code = c
	}
	s.ResponseWriter.WriteHeader(c)
}

func (s *statusRW) Write(b []byte) (int, error) {
	if s.code == 0 {
		s.code = http.StatusOK
	}
	return s.ResponseWriter.Write(b)
}

func (s *statusRW) Flush() {
	if f, ok := s.ResponseWriter.(http.Flusher); ok {
		f.Flush()
	}
}

func (s *statusRW) Unwrap() http.ResponseWriter { return s.ResponseWriter }

// Journal : les 40 premières requêtes de chaque démarrage et toute erreur serveur ; les 60 dernières en mémoire
// (page de diagnostic). Le chemin seulement : jamais les paramètres (ils peuvent contenir des données personnelles).
func (l *launcher) note(r *http.Request, code, port int, d time.Duration) {
	ip, _, _ := net.SplitHostPort(r.RemoteAddr)
	line := fmt.Sprintf("%s %s %s → %d (PHP %d, %d ms)", ip, r.Method, r.URL.Path, code, port, d.Milliseconds())
	n := l.nReq.Add(1)
	if n <= 40 || code >= 500 || code == 0 {
		log.Printf("Requête %s", line)
	}
	l.mu.Lock()
	l.recent = append(l.recent, time.Now().Format("15:04:05 ")+line)
	if len(l.recent) > 60 {
		l.recent = l.recent[len(l.recent)-60:]
	}
	l.mu.Unlock()
}

// ---------------------------------------------------------------------------------------------------------------
// Fenêtre

func (l *launcher) appURL() string { return fmt.Sprintf("http://127.0.0.1:%d/", l.c.portWeb) }

func (l *launcher) profileDir() string { return filepath.Join(l.c.data, "fenetre") }

// Ouvre la fenêtre « application » et retourne une fonction qui attend sa fermeture (nil : impossible de savoir)
func (l *launcher) openWindow() (func(), error) {
	browser := findBrowser(l.c.navigateur)
	if browser == "" {
		if err := openDefault(l.appURL()); err != nil {
			return nil, err
		}
		return nil, nil
	}
	args := []string{"--app=" + l.appURL(), "--user-data-dir=" + l.profileDir(), "--no-first-run", "--no-default-browser-check",
		"--disable-features=Translate,msEdgeTranslate,msImplicitSignin", "--start-maximized", "--disable-background-mode"}
	if l.c.kiosquePrint {
		args = append(args, "--kiosk-printing") // tickets imprimés directement sur l'imprimante par défaut
	}
	l.browser = browser
	cmd := exec.Command(browser, append(args, extraBrowserArgs()...)...)
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	log.Printf("Fenêtre : %s %s", browser, l.appURL())
	t := time.Now()
	go func() {
		err := cmd.Wait()
		if d := time.Since(t); d < 15*time.Second { // normal pour Edge déjà ouvert (fenêtre confiée à un autre processus)
			log.Printf("Fenêtre : processus du navigateur terminé après %d ms (%v)", d.Milliseconds(), err)
		}
	}()
	// La fermeture n'est pas déduite du processus du navigateur (Edge peut se relancer ou confier la fenêtre à un
	// autre de ses processus) : chaque page de POS Marwa envoie un signe de vie ; sans signe de vie, arrêt (watchdog).
	return func() {}, nil
}

// Script ajouté aux pages : signe de vie toutes les 15 s (une fenêtre réduite en envoie au moins un par minute)
const beatJS = `(function(){var u='/__bureau/battement';function b(){try{fetch(u,{cache:'no-store',keepalive:true}).catch(function(){});}catch(e){}}b();setInterval(b,15000);document.addEventListener('visibilitychange',b);})();`

// Insère <script src="/__bureau/battement.js"> avant </body> dans les pages HTML complètes (pas les fragments,
// ni le JSON, les fichiers ou les flux).
func injectBeat(resp *http.Response) error {
	ct := resp.Header.Get("Content-Type")
	if !strings.HasPrefix(ct, "text/html") || resp.Header.Get("Content-Encoding") != "" || resp.Request.Method != http.MethodGet {
		return nil
	}
	const max = 8 << 20
	body, err := io.ReadAll(io.LimitReader(resp.Body, max+1))
	if err != nil {
		resp.Body.Close()
		return err
	}
	if len(body) > max { // très grande page : transmise telle quelle
		resp.Body = struct {
			io.Reader
			io.Closer
		}{io.MultiReader(bytes.NewReader(body), resp.Body), resp.Body}
		return nil
	}
	resp.Body.Close()
	if i := bytes.LastIndex(bytes.ToLower(body), []byte("</body>")); i >= 0 {
		body = append(body[:i:i], append([]byte(`<script src="/__bureau/battement.js"></script>`), body[i:]...)...)
	}
	resp.Body = io.NopCloser(bytes.NewReader(body))
	resp.ContentLength = int64(len(body))
	resp.Header.Set("Content-Length", strconv.Itoa(len(body)))
	return nil
}

func (l *launcher) beat() { l.lastBeat.Store(int64(time.Since(l.t0))) }

// Arrêt automatique quand plus aucune fenêtre (de ce PC ou d'un autre poste) ne donne signe de vie depuis « idle ».
func (l *launcher) watchdog(idle time.Duration) {
	l.beat()
	for {
		select {
		case <-l.done:
			return
		case <-time.After(10 * time.Second):
		}
		if time.Since(l.t0)-time.Duration(l.lastBeat.Load()) > idle {
			l.stop("plus aucune fenêtre ouverte depuis " + idle.String())
			return
		}
	}
}

// ---------------------------------------------------------------------------------------------------------------
// Démarrage / arrêt

func (l *launcher) stop(why string) {
	l.stopOnce.Do(func() {
		log.Printf("Arrêt (%s)", why)
		l.stopping.Store(true)
		l.ready.Store(false)
		if l.srv != nil {
			ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
			_ = l.srv.Shutdown(ctx)
			cancel()
		}
		l.stopPHP()
		l.stopDB()
		os.Remove(filepath.Join(l.c.data, "secret", "arret.jeton"))
		log.Printf("Arrêt terminé")
		close(l.done)
	})
}

func running(port int) bool {
	cl := http.Client{Timeout: 1500 * time.Millisecond}
	resp, err := cl.Get(fmt.Sprintf("http://127.0.0.1:%d/__bureau/sante", port))
	if err != nil {
		return false
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 200))
	return strings.HasPrefix(string(b), "POS Marwa Bureau")
}

func tail(s string, n int) string {
	if len(s) > n {
		return s[len(s)-n:]
	}
	return s
}

func main() {
	stopFlag := flag.Bool("arreter", false, "arrêter POS Marwa (serveurs compris)")
	noWin := flag.Bool("sans-fenetre", false, "démarrer les serveurs sans ouvrir de fenêtre (poste serveur, essais)")
	rootFlag := flag.String("dossier", "", "dossier du logiciel (par défaut : celui du programme)")
	flag.Parse()

	root := *rootFlag
	if root == "" {
		exe, _ := os.Executable()
		root = filepath.Dir(exe)
	}
	root, _ = filepath.Abs(root)
	c := loadConfig(root)
	if *noWin {
		c.fenetre = false
	}
	for _, d := range []string{c.data, c.logs, filepath.Join(c.data, "secret")} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			fatal("Le dossier " + d + " n'est pas accessible en écriture : " + err.Error() + "\n\nInstallez POS Marwa dans un dossier où votre compte Windows peut écrire (par défaut C:\\POS Marwa).")
		}
	}
	logf, _ := os.OpenFile(filepath.Join(c.logs, "lanceur.log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
	if st, err := logf.Stat(); err == nil && st.Size() > 5<<20 {
		logf.Truncate(0)
	}
	log.SetOutput(io.MultiWriter(logf, os.Stderr))
	log.SetFlags(log.LstdFlags)
	// Un plantage du programme (sans fenêtre de console sous Windows) laisse sa trace dans plantage.log
	if cf, err := os.OpenFile(filepath.Join(c.logs, "plantage.log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644); err == nil {
		_ = debug.SetCrashOutput(cf, debug.CrashOptions{})
		cf.Close()
	}

	if *stopFlag {
		tok, _ := os.ReadFile(filepath.Join(c.data, "secret", "arret.jeton"))
		req, _ := http.NewRequest(http.MethodPost, fmt.Sprintf("http://127.0.0.1:%d/__bureau/arret", c.portWeb), nil)
		req.Header.Set("X-Jeton", strings.TrimSpace(string(tok)))
		if resp, err := (&http.Client{Timeout: 5 * time.Second}).Do(req); err == nil {
			resp.Body.Close()
		}
		return
	}

	// Déjà en marche : on ouvre seulement une fenêtre
	l := &launcher{c: c, jeton: randomHex(24), done: make(chan struct{}), t0: time.Now()}
	if running(c.portWeb) {
		if c.fenetre {
			if _, err := l.openWindow(); err != nil {
				fatal("Impossible d'ouvrir la fenêtre de POS Marwa : " + err.Error())
			}
		}
		return
	}
	log.Printf("=== POS Marwa Bureau %s — dossier %s (%s/%s, réseau=%v)", version, root, runtime.GOOS, runtime.GOARCH, c.reseau)
	listenHost := "127.0.0.1"
	if c.reseau {
		listenHost = "0.0.0.0" // autres postes du réseau local (tablettes, caisses) ; la base reste locale
	}
	ln, err := net.Listen("tcp", fmt.Sprintf("%s:%d", listenHost, c.portWeb))
	if err != nil {
		if running(c.portWeb) { // démarré en même temps par un double-clic
			if c.fenetre {
				l.openWindow()
			}
			return
		}
		fatal(fmt.Sprintf("Le port %d est déjà utilisé par un autre programme.\n\nChangez PORT_WEB dans le fichier pos-marwa.ini du dossier de POS Marwa.", c.portWeb))
	}
	_ = os.WriteFile(filepath.Join(c.data, "secret", "arret.jeton"), []byte(randomHex(24)), 0o600)
	l.srv = &http.Server{Handler: l, ReadHeaderTimeout: 20 * time.Second}
	go func() {
		if err := l.srv.Serve(ln); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Printf("Répartiteur : %v", err)
		}
	}()

	// La fenêtre s'ouvre tout de suite sur la page « Démarrage… », pendant que les serveurs démarrent
	var wait func()
	if c.fenetre {
		if wait, err = l.openWindow(); err != nil {
			log.Printf("Fenêtre : %v", err)
		}
	}
	if err := l.startDB(); err != nil {
		l.stop("erreur base de données")
		fatal("POS Marwa ne peut pas démarrer sa base de données.\n\n" + err.Error())
	}
	if err := l.ensureEnv(); err != nil {
		l.stop("erreur .env")
		fatal("Impossible d'écrire la configuration (app\\.env) : " + err.Error())
	}
	if err := l.startPHP(); err != nil {
		l.stop("erreur PHP")
		fatal("POS Marwa ne peut pas démarrer.\n\n" + err.Error())
	}
	l.ready.Store(true)
	log.Printf("Prêt : %s (%d serveurs PHP, base sur le port %d, réseau=%v)", l.appURL(), len(l.workers), c.portBD, c.reseau)

	go l.selfCheck()
	go waitSignal(func() { l.stop("signal du système") })
	if wait != nil {
		idle := 3 * time.Minute
		if n, err := strconv.Atoi(os.Getenv("POS_BUREAU_INACTIF")); err == nil && n >= 10 { // essais : délai en secondes
			idle = time.Duration(n) * time.Second
		}
		go l.watchdog(idle)
	}
	<-l.done
}
