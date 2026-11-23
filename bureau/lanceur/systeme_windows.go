//go:build windows

package main

import (
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"syscall"
	"unsafe"
)

var (
	user32           = syscall.NewLazyDLL("user32.dll")
	kernel32         = syscall.NewLazyDLL("kernel32.dll")
	messageBox       = user32.NewProc("MessageBoxW")
	createJobObject  = kernel32.NewProc("CreateJobObjectW")
	setInfoJobObject = kernel32.NewProc("SetInformationJobObject")
	assignProcess    = kernel32.NewProc("AssignProcessToJobObject")
	openProcess      = kernel32.NewProc("OpenProcess")
	jobHandle        uintptr
)

const createNoWindow = 0x08000000

// Aucune fenêtre noire pour les serveurs (PHP, MariaDB, outils)
func hide(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: createNoWindow}
}

func fatal(msg string) {
	t, _ := syscall.UTF16PtrFromString("POS Marwa")
	m, _ := syscall.UTF16PtrFromString(msg + "\n\nJournal : dossier donnees\\journaux\\lanceur.log")
	messageBox.Call(0, uintptr(unsafe.Pointer(m)), uintptr(unsafe.Pointer(t)), 0x10) // MB_ICONERROR
	os.Exit(1)
}

// Message d'avertissement sans arrêter POS Marwa (contrôle de démarrage)
func alert(msg string) {
	t, _ := syscall.UTF16PtrFromString("POS Marwa — contrôle")
	m, _ := syscall.UTF16PtrFromString(msg)
	messageBox.Call(0, uintptr(unsafe.Pointer(m)), uintptr(unsafe.Pointer(t)), 0x30|0x10000) // MB_ICONWARNING | MB_SETFOREGROUND
}

// Groupe de processus fermé avec le lanceur : si « POS Marwa.exe » est arrêté de force, les serveurs PHP s'arrêtent
// aussi (MariaDB n'y est pas : elle s'arrête proprement, ou est réutilisée au démarrage suivant).
func init() {
	h, _, _ := createJobObject.Call(0, 0)
	if h == 0 {
		return
	}
	// JOBOBJECT_EXTENDED_LIMIT_INFORMATION (x64 : 144 octets), LimitFlags = KILL_ON_JOB_CLOSE (0x2000) à l'offset 16
	var info [144]byte
	*(*uint32)(unsafe.Pointer(&info[16])) = 0x2000
	if r, _, _ := setInfoJobObject.Call(h, 9, uintptr(unsafe.Pointer(&info[0])), uintptr(len(info))); r == 0 {
		return
	}
	jobHandle = h
}

func attachJob(cmd *exec.Cmd) {
	if jobHandle == 0 || cmd.Process == nil {
		return
	}
	// PROCESS_SET_QUOTA | PROCESS_TERMINATE
	ph, _, _ := openProcess.Call(0x0100|0x0001, 0, uintptr(cmd.Process.Pid))
	if ph != 0 {
		assignProcess.Call(jobHandle, ph)
		syscall.CloseHandle(syscall.Handle(ph))
	}
}

func findBrowser(pref string) string {
	pf86, pf, local := os.Getenv("ProgramFiles(x86)"), os.Getenv("ProgramFiles"), os.Getenv("LOCALAPPDATA")
	edge := []string{filepath.Join(pf86, `Microsoft\Edge\Application\msedge.exe`), filepath.Join(pf, `Microsoft\Edge\Application\msedge.exe`), filepath.Join(local, `Microsoft\Edge\Application\msedge.exe`)}
	chrome := []string{filepath.Join(pf, `Google\Chrome\Application\chrome.exe`), filepath.Join(pf86, `Google\Chrome\Application\chrome.exe`), filepath.Join(local, `Google\Chrome\Application\chrome.exe`)}
	var list []string
	switch pref {
	case "chrome":
		list = append(chrome, edge...)
	case "defaut", "navigateur":
		return ""
	default:
		list = append(edge, chrome...)
	}
	for _, p := range list {
		if st, err := os.Stat(p); err == nil && !st.IsDir() {
			return p
		}
	}
	return ""
}

func extraBrowserArgs() []string { return nil }

func openDefault(u string) error {
	cmd := exec.Command("rundll32", "url.dll,FileProtocolHandler", u)
	return cmd.Start()
}

func waitSignal(f func()) {
	ch := make(chan os.Signal, 1)
	signal.Notify(ch, os.Interrupt, syscall.SIGTERM)
	<-ch
	f()
}

// Profil du navigateur en cours d'utilisation (fenêtre encore ouverte) : Edge et Chrome gardent le fichier « lockfile »
// du profil ouvert en exclusivité ; s'il peut être supprimé, c'est un reste d'une fenêtre fermée.
func profileInUse(dir string) bool {
	f := filepath.Join(dir, "lockfile")
	if _, err := os.Stat(f); err != nil {
		return false
	}
	return os.Remove(f) != nil
}
