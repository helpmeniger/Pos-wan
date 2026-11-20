package main

// Protection de l'édition Bureau :
//   - mots de passe de la base et clé APP_KEY chiffrés avec la protection de Windows liée à CET ordinateur
//     (DPAPI, portée « machine ») dans donnees\secret\bd.dat : jamais en clair dans app\.env ni ailleurs ;
//     le dossier copié sur un autre ordinateur ne peut pas les lire (POS Marwa ne démarre pas) ;
//   - empreinte de l'ordinateur (MachineGuid de Windows) transmise à l'application : la licence est liée à
//     l'ordinateur ;
//   - date du premier démarrage gardée hors du dossier du logiciel (registre de Windows et dossier système
//     caché), signée : réinstaller ou effacer le dossier ne relance pas la période d'essai.
// Limite honnête : un administrateur de l'ordinateur d'origine peut lire ces informations, et un informaticien
// qui modifie le programme peut contourner ces contrôles. Ils empêchent la copie simple et la remise à zéro.

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

type secrets struct{ root, app, key string }

// Le dossier a été copié depuis un autre ordinateur (ou Windows a été réinstallé)
var errAutrePC = errors.New("autre ordinateur")

func (c *config) secretFile() string    { return filepath.Join(c.data, "secret", "bd.dat") }
func (c *config) oldSecretFile() string { return filepath.Join(c.data, "secret", "bd.ini") }

func parseSecrets(b []byte) secrets {
	var s secrets
	for _, l := range strings.Split(string(b), "\n") {
		k, v, _ := strings.Cut(strings.TrimSpace(l), "=")
		switch k {
		case "root":
			s.root = v
		case "app":
			s.app = v
		case "cle":
			s.key = v
		}
	}
	return s
}

// loadSecrets : (secrets, trouvés, erreur). Ancien fichier en clair (bd.ini, versions 3.13) : converti.
func (c *config) loadSecrets() (secrets, bool, error) {
	if b, err := os.ReadFile(c.secretFile()); err == nil {
		raw, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(strings.TrimSpace(string(b)), "PMS1:"))
		if err != nil {
			return secrets{}, false, fmt.Errorf("fichier des mots de passe illisible : %w", err)
		}
		plain, err := unprotect(raw)
		if err != nil {
			return secrets{}, false, errAutrePC
		}
		s := parseSecrets(plain)
		return s, s.root != "" && s.app != "", nil
	}
	b, err := os.ReadFile(c.oldSecretFile())
	if err != nil {
		return secrets{}, false, nil
	}
	s := parseSecrets(b)
	if s.root == "" || s.app == "" {
		return s, false, nil
	}
	// Conversion : APP_KEY repris d'app\.env (même valeur : les données chiffrées avec elle restent lisibles)
	if s.key == "" {
		s.key = envFileValue(filepath.Join(c.app, ".env"), "APP_KEY")
	}
	if s.key == "" {
		s.key = randomHex(32)
	}
	if err := c.saveSecrets(s); err != nil {
		return s, true, nil // bd.ini reste en place : rien n'est perdu
	}
	if back, ok, err := c.loadSecrets(); err == nil && ok && back == s {
		os.Remove(c.oldSecretFile()) // relu et identique : l'ancien fichier en clair est retiré
		log.Printf("Mots de passe de la base chiffrés (protection de Windows liée à cet ordinateur) ; bd.ini en clair retiré")
	}
	return s, true, nil
}

func (c *config) saveSecrets(s secrets) error {
	if err := os.MkdirAll(filepath.Dir(c.secretFile()), 0o700); err != nil {
		return err
	}
	enc, err := protect([]byte("root=" + s.root + "\napp=" + s.app + "\ncle=" + s.key + "\n"))
	if err != nil {
		return fmt.Errorf("chiffrement des mots de passe impossible : %w", err)
	}
	tmp := c.secretFile() + ".tmp"
	if err := os.WriteFile(tmp, []byte("PMS1:"+base64.StdEncoding.EncodeToString(enc)+"\n"), 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, c.secretFile())
}

func envFileValue(path, key string) string {
	b, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	for _, l := range strings.Split(strings.ReplaceAll(string(b), "\r\n", "\n"), "\n") {
		k, v, ok := strings.Cut(strings.TrimSpace(l), "=")
		if ok && strings.TrimSpace(k) == key {
			v = strings.TrimSpace(v)
			if len(v) >= 2 && (v[0] == '"' || v[0] == '\'') && v[len(v)-1] == v[0] {
				v = v[1 : len(v)-1]
			}
			return v
		}
	}
	return ""
}

// ---------------------------------------------------------------------------------------------------------------
// Période d'essai : date du premier démarrage de POS Marwa sur cet ordinateur

func markerSig(ts int64, machine string) string {
	m := hmac.New(sha256.New, []byte("POS Marwa essai|"+machine))
	fmt.Fprintf(m, "%d", ts)
	return hex.EncodeToString(m.Sum(nil))[:24]
}

// trialStart : la plus ancienne date valide des deux emplacements ; une valeur modifiée à la main compte comme
// un essai terminé ; aucune valeur : maintenant. La date retenue est réécrite aux deux emplacements.
func trialStart(machine string) int64 {
	now := time.Now().Unix()
	best := int64(0)
	tampered := false
	for _, v := range markerRead() {
		ts, sig, ok := strings.Cut(strings.TrimSpace(v), ".")
		n, err := strconv.ParseInt(ts, 10, 64)
		if !ok || err != nil || n <= 0 || n > now+86400 || !hmac.Equal([]byte(sig), []byte(markerSig(n, machine))) {
			tampered = true
			continue
		}
		if best == 0 || n < best {
			best = n
		}
	}
	if best == 0 {
		if tampered {
			log.Printf("Essai : date de premier démarrage modifiée à la main : période d'essai considérée comme terminée")
			best = 1
		} else {
			best = now
		}
	}
	markerWrite(fmt.Sprintf("%d.%s", best, markerSig(best, machine)))
	return best
}

// Support externe proposé pour copier les sauvegardes
type support struct {
	Racine string `json:"racine"`
	Type   string `json:"type"` // amovible, disque, reseau
	Nom    string `json:"nom"`
	Libre  uint64 `json:"libre"`
	Total  uint64 `json:"total"`
}
