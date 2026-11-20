//go:build !windows

package main

// Équivalents pour Linux (essais automatiques) : chiffrement AES-GCM avec une clé tirée de l'identifiant de la
// machine (comme DPAPI, illisible sur une autre machine), marqueurs d'essai dans un dossier, supports de /media.

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/sha256"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"syscall"
)

func machineSource() string {
	if v := os.Getenv("POS_BUREAU_MACHINE"); v != "" {
		return v
	}
	b, _ := os.ReadFile("/etc/machine-id")
	return strings.TrimSpace(string(b))
}

func gcm() (cipher.AEAD, error) {
	k := sha256.Sum256([]byte("POS Marwa Bureau|secrets|" + machineSource()))
	bc, err := aes.NewCipher(k[:])
	if err != nil {
		return nil, err
	}
	return cipher.NewGCM(bc)
}

func protect(b []byte) ([]byte, error) {
	g, err := gcm()
	if err != nil {
		return nil, err
	}
	n := make([]byte, g.NonceSize())
	rand.Read(n)
	return g.Seal(n, n, b, nil), nil
}

func unprotect(b []byte) ([]byte, error) {
	g, err := gcm()
	if err != nil {
		return nil, err
	}
	if len(b) < g.NonceSize() {
		return nil, errors.New("trop court")
	}
	return g.Open(nil, b[:g.NonceSize()], b[g.NonceSize():], nil)
}

func markerDir() string {
	if d := os.Getenv("POS_BUREAU_MARQUEURS"); d != "" {
		return d
	}
	h, _ := os.UserHomeDir()
	return filepath.Join(h, ".config", ".posmarwa")
}

func markerRead() []string {
	var v []string
	for _, f := range []string{"a", "b"} {
		if b, err := os.ReadFile(filepath.Join(markerDir(), f)); err == nil && len(b) > 0 {
			v = append(v, strings.TrimSpace(string(b)))
		}
	}
	return v
}

func markerWrite(val string) {
	os.MkdirAll(markerDir(), 0o700)
	for _, f := range []string{"a", "b"} {
		os.WriteFile(filepath.Join(markerDir(), f), []byte(val), 0o600)
	}
}

func listSupports(root string) []support {
	out := []support{}
	var dirs []string
	if v := os.Getenv("POS_BUREAU_SUPPORTS"); v != "" {
		dirs = strings.Split(v, ":")
	} else {
		for _, g := range []string{"/media/*/*", "/run/media/*/*"} {
			m, _ := filepath.Glob(g)
			dirs = append(dirs, m...)
		}
	}
	for _, d := range dirs {
		var st syscall.Statfs_t
		if fi, err := os.Stat(d); err != nil || !fi.IsDir() || syscall.Statfs(d, &st) != nil {
			continue
		}
		out = append(out, support{Racine: d, Type: "amovible", Nom: filepath.Base(d), Libre: st.Bavail * uint64(st.Bsize), Total: st.Blocks * uint64(st.Bsize)})
	}
	return out
}
