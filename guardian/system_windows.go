//go:build windows

package main

import (
	"context"
	"errors"
	"fmt"
	"html"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"

	"marwa/guardian/internal/core"
	"marwa/guardian/internal/platform"
)

const (
	serviceName    = "MarwaGuardian"
	serviceAccount = `NT SERVICE\MarwaGuardian` // compte virtuel propre au service (pas Administrateur)
	runKey         = `SOFTWARE\Microsoft\Windows\CurrentVersion\Run`
	runValue       = "MarwaGuardianNotificateur"
	notifierExe    = "MarwaGuardian-Notificateur.exe"
)

// ------------------------------------------------------------------------------------------------ service

type handler struct{}

func (handler) Execute(args []string, req <-chan svc.ChangeRequest, status chan<- svc.Status) (bool, uint32) {
	status <- svc.Status{State: svc.StartPending}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- serve(ctx) }()
	status <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}
	for {
		select {
		case err := <-done:
			cancel()
			if err != nil {
				return true, 1 // arrêt anormal : Windows relance le service (actions de récupération)
			}
			return false, 0
		case c := <-req:
			switch c.Cmd {
			case svc.Interrogate:
				status <- c.CurrentStatus
			case svc.Stop, svc.Shutdown:
				status <- svc.Status{State: svc.StopPending}
				cancel()
				select {
				case <-done:
				case <-time.After(10 * time.Second):
				}
				return false, 0
			}
		}
	}
}

func runService() error {
	inService, err := svc.IsWindowsService()
	if err != nil {
		return err
	}
	if !inService {
		return errors.New("cette commande est lancée par Windows ; pour un essai, utilisez « guardian run »")
	}
	return svc.Run(serviceName, handler{})
}

// ------------------------------------------------------------------------------------------------ installation

func hiddenCmd(name string, args ...string) *exec.Cmd {
	c := exec.Command(name, args...)
	c.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000}
	return c
}

func run(name string, args ...string) error {
	out, err := hiddenCmd(name, args...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s : %v — %s", name, err, strings.TrimSpace(string(out)))
	}
	return nil
}

// install : service au démarrage automatique (relancé en cas d'arrêt), droits minimaux, notificateur à l'ouverture de
// session. Exige les droits administrateur.
func install() error {
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("droits administrateur nécessaires : %w", err)
	}
	defer m.Disconnect()
	dir := platform.DataDir()
	// Dossier de données : SYSTEM et Administrateurs (tout), le service (modification), personne d'autre ;
	// sous-dossier « notificateur » lisible par les utilisateurs (port et jeton de lecture des alertes)
	if err := os.MkdirAll(filepath.Join(dir, "notificateur"), 0o750); err != nil {
		return err
	}
	s, err := m.OpenService(serviceName)
	if err == nil {
		s.Close()
		return errors.New("le service MarwaGuardian est déjà installé (désinstallez-le d'abord pour le réinstaller)")
	}
	s, err = m.CreateService(serviceName, exe, mgr.Config{
		DisplayName:      "MARWA GUARDIAN",
		Description:      "Surveillance et protection des données de POS Marwa : santé de l'ordinateur, arrêts anormaux, base de données, alertes.",
		StartType:        mgr.StartAutomatic,
		ServiceStartName: serviceAccount,
	}, "service")
	if err != nil {
		return fmt.Errorf("création du service : %w", err)
	}
	defer s.Close()
	_ = s.SetRecoveryActions([]mgr.RecoveryAction{{Type: mgr.ServiceRestart, Delay: 10 * time.Second}, {Type: mgr.ServiceRestart, Delay: 30 * time.Second}, {Type: mgr.ServiceRestart, Delay: 2 * time.Minute}}, 86400)
	steps := [][]string{
		{"icacls", dir, "/inheritance:r", "/grant:r", "*S-1-5-18:(OI)(CI)F", "*S-1-5-32-544:(OI)(CI)F", serviceAccount + ":(OI)(CI)M"},
		{"icacls", filepath.Join(dir, "notificateur"), "/grant", "*S-1-5-32-545:(OI)(CI)RX"},
		// Lecture des journaux d'événements : groupe « Lecteurs des journaux d'événements » (SID, nom traduit selon la langue)
		{"powershell", "-NoProfile", "-NonInteractive", "-Command", "Add-LocalGroupMember -SID S-1-5-32-573 -Member '" + serviceAccount + "' -ErrorAction SilentlyContinue"},
	}
	cfg, _ := core.LoadConfig(dir)
	posDir := cfg.PosDir
	if posDir == "" {
		posDir = platform.RegString(`SOFTWARE\POSMarwaBureau`, "Dossier")
	}
	if posDir != "" {
		if _, err := os.Stat(posDir); err == nil {
			steps = append(steps, []string{"icacls", posDir, "/grant", serviceAccount + ":(OI)(CI)RX"}) // lecture seule
		}
	}
	for _, st := range steps {
		if err := run(st[0], st[1:]...); err != nil {
			fmt.Fprintln(os.Stderr, "Avertissement :", err)
		}
	}
	// Notificateur : version « fenêtre » du programme (aucune console noire à l'ouverture de session)
	notif := filepath.Join(filepath.Dir(exe), notifierExe)
	if _, err := os.Stat(notif); err != nil {
		notif = exe
	}
	k, _, err := registry.CreateKey(registry.LOCAL_MACHINE, runKey, registry.SET_VALUE|registry.WOW64_64KEY)
	if err == nil {
		_ = k.SetStringValue(runValue, `"`+notif+`" notificateur`)
		k.Close()
	}
	if pw := os.Getenv("GUARDIAN_MOT_DE_PASSE"); pw != "" {
		if err := setPassword(); err != nil {
			return err
		}
	}
	if err := s.Start(); err != nil {
		return fmt.Errorf("démarrage du service : %w", err)
	}
	fmt.Println("MARWA GUARDIAN installé et démarré.")
	return nil
}

func uninstall() error {
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("droits administrateur nécessaires : %w", err)
	}
	defer m.Disconnect()
	if s, err := m.OpenService(serviceName); err == nil {
		_, _ = s.Control(svc.Stop)
		for i := 0; i < 20; i++ {
			if st, err := s.Query(); err != nil || st.State == svc.Stopped {
				break
			}
			time.Sleep(500 * time.Millisecond)
		}
		if err := s.Delete(); err != nil {
			s.Close()
			return err
		}
		s.Close()
	}
	if k, err := registry.OpenKey(registry.LOCAL_MACHINE, runKey, registry.SET_VALUE|registry.WOW64_64KEY); err == nil {
		_ = k.DeleteValue(runValue)
		k.Close()
	}
	_ = run("taskkill", "/F", "/IM", notifierExe) // notificateurs des sessions ouvertes
	fmt.Println("MARWA GUARDIAN désinstallé. L'historique est conservé dans", platform.DataDir())
	return nil
}

// ------------------------------------------------------------------------------------------------ console

func openConsole() error {
	cfg, _ := core.LoadConfig(platform.DataDir())
	url := fmt.Sprintf("http://127.0.0.1:%d/", cfg.Port)
	for _, base := range []string{os.Getenv("ProgramFiles(x86)"), os.Getenv("ProgramFiles"), os.Getenv("LOCALAPPDATA")} {
		edge := filepath.Join(base, `Microsoft\Edge\Application\msedge.exe`)
		if _, err := os.Stat(edge); err == nil && base != "" {
			return exec.Command(edge, "--app="+url, "--no-first-run").Start()
		}
	}
	return exec.Command("rundll32", "url.dll,FileProtocolHandler", url).Start()
}

// ------------------------------------------------------------------------------------------------ notifications

// showNotification : notification Windows (centre de notifications) ; cliquer ouvre la console. Le texte passe par une
// variable d'environnement (jamais dans la ligne de commande) et il est échappé pour le XML.
func showNotification(a Alert, console string) error {
	x := html.EscapeString
	scenario := ""
	if a.Severity >= 3 {
		scenario = ` scenario="reminder"` // reste affichée jusqu'à ce qu'on la ferme
	}
	body := a.Problem
	if a.Advice != "" {
		body += " " + a.Advice
	}
	toast := `<toast activationType="protocol" launch="` + x(console+"#incidents") + `"` + scenario + `><visual><binding template="ToastGeneric">` +
		`<text>` + x(a.Label+" — "+a.Title) + `</text><text>` + x(body) + `</text><text placement="attribution">MARWA GUARDIAN</text></binding></visual>` +
		`<actions><action content="Voir les détails" activationType="protocol" arguments="` + x(console+"#incidents") + `"/><action content="Fermer" activationType="system" arguments="dismiss"/></actions></toast>`
	script := `$ErrorActionPreference='Stop';` +
		`[void][Windows.UI.Notifications.ToastNotificationManager,Windows.UI.Notifications,ContentType=WindowsRuntime];` +
		`[void][Windows.Data.Xml.Dom.XmlDocument,Windows.Data.Xml.Dom.XmlDocument,ContentType=WindowsRuntime];` +
		`$x=New-Object Windows.Data.Xml.Dom.XmlDocument;$x.LoadXml($env:GUARDIAN_TOAST);` +
		`$n=[Windows.UI.Notifications.ToastNotification]::new($x);` +
		`[Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier('{1AC14E77-02E7-4E5D-B744-2EB1AE5198B7}\WindowsPowerShell\v1.0\powershell.exe').Show($n)`
	c := hiddenCmd("powershell", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script)
	c.Env = append(os.Environ(), "GUARDIAN_TOAST="+toast)
	if out, err := c.CombinedOutput(); err != nil {
		// Repli : boîte de message (toujours visible) pour les alertes graves
		if a.Severity >= 3 {
			t, _ := windows.UTF16PtrFromString("MARWA GUARDIAN — " + a.Title)
			m, _ := windows.UTF16PtrFromString(body)
			_, _ = windows.MessageBox(0, m, t, windows.MB_OK|windows.MB_ICONWARNING|windows.MB_SETFOREGROUND)
			return nil
		}
		return fmt.Errorf("notification Windows : %v %s", err, strings.TrimSpace(string(out)))
	}
	return nil
}
