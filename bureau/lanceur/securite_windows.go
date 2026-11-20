//go:build windows

package main

import (
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"unsafe"
)

var (
	advapi32           = syscall.NewLazyDLL("advapi32.dll")
	crypt32            = syscall.NewLazyDLL("crypt32.dll")
	regCreateKeyEx     = advapi32.NewProc("RegCreateKeyExW")
	regSetValueEx      = advapi32.NewProc("RegSetValueExW")
	cryptProtectData   = crypt32.NewProc("CryptProtectData")
	cryptUnprotectData = crypt32.NewProc("CryptUnprotectData")
	localFree          = kernel32.NewProc("LocalFree")
	getLogicalDrives   = kernel32.NewProc("GetLogicalDrives")
	getDriveType       = kernel32.NewProc("GetDriveTypeW")
	getVolumeInfo      = kernel32.NewProc("GetVolumeInformationW")
	getDiskFreeSpaceEx = kernel32.NewProc("GetDiskFreeSpaceExW")
	setErrorMode       = kernel32.NewProc("SetErrorMode")
)

const (
	keyWow6464  = 0x0100
	essaiCle    = `Software\HighTechNiger\PM`
	essaiValeur = "e"
)

func regString(root syscall.Handle, path, name string) string {
	var k syscall.Handle
	p, _ := syscall.UTF16PtrFromString(path)
	if syscall.RegOpenKeyEx(root, p, 0, syscall.KEY_READ|keyWow6464, &k) != nil {
		return ""
	}
	defer syscall.RegCloseKey(k)
	n, _ := syscall.UTF16PtrFromString(name)
	var typ, size uint32
	if syscall.RegQueryValueEx(k, n, nil, &typ, nil, &size) != nil || typ != syscall.REG_SZ || size == 0 || size > 4096 {
		return ""
	}
	buf := make([]uint16, size/2+1)
	if syscall.RegQueryValueEx(k, n, nil, &typ, (*byte)(unsafe.Pointer(&buf[0])), &size) != nil {
		return ""
	}
	return syscall.UTF16ToString(buf)
}

// MachineGuid : attribué par Windows à l'installation du système, propre à cet ordinateur
func machineSource() string {
	return strings.TrimSpace(regString(syscall.HKEY_LOCAL_MACHINE, `SOFTWARE\Microsoft\Cryptography`, "MachineGuid"))
}

type dataBlob struct {
	n uint32
	p *byte
}

func blob(b []byte) *dataBlob {
	if len(b) == 0 {
		return &dataBlob{}
	}
	return &dataBlob{n: uint32(len(b)), p: &b[0]}
}

var entropy = []byte("POS Marwa Bureau|secrets")

// Chiffrement DPAPI, portée « ordinateur » (CRYPTPROTECT_LOCAL_MACHINE) : lisible seulement sur cet ordinateur
func protect(b []byte) ([]byte, error) {
	var out dataBlob
	r, _, err := cryptProtectData.Call(uintptr(unsafe.Pointer(blob(b))), 0, uintptr(unsafe.Pointer(blob(entropy))), 0, 0, 0x1|0x4, uintptr(unsafe.Pointer(&out)))
	if r == 0 {
		return nil, err
	}
	defer localFree.Call(uintptr(unsafe.Pointer(out.p)))
	return append([]byte(nil), unsafe.Slice(out.p, out.n)...), nil
}

func unprotect(b []byte) ([]byte, error) {
	var out dataBlob
	r, _, err := cryptUnprotectData.Call(uintptr(unsafe.Pointer(blob(b))), 0, uintptr(unsafe.Pointer(blob(entropy))), 0, 0, 0x1, uintptr(unsafe.Pointer(&out)))
	if r == 0 {
		return nil, err
	}
	defer localFree.Call(uintptr(unsafe.Pointer(out.p)))
	return append([]byte(nil), unsafe.Slice(out.p, out.n)...), nil
}

// Date du premier démarrage : registre de l'utilisateur et fichier caché de %ProgramData% (hors du dossier du
// logiciel, ni effacés par la désinstallation)
func markerFile() string {
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	return filepath.Join(pd, "HighTechNiger", "pm.dat")
}

func markerRead() []string {
	var v []string
	if s := regString(syscall.HKEY_CURRENT_USER, essaiCle, essaiValeur); s != "" {
		v = append(v, s)
	}
	if b, err := os.ReadFile(markerFile()); err == nil && len(b) > 0 {
		v = append(v, strings.TrimSpace(string(b)))
	}
	return v
}

func markerWrite(val string) {
	var k syscall.Handle
	p, _ := syscall.UTF16PtrFromString(essaiCle)
	if r, _, _ := regCreateKeyEx.Call(uintptr(syscall.HKEY_CURRENT_USER), uintptr(unsafe.Pointer(p)), 0, 0, 0, uintptr(syscall.KEY_WRITE|keyWow6464), 0, uintptr(unsafe.Pointer(&k)), 0); r == 0 {
		n, _ := syscall.UTF16PtrFromString(essaiValeur)
		d, _ := syscall.UTF16FromString(val)
		regSetValueEx.Call(uintptr(k), uintptr(unsafe.Pointer(n)), 0, syscall.REG_SZ, uintptr(unsafe.Pointer(&d[0])), uintptr(len(d)*2))
		syscall.RegCloseKey(k)
	}
	f := markerFile()
	if b, err := os.ReadFile(f); err == nil && strings.TrimSpace(string(b)) == val {
		return
	}
	if os.MkdirAll(filepath.Dir(f), 0o755) != nil {
		return
	}
	fp, _ := syscall.UTF16PtrFromString(f)
	syscall.SetFileAttributes(fp, syscall.FILE_ATTRIBUTE_NORMAL) // un fichier caché ne peut pas être réécrit tel quel
	if os.WriteFile(f, []byte(val), 0o644) == nil {
		syscall.SetFileAttributes(fp, syscall.FILE_ATTRIBUTE_HIDDEN|syscall.FILE_ATTRIBUTE_SYSTEM)
	}
}

// Clés USB, cartes mémoire, disques externes et lecteurs réseau ; ni le disque de Windows ni celui de POS Marwa
func listSupports(root string) []support {
	out := []support{}
	setErrorMode.Call(0x0001 | 0x8000) // pas de fenêtre « insérez un disque » pour un lecteur vide
	sys := strings.ToUpper(strings.TrimSuffix(os.Getenv("SystemDrive"), `\`))
	own := strings.ToUpper(filepath.VolumeName(root))
	mask, _, _ := getLogicalDrives.Call()
	for i := 0; i < 26; i++ {
		if mask&(1<<i) == 0 {
			continue
		}
		letter := string(rune('A'+i)) + ":"
		if letter == sys || letter == own || letter == "A:" || letter == "B:" {
			continue
		}
		racine := letter + `\`
		rp, _ := syscall.UTF16PtrFromString(racine)
		t, _, _ := getDriveType.Call(uintptr(unsafe.Pointer(rp)))
		typ := map[uintptr]string{2: "amovible", 3: "disque", 4: "reseau"}[t]
		if typ == "" {
			continue // lecteur de CD/DVD, disque virtuel en mémoire
		}
		name := make([]uint16, 261)
		if r, _, _ := getVolumeInfo.Call(uintptr(unsafe.Pointer(rp)), uintptr(unsafe.Pointer(&name[0])), 261, 0, 0, 0, 0, 0); r == 0 {
			continue // aucun support inséré
		}
		var free, total, totalFree uint64
		getDiskFreeSpaceEx.Call(uintptr(unsafe.Pointer(rp)), uintptr(unsafe.Pointer(&free)), uintptr(unsafe.Pointer(&total)), uintptr(unsafe.Pointer(&totalFree)))
		out = append(out, support{Racine: racine, Type: typ, Nom: syscall.UTF16ToString(name), Libre: free, Total: total})
	}
	return out
}
