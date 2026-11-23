//go:build !windows

package main

// Équivalents pour Linux : servent aux essais automatiques du lanceur (mêmes étapes que sous Windows).

import (
	"fmt"
	"log"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
)

func hide(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
}

func attachJob(cmd *exec.Cmd) {}

func fatal(msg string) {
	fmt.Fprintln(os.Stderr, "POS Marwa : "+msg)
	os.Exit(1)
}

func alert(msg string) { log.Printf("AVERTISSEMENT : %s", msg) }

func findBrowser(pref string) string {
	if p := os.Getenv("POS_BUREAU_NAVIGATEUR"); p != "" {
		return p
	}
	for _, p := range []string{"/usr/bin/chromium", "/usr/bin/google-chrome"} {
		if _, err := os.Stat(p); err == nil {
			return p
		}
	}
	return ""
}

// Linux (essais en tant que root) : Chromium refuse de démarrer sans --no-sandbox
func extraBrowserArgs() []string {
	if os.Geteuid() == 0 {
		return []string{"--no-sandbox"}
	}
	return nil
}

func openDefault(u string) error { return exec.Command("xdg-open", u).Start() }

func waitSignal(f func()) {
	ch := make(chan os.Signal, 1)
	signal.Notify(ch, os.Interrupt, syscall.SIGTERM)
	<-ch
	f()
}

// Profil du navigateur en cours d'utilisation (fenêtre encore ouverte) : Chromium tient « SingletonLock », lien
// symbolique « machine-pid ».
func profileInUse(dir string) bool {
	t, err := os.Readlink(filepath.Join(dir, "SingletonLock"))
	if err != nil {
		return false
	}
	i := strings.LastIndex(t, "-")
	pid, err := strconv.Atoi(t[i+1:])
	if err != nil || pid <= 0 {
		return false
	}
	return syscall.Kill(pid, 0) == nil
}
