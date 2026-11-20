package main

// Pont MARWA SECEF CONNECT (Niger, e-SECeF) : démarré, surveillé et arrêté par POS Marwa.exe avec le Node.js livré
// (node\node.exe). Il ne démarre que lorsque POS Marwa a créé son jeton (app\addons\fiscal\pont-secef\jeton.txt,
// écrit par la page « Certification fiscale ») ; un nouveau jeton le redémarre. Il écoute sur 127.0.0.1 seulement.

import (
	"fmt"
	"log"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

const pontPort = 8760

type pontSecef struct {
	mu      sync.Mutex
	cmd     *exec.Cmd
	jeton   string
	echecs  int
	attente time.Time
}

func (l *launcher) pontDir() string { return filepath.Join(l.c.app, "addons", "fiscal", "pont-secef") }

func (l *launcher) pontJeton() string {
	b, err := os.ReadFile(filepath.Join(l.pontDir(), "jeton.txt"))
	if err != nil {
		return ""
	}
	t := strings.TrimSpace(string(b))
	if len(t) < 32 {
		return ""
	}
	return t
}

// État pour le rapport de diagnostic
func (l *launcher) pontEtat() string {
	l.pont.mu.Lock()
	defer l.pont.mu.Unlock()
	switch {
	case !fileExists(l.c.node):
		return "Node.js absent (" + l.c.node + ")"
	case l.pontJeton() == "":
		return "non configuré (aucun jeton : Certification fiscale → MARWA SECEF CONNECT)"
	case l.pont.cmd != nil:
		return fmt.Sprintf("en marche sur 127.0.0.1:%d", pontPort)
	default:
		return fmt.Sprintf("arrêté (%d échec(s) de démarrage)", l.pont.echecs)
	}
}

func fileExists(p string) bool {
	_, err := os.Stat(p)
	return err == nil
}

func (l *launcher) pontWatch() {
	for {
		l.pontTick()
		select {
		case <-l.done:
			return
		case <-time.After(10 * time.Second):
		}
	}
}

func (l *launcher) pontTick() {
	if l.stopping.Load() || !fileExists(l.c.node) || !fileExists(filepath.Join(l.pontDir(), "serveur.js")) {
		return
	}
	jeton := l.pontJeton()
	l.pont.mu.Lock()
	defer l.pont.mu.Unlock()
	if jeton == "" {
		return
	}
	if l.pont.cmd != nil && l.pont.jeton == jeton {
		return // en marche avec le bon jeton
	}
	if l.pont.cmd != nil { // nouveau jeton créé dans POS Marwa : redémarrage
		log.Printf("Pont MARWA SECEF : nouveau jeton, redémarrage")
		_ = l.pont.cmd.Process.Kill()
		l.pont.cmd = nil
		time.Sleep(time.Second)
	}
	if time.Now().Before(l.pont.attente) {
		return
	}
	if c, err := net.DialTimeout("tcp", fmt.Sprintf("127.0.0.1:%d", pontPort), 500*time.Millisecond); err == nil {
		c.Close()
		l.pont.attente = time.Now().Add(time.Minute)
		log.Printf("Pont MARWA SECEF : le port %d est déjà utilisé (pont lancé à la main ?) : démarrage automatique différé", pontPort)
		return
	}
	cmd := exec.Command(l.c.node, filepath.Join(l.pontDir(), "serveur.js"))
	cmd.Dir = l.pontDir()
	nav := env("POS_BUREAU_PONT_NAVIGATEUR", "msedge")
	if l.c.navigateur == "chrome" {
		nav = "chrome"
	}
	cmd.Env = append(os.Environ(), "PONT_SECEF_JETON="+jeton, "PONT_SECEF_DONNEES="+filepath.Join(l.c.data, "pont-secef"),
		fmt.Sprintf("PONT_SECEF_PORT=%d", pontPort), "PONT_SECEF_NAVIGATEUR="+nav)
	f, _ := os.OpenFile(filepath.Join(l.c.logs, "pont-secef.log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
	cmd.Stdout, cmd.Stderr = f, f
	hide(cmd)
	if err := cmd.Start(); err != nil {
		l.pont.echecs++
		l.pont.attente = time.Now().Add(time.Duration(min(l.pont.echecs, 6)) * 30 * time.Second)
		log.Printf("Pont MARWA SECEF : démarrage impossible : %v", err)
		return
	}
	attachJob(cmd)
	l.pont.cmd, l.pont.jeton = cmd, jeton
	log.Printf("Pont MARWA SECEF démarré (Node.js livré, navigateur %s)", nav)
	go func(c *exec.Cmd) {
		err := c.Wait()
		if f != nil {
			f.Close()
		}
		l.pont.mu.Lock()
		defer l.pont.mu.Unlock()
		if l.pont.cmd == c {
			l.pont.cmd = nil
			if !l.stopping.Load() {
				l.pont.echecs++
				l.pont.attente = time.Now().Add(time.Duration(min(l.pont.echecs, 6)) * 30 * time.Second) // redémarrage espacé
				log.Printf("Pont MARWA SECEF arrêté (%v) : nouveau démarrage dans %d s", err, min(l.pont.echecs, 6)*30)
			}
		}
	}(cmd)
}

func (l *launcher) stopPont() {
	l.pont.mu.Lock()
	defer l.pont.mu.Unlock()
	if l.pont.cmd != nil && l.pont.cmd.Process != nil {
		_ = l.pont.cmd.Process.Kill()
		l.pont.cmd = nil
	}
}
