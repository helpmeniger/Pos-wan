//go:build !windows

package main

import (
	"errors"
	"fmt"

	"marwa/guardian/internal/core"
	"marwa/guardian/internal/platform"
)

// Hors Windows (essais) : pas de service Windows ; l'agent se lance avec « guardian run ».

func runService() error {
	return errors.New("le service n'existe que sous Windows : utilisez « guardian run »")
}
func install() error   { return errors.New("installation du service : Windows uniquement") }
func uninstall() error { return errors.New("désinstallation du service : Windows uniquement") }

func openConsole() error {
	cfg, _ := core.LoadConfig(platform.DataDir())
	fmt.Printf("Console : http://127.0.0.1:%d/\n", cfg.Port)
	return nil
}

// showNotification : affichée dans le terminal (les essais lisent cette ligne).
func showNotification(a Alert, console string) error {
	fmt.Printf("NOTIFICATION [%s] %s — %s — %s (%s)\n", a.Label, a.Title, a.Problem, a.Advice, console)
	return nil
}
