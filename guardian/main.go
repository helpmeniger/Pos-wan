// MARWA GUARDIAN — agent de surveillance et de protection des données pour POS Marwa.
// « Votre caisse protégée, vos données en sécurité. »
//
// Commandes :
//
//	guardian run              agent au premier plan (essais, dépannage)
//	guardian service          lancé par Windows (service « MarwaGuardian »)
//	guardian install          installe et démarre le service (administrateur)
//	guardian uninstall        arrête et retire le service (les données sont conservées)
//	guardian notificateur     notifications dans la session de l'utilisateur
//	guardian console          ouvre la console dans une fenêtre Edge
//	guardian mot-de-passe     définit le mot de passe administrateur de la console
//	guardian verifier         contrôle l'intégrité de l'historique
package main

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"marwa/guardian/internal/core"
	"marwa/guardian/internal/platform"
	"marwa/guardian/internal/store"
	"marwa/guardian/internal/web"
)

var version = "dev"

func main() {
	core.Version = version
	cmd := "run"
	if len(os.Args) > 1 {
		cmd = os.Args[1]
	}
	var err error
	switch cmd {
	case "run":
		err = runForeground()
	case "service":
		err = runService()
	case "install":
		err = install()
	case "uninstall":
		err = uninstall()
	case "notificateur":
		err = runNotifier()
	case "console":
		err = openConsole()
	case "mot-de-passe":
		err = setPassword()
	case "verifier":
		err = verify()
	case "version":
		fmt.Println("MARWA GUARDIAN", version)
	default:
		err = fmt.Errorf("commande inconnue « %s » (run, service, install, uninstall, notificateur, console, mot-de-passe, verifier, version)", cmd)
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "MARWA GUARDIAN :", err)
		os.Exit(1)
	}
}

// setup : dossier de données, journal de fonctionnement, base, configuration.
func setup() (string, *log.Logger, *store.Store, core.Config, error) {
	dir := platform.DataDir()
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return "", nil, nil, core.Config{}, fmt.Errorf("dossier de données %s : %w", dir, err)
	}
	lf, err := os.OpenFile(filepath.Join(dir, "guardian.log"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o640)
	if err != nil {
		return "", nil, nil, core.Config{}, err
	}
	if st, err := lf.Stat(); err == nil && st.Size() > 5<<20 {
		_ = lf.Truncate(0)
	}
	lg := log.New(io.MultiWriter(lf, os.Stderr), "", log.LstdFlags)
	cfg, err := core.LoadConfig(dir)
	if err != nil {
		lg.Printf("configuration illisible, valeurs par défaut : %v", err)
	}
	s, err := store.Open(dir)
	if err != nil {
		return "", nil, nil, cfg, err
	}
	return dir, lg, s, cfg, nil
}

// serve : agent + API jusqu'à l'annulation du contexte.
func serve(ctx context.Context) error {
	dir, lg, s, cfg, err := setup()
	if err != nil {
		return err
	}
	defer s.Close()
	lg.Printf("=== MARWA GUARDIAN %s — données %s, console http://127.0.0.1:%d/", version, dir, cfg.Port)
	a := core.New(s, cfg, dir, lg)
	srv, err := web.New(a, cfg.Port)
	if err != nil {
		return err
	}
	ln, err := srv.Listen()
	if err != nil {
		return fmt.Errorf("port %d de la console indisponible : %w", cfg.Port, err)
	}
	hs := &http.Server{Handler: srv.Handler(), ReadHeaderTimeout: 10 * time.Second}
	go func() {
		if err := hs.Serve(ln); err != nil && !errors.Is(err, http.ErrServerClosed) {
			lg.Printf("console : %v", err)
		}
	}()
	_ = s.AddJournal("démarrage de l'agent", "agent", version, time.Now())
	a.Run(ctx)
	sctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	_ = hs.Shutdown(sctx)
	_ = s.AddJournal("arrêt de l'agent", "agent", "", time.Now())
	lg.Printf("Arrêt de MARWA GUARDIAN")
	return nil
}

func runForeground() error {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	return serve(ctx)
}

func setPassword() error {
	_, _, s, _, err := setup()
	if err != nil {
		return err
	}
	defer s.Close()
	pw := os.Getenv("GUARDIAN_MOT_DE_PASSE") // installateur : transmis par l'environnement, jamais en argument
	if pw == "" {
		fmt.Print("Nouveau mot de passe administrateur (8 caractères au moins) : ")
		line, _ := bufio.NewReader(os.Stdin).ReadString('\n')
		pw = strings.TrimRight(line, "\r\n")
	}
	if err := web.SetPassword(s, pw); err != nil {
		return err
	}
	_ = s.AddJournal("mot de passe défini", "administrateur (ligne de commande)", "", time.Now())
	fmt.Println("Mot de passe enregistré.")
	return nil
}

func verify() error {
	_, _, s, _, err := setup()
	if err != nil {
		return err
	}
	defer s.Close()
	bad, err := s.Verify()
	if err != nil {
		return err
	}
	if len(bad) == 0 {
		fmt.Println("Historique intact.")
		return nil
	}
	return fmt.Errorf("historique modifié : %s", strings.Join(bad, " ; "))
}
