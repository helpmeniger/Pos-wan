package main

// Contrôle de démarrage et rapport de diagnostic : POS Marwa vérifie lui-même, sur le PC où il est installé, toute
// la chaîne (base → serveurs PHP → application → fichiers des pages) et dit exactement ce qui ne va pas.

import (
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strings"
	"time"
)

var cssRe = regexp.MustCompile(`href="(/assets/[^"?]+\.css)`)

// Contrôle de démarrage : résultat dans le journal, dans donnees\journaux\diagnostic.txt et, en cas de problème,
// dans un message à l'écran.
func (l *launcher) selfCheck() {
	var lines []string
	ok := true
	add := func(good bool, s string) {
		mark := "OK    "
		if !good {
			mark, ok = "ÉCHEC ", false
		}
		lines = append(lines, mark+s)
		log.Printf("Contrôle : %s%s", mark, s)
	}

	// 1. Base de données, avec le compte de l'application (celui d'app\.env)
	if v, err := l.c.sql("poswan", l.sec.app, "SELECT VERSION()"); err != nil {
		add(false, "base de données : le compte de l'application est refusé ("+strings.TrimSpace(tail(v+" "+err.Error(), 300))+")")
	} else {
		add(true, "base de données : MariaDB "+v+", compte de l'application accepté")
	}

	// 1 bis. Aucun secret en clair dans app\.env
	leak := []string{}
	for _, k := range envSecrets {
		if envFileValue(filepath.Join(l.c.app, ".env"), k) != "" {
			leak = append(leak, k)
		}
	}
	if len(leak) == 0 {
		add(true, "secrets : clé et accès à la base chiffrés dans donnees\\secret\\bd.dat (aucun dans app\\.env)")
	} else {
		add(false, "secrets : encore présents dans app\\.env ("+strings.Join(leak, ", ")+")")
	}

	// 2. Serveurs PHP
	n := 0
	for _, w := range l.workers {
		if w.alive.Load() {
			n++
		}
	}
	add(n == len(l.workers), fmt.Sprintf("serveurs PHP : %d sur %d en marche", n, len(l.workers)))

	// 3. Application, par le même chemin que la fenêtre (répartiteur → PHP → application)
	var chain []string
	cl := &http.Client{Timeout: 90 * time.Second, CheckRedirect: func(req *http.Request, via []*http.Request) error {
		chain = append(chain, req.URL.String())
		if h := req.URL.Hostname(); h != "127.0.0.1" {
			return fmt.Errorf("redirection vers une autre adresse (%s) : vérifiez APP_BASE_URL dans app\\.env", req.URL)
		}
		if len(via) >= 8 {
			return errors.New("trop de redirections")
		}
		return nil
	}}
	t := time.Now()
	resp, err := cl.Get(l.appURL())
	page := ""
	if err != nil {
		add(false, "application : "+err.Error())
	} else {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
		resp.Body.Close()
		page = string(b)
		via := ""
		if len(chain) > 0 {
			via = " via " + strings.Join(chain, " → ")
		}
		good := resp.StatusCode == http.StatusOK && strings.Contains(strings.ToLower(page), "</html>")
		msg := fmt.Sprintf("application : %s%s → %d, %d octets, %d ms", l.appURL(), via, resp.StatusCode, len(b), time.Since(t).Milliseconds())
		if !good {
			msg += " — début de la réponse : " + oneLine(page, 400)
		}
		add(good, msg)
	}

	// 4. Fichier de style de la page (fichiers publics servis par le routeur)
	if m := cssRe.FindStringSubmatch(page); m != nil {
		if r, err := cl.Get(strings.TrimSuffix(l.appURL(), "/") + m[1]); err != nil {
			add(false, "fichiers des pages : "+m[1]+" : "+err.Error())
		} else {
			io.Copy(io.Discard, r.Body)
			r.Body.Close()
			add(r.StatusCode == http.StatusOK, fmt.Sprintf("fichiers des pages : %s → %d", m[1], r.StatusCode))
		}
	}

	if ok {
		lines = append(lines, "Tout fonctionne : si la fenêtre affiche une erreur, notez l'adresse et le message affichés.")
	}
	l.mu.Lock()
	l.check = strings.Join(lines, "\n")
	l.mu.Unlock()
	rep := filepath.Join(l.c.logs, "diagnostic.txt")
	_ = os.WriteFile(rep, []byte(l.report()), 0o600)
	if !ok {
		var bad []string
		for _, s := range lines {
			if strings.HasPrefix(s, "ÉCHEC") {
				bad = append(bad, "• "+strings.TrimSpace(strings.TrimPrefix(s, "ÉCHEC")))
			}
		}
		alert("POS Marwa a démarré, mais son contrôle a trouvé un problème :\n\n" + strings.Join(bad, "\n") +
			"\n\nRapport complet : " + rep + "\n(ou dans la fenêtre : " + l.appURL() + "__bureau/diagnostic)")
	}
}

// Rapport de diagnostic (page /__bureau/diagnostic, réservée à ce PC, et diagnostic.txt). Jamais de mot de passe ;
// les journaux peuvent contenir des noms de clients ou d'utilisateurs : relisez-le avant de le transmettre.
func (l *launcher) report() string {
	var b strings.Builder
	c := l.c
	fmt.Fprintf(&b, "POS Marwa Bureau %s — rapport du %s\n", version, time.Now().Format("02/01/2006 15:04:05"))
	b.WriteString("(Peut contenir des noms de clients ou d'utilisateurs, loi n° 2017-28 : relisez-le avant de le transmettre.)\n\n")
	fmt.Fprintf(&b, "Dossier       : %s\nSystème       : %s/%s\n", c.root, runtime.GOOS, runtime.GOARCH)
	host := "127.0.0.1"
	if c.reseau {
		host = "0.0.0.0 (réseau local)"
	}
	fmt.Fprintf(&b, "Répartiteur   : %s:%d — adresse de la fenêtre %s\n", host, c.portWeb, l.appURL())
	br := l.browser
	if br == "" {
		br = "(aucune fenêtre ouverte par ce démarrage, ou navigateur par défaut)"
	}
	fmt.Fprintf(&b, "Navigateur    : %s\nBase          : 127.0.0.1:%d\n", br, c.portBD)
	fmt.Fprintf(&b, "Prêt          : %v — dernier signe de vie d'une fenêtre il y a %d s\n", l.ready.Load(),
		int((time.Since(l.t0)-time.Duration(l.lastBeat.Load()))/time.Second))
	b.WriteString("Serveurs PHP  :")
	for _, w := range l.workers {
		st := "arrêté"
		if w.alive.Load() {
			st = "en marche"
		}
		fmt.Fprintf(&b, " %d %s (%d en cours) ;", w.port, st, w.busy.Load())
	}
	b.WriteString("\n")
	for _, f := range []string{filepath.Join(c.app, ".env"), filepath.Join(c.app, "storage", "installed.lock"), filepath.Join(c.app, "routeur.php"),
		filepath.Join(c.app, "public", "index.php"), c.php, c.phpIni} {
		st := "présent"
		if _, err := os.Stat(f); err != nil {
			st = "ABSENT"
		}
		fmt.Fprintf(&b, "Fichier       : %s — %s\n", f, st)
	}
	l.mu.Lock()
	check, recent := l.check, append([]string(nil), l.recent...)
	l.mu.Unlock()
	if check == "" {
		check = "(en cours)"
	}
	fmt.Fprintf(&b, "\n== Contrôle de démarrage\n%s\n", check)
	fmt.Fprintf(&b, "\n== Dernières requêtes (%d depuis le démarrage)\n%s\n", l.nReq.Load(), strings.Join(recent, "\n"))
	section := func(title, path string, n int64) {
		fmt.Fprintf(&b, "\n== %s (%s, fin)\n%s\n", title, path, fileTail(path, n))
	}
	section("Lanceur", filepath.Join(c.logs, "lanceur.log"), 4000)
	section("Plantages du lanceur", filepath.Join(c.logs, "plantage.log"), 4000)
	section("PHP", filepath.Join(c.logs, "php.log"), 6000)
	section("Application", latestLog(filepath.Join(c.app, "storage", "logs")), 6000)
	section("MariaDB", filepath.Join(c.logs, "mariadb.err"), 3000)
	return b.String()
}

func fileTail(path string, n int64) string {
	if path == "" {
		return "(aucun)"
	}
	f, err := os.Open(path)
	if err != nil {
		return "(absent)"
	}
	defer f.Close()
	if st, err := f.Stat(); err == nil && st.Size() > n {
		f.Seek(-n, io.SeekEnd)
	}
	b, _ := io.ReadAll(f)
	if len(b) == 0 {
		return "(vide)"
	}
	return string(b)
}

func latestLog(dir string) string {
	m, _ := filepath.Glob(filepath.Join(dir, "*.log"))
	sort.Slice(m, func(i, j int) bool {
		a, _ := os.Stat(m[i])
		b, _ := os.Stat(m[j])
		return a != nil && b != nil && a.ModTime().After(b.ModTime())
	})
	if len(m) == 0 {
		return ""
	}
	return m[0]
}

func oneLine(s string, n int) string {
	s = strings.Join(strings.Fields(s), " ")
	if r := []rune(s); len(r) > n {
		s = string(r[:n]) + "…"
	}
	return s
}
