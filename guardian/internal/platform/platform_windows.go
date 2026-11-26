//go:build windows

package platform

import (
	"fmt"
	"os"
	"os/exec"
	"strings"
	"syscall"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
	"golang.org/x/sys/windows/svc"
)

var (
	kernel32               = windows.NewLazySystemDLL("kernel32.dll")
	procGlobalMemoryEx     = kernel32.NewProc("GlobalMemoryStatusEx")
	procGetSystemTimes     = kernel32.NewProc("GetSystemTimes")
	procGetTickCount64     = kernel32.NewProc("GetTickCount64")
	procGetDiskFreeSpaceEx = kernel32.NewProc("GetDiskFreeSpaceExW")
)

// Commande sans fenêtre de console (le notificateur tourne dans la session de l'utilisateur).
func hidden(cmd *exec.Cmd) *exec.Cmd {
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000} // CREATE_NO_WINDOW
	return cmd
}

// Events : événements d'un journal Windows (System, Application, Security, ou journal « Microsoft-Windows-…/Operational »).
func Events(log string, ids []int, since time.Time, max int) ([]Event, error) {
	if max <= 0 {
		max = 500
	}
	out, err := hidden(exec.Command("wevtutil", "qe", log, "/q:"+EventQuery(ids, since), "/f:xml", fmt.Sprintf("/c:%d", max))).Output()
	if err != nil {
		return nil, fmt.Errorf("journal %s illisible : %v", log, err)
	}
	return ParseEvents(out)
}

type memoryStatusEx struct {
	Length               uint32
	MemoryLoad           uint32
	TotalPhys            uint64
	AvailPhys            uint64
	TotalPageFile        uint64
	AvailPageFile        uint64
	TotalVirtual         uint64
	AvailVirtual         uint64
	AvailExtendedVirtual uint64
}

func ReadMemory() (Memory, error) {
	var m memoryStatusEx
	m.Length = uint32(unsafe.Sizeof(m))
	if r, _, err := procGlobalMemoryEx.Call(uintptr(unsafe.Pointer(&m))); r == 0 {
		return Memory{}, err
	}
	return Memory{Total: m.TotalPhys, Available: m.AvailPhys}, nil
}

func systemTimes() (idle, total uint64, err error) {
	var i, k, u windows.Filetime
	if r, _, e := procGetSystemTimes.Call(uintptr(unsafe.Pointer(&i)), uintptr(unsafe.Pointer(&k)), uintptr(unsafe.Pointer(&u))); r == 0 {
		return 0, 0, e
	}
	ft := func(f windows.Filetime) uint64 { return uint64(f.HighDateTime)<<32 | uint64(f.LowDateTime) }
	return ft(i), ft(k) + ft(u), nil // le temps noyau inclut le temps d'inactivité
}

// CPUPercent : utilisation moyenne du processeur sur l'intervalle.
func CPUPercent(sample time.Duration) (float64, error) {
	i1, t1, err := systemTimes()
	if err != nil {
		return 0, err
	}
	time.Sleep(sample)
	i2, t2, err := systemTimes()
	if err != nil || t2 <= t1 {
		return 0, err
	}
	return 100 * (1 - float64(i2-i1)/float64(t2-t1)), nil
}

// Disks : volumes fixes (disques internes et externes « fixes »).
func Disks() ([]Disk, error) {
	sys := strings.ToUpper(os.Getenv("SystemDrive"))
	if sys == "" {
		sys = "C:"
	}
	mask, err := windows.GetLogicalDrives()
	if err != nil {
		return nil, err
	}
	var out []Disk
	for i := 0; i < 26; i++ {
		if mask&(1<<uint(i)) == 0 {
			continue
		}
		root := string(rune('A'+i)) + `:\`
		p, _ := windows.UTF16PtrFromString(root)
		if windows.GetDriveType(p) != windows.DRIVE_FIXED {
			continue
		}
		var free, total, totalFree uint64
		if r, _, _ := procGetDiskFreeSpaceEx.Call(uintptr(unsafe.Pointer(p)), uintptr(unsafe.Pointer(&free)), uintptr(unsafe.Pointer(&total)), uintptr(unsafe.Pointer(&totalFree))); r == 0 {
			continue
		}
		out = append(out, Disk{Mount: root, Total: total, Free: free, System: strings.HasPrefix(strings.ToUpper(root), sys)})
	}
	return out, nil
}

// BootTime : heure du dernier démarrage de Windows.
func BootTime() time.Time {
	r, _, _ := procGetTickCount64.Call()
	return time.Now().Add(-time.Duration(r) * time.Millisecond).Truncate(time.Second)
}

func scm() (windows.Handle, error) {
	// Droits minimaux : se connecter et lister (un compte de service non administrateur ne peut pas davantage)
	return windows.OpenSCManager(nil, nil, windows.SC_MANAGER_CONNECT|windows.SC_MANAGER_ENUMERATE_SERVICE)
}

// ServiceState : état d'un service Windows.
func ServiceState(name string) Service {
	out := Service{Name: name}
	m, err := scm()
	if err != nil {
		return out
	}
	defer windows.CloseServiceHandle(m)
	n, _ := windows.UTF16PtrFromString(name)
	h, err := windows.OpenService(m, n, windows.SERVICE_QUERY_STATUS)
	if err != nil {
		return out
	}
	defer windows.CloseServiceHandle(h)
	out.Exists = true
	var st windows.SERVICE_STATUS
	if err := windows.QueryServiceStatus(h, &st); err != nil {
		return out
	}
	out.Running = st.CurrentState == windows.SERVICE_RUNNING
	out.State = map[uint32]string{uint32(svc.Stopped): "arrêté", uint32(svc.StartPending): "démarrage", uint32(svc.StopPending): "arrêt en cours",
		uint32(svc.Running): "en marche", uint32(svc.Paused): "en pause"}[st.CurrentState]
	return out
}

// ServiceNames : services dont le nom commence par un préfixe (découverte des services de WampServer).
func ServiceNames(prefix string) []string {
	m, err := scm()
	if err != nil {
		return nil
	}
	defer windows.CloseServiceHandle(m)
	var needed, count, resume uint32
	_ = windows.EnumServicesStatusEx(m, windows.SC_ENUM_PROCESS_INFO, windows.SERVICE_WIN32, windows.SERVICE_STATE_ALL, nil, 0, &needed, &count, &resume, nil)
	if needed == 0 {
		return nil
	}
	buf := make([]byte, needed)
	resume = 0
	if err := windows.EnumServicesStatusEx(m, windows.SC_ENUM_PROCESS_INFO, windows.SERVICE_WIN32, windows.SERVICE_STATE_ALL, &buf[0], needed, &needed, &count, &resume, nil); err != nil {
		return nil
	}
	list := unsafe.Slice((*windows.ENUM_SERVICE_STATUS_PROCESS)(unsafe.Pointer(&buf[0])), count)
	var out []string
	for _, s := range list {
		name := windows.UTF16PtrToString(s.ServiceName)
		if strings.HasPrefix(strings.ToLower(name), strings.ToLower(prefix)) {
			out = append(out, name)
		}
	}
	return out
}

// RegString : valeur texte du registre HKEY_LOCAL_MACHINE (vide si absente).
func RegString(path, name string) string {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, path, registry.QUERY_VALUE|registry.WOW64_64KEY)
	if err != nil {
		return ""
	}
	defer k.Close()
	v, _, _ := k.GetStringValue(name)
	return v
}

// ProcessRunning : un processus de ce nom (ex. « POS Marwa.exe ») est-il en cours d'exécution ?
func ProcessRunning(exe string) bool {
	snap, err := windows.CreateToolhelp32Snapshot(windows.TH32CS_SNAPPROCESS, 0)
	if err != nil {
		return false
	}
	defer windows.CloseHandle(snap)
	var e windows.ProcessEntry32
	e.Size = uint32(unsafe.Sizeof(e))
	for err = windows.Process32First(snap, &e); err == nil; err = windows.Process32Next(snap, &e) {
		if strings.EqualFold(windows.UTF16ToString(e.ExeFile[:]), exe) {
			return true
		}
	}
	return false
}

// ProgramData : dossier de données de l'agent.
func DataDir() string {
	if d := os.Getenv("GUARDIAN_DATA"); d != "" {
		return d
	}
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	return pd + `\MarwaGuardian`
}

// Volumes : lecteurs présents (fixes, amovibles, réseau connectés), avec leur nom de volume.
func Volumes() []Volume {
	mask, err := windows.GetLogicalDrives()
	if err != nil {
		return nil
	}
	var out []Volume
	for i := 0; i < 26; i++ {
		if mask&(1<<uint(i)) == 0 {
			continue
		}
		root := string(rune('A'+i)) + `:\`
		p, _ := windows.UTF16PtrFromString(root)
		t := windows.GetDriveType(p)
		if t != windows.DRIVE_FIXED && t != windows.DRIVE_REMOVABLE && t != windows.DRIVE_REMOTE {
			continue
		}
		name := make([]uint16, 261)
		if err := windows.GetVolumeInformation(p, &name[0], uint32(len(name)), nil, nil, nil, nil, 0); err != nil {
			continue // lecteur sans support (lecteur de carte vide)
		}
		out = append(out, Volume{Root: root, Label: windows.UTF16ToString(name), Removable: t == windows.DRIVE_REMOVABLE})
	}
	return out
}

// FreeSpace : octets disponibles sur le volume d'un dossier (disque local, clé USB, partage réseau).
func FreeSpace(path string) (uint64, error) {
	p, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return 0, err
	}
	var free, total, totalFree uint64
	if r, _, e := procGetDiskFreeSpaceEx.Call(uintptr(unsafe.Pointer(p)), uintptr(unsafe.Pointer(&free)), uintptr(unsafe.Pointer(&total)), uintptr(unsafe.Pointer(&totalFree))); r == 0 {
		return 0, e
	}
	return free, nil
}

// LowPriority : processus en priorité « inférieure à la normale », sans fenêtre (la caisse reste prioritaire).
func LowPriority(cmd *exec.Cmd) *exec.Cmd {
	hidden(cmd)
	cmd.SysProcAttr.CreationFlags |= 0x00004000 // BELOW_NORMAL_PRIORITY_CLASS
	return cmd
}

// ExeName : nom d'un exécutable sous Windows.
func ExeName(n string) string { return n + ".exe" }
