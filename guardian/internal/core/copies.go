package core

import (
	"archive/zip"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"marwa/guardian/internal/platform"
	"marwa/guardian/internal/store"
)

// Copies des sauvegardes vérifiées vers d'autres endroits (autre disque, partage réseau, clé USB ou disque externe
// reconnu par son nom). Chaque destination reçoit un dossier « MARWA GUARDIAN\<ordinateur> » ; seul ce dossier est
// écrit, et la rotation n'y supprime que les anciennes copies faites par MARWA GUARDIAN. Les sauvegardes d'origine
// de POS Marwa ne sont jamais modifiées.

// CopyStatus : état d'une destination.
type CopyStatus struct {
	Dest        Destination `json:"dest"`
	Name        string      `json:"name"`
	Present     bool        `json:"present"` // destination accessible maintenant
	Folder      string      `json:"folder"`  // dossier réellement utilisé
	LastOK      *time.Time  `json:"last_ok"` // dernière copie complète et contrôlée
	Copied      int         `json:"copied"`  // fichiers copiés lors du dernier passage
	Held        int         `json:"held"`    // sauvegardes présentes sur la destination
	Free        uint64      `json:"free"`
	Error       string      `json:"error"`
	ConfigSaved *time.Time  `json:"config_saved"` // dernière copie de la configuration de POS Marwa
}

func hostname() string {
	h, _ := os.Hostname()
	if h == "" {
		h = "ordinateur"
	}
	return strings.Map(func(r rune) rune {
		if r == '/' || r == '\\' || r == ':' {
			return '_'
		}
		return r
	}, h)
}

// resolve : dossier racine de la destination, s'il est accessible maintenant.
func resolve(d Destination) (string, bool) {
	switch d.Kind {
	case "support":
		for _, v := range platform.Volumes() {
			if strings.EqualFold(v.Label, d.Label) && d.Label != "" {
				return v.Root, true
			}
		}
		return "", false
	default:
		if st, err := os.Stat(d.Path); err == nil && st.IsDir() {
			return d.Path, true
		}
		return d.Path, false
	}
}

func fileSHA(p string) (string, error) {
	f, err := os.Open(p)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

// copyFile : copie via un fichier temporaire, puis contrôle de l'empreinte avant de lui donner son nom.
func copyFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	tmp := dst + ".copie-en-cours"
	out, err := os.Create(tmp)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		os.Remove(tmp)
		return err
	}
	if err := out.Sync(); err != nil {
		out.Close()
		os.Remove(tmp)
		return err
	}
	out.Close()
	a, err1 := fileSHA(src)
	b, err2 := fileSHA(tmp)
	if err1 != nil || err2 != nil || a != b {
		os.Remove(tmp)
		return fmt.Errorf("copie de %s abîmée (support défectueux ou retiré ?)", filepath.Base(src))
	}
	return os.Rename(tmp, dst)
}

// copyStatuses : état gardé des destinations.
func (a *Agent) copyStatuses() []CopyStatus {
	var out []CopyStatus
	for _, d := range a.cfg().Destinations {
		cs := CopyStatus{Dest: d, Name: d.Name()}
		if raw := a.S.Get1("copie:" + d.ID); raw != "" {
			_ = json.Unmarshal([]byte(raw), &cs)
			cs.Dest, cs.Name = d, d.Name()
		}
		out = append(out, cs)
	}
	return out
}

// CheckCopies : copies en arrière-plan (une copie vers une clé USB peut durer plusieurs minutes).
func (a *Agent) CheckCopies() {
	if len(a.cfg().Destinations) == 0 {
		return
	}
	if _, loaded := a.busy.LoadOrStore("copies", true); loaded {
		return
	}
	go func() {
		defer a.busy.Delete("copies")
		a.RunCopies()
	}()
}

// RunCopies : un passage sur toutes les destinations.
func (a *Agent) RunCopies() {
	st := a.Status()
	var verified []BackupFile
	for _, b := range st.Backups.Recent {
		if b.Check.OK() {
			verified = append(verified, b)
		}
	}
	now := a.Now()
	for _, d := range a.cfg().Destinations {
		cs := CopyStatus{Dest: d, Name: d.Name()}
		if raw := a.S.Get1("copie:" + d.ID); raw != "" {
			_ = json.Unmarshal([]byte(raw), &cs)
			cs.Dest, cs.Name = d, d.Name()
		}
		cs.Copied, cs.Error = 0, ""
		root, ok := resolve(d)
		cs.Present = ok
		if !ok {
			cs.Error = map[bool]string{true: "support non branché", false: "dossier inaccessible"}[d.Kind == "support"]
			a.saveCopy(cs)
			// Jamais copiée depuis 7 jours : avertissement (une clé rangée ailleurs reste normale quelques jours)
			if cs.LastOK == nil || now.Sub(*cs.LastOK) > 7*24*time.Hour {
				a.raise(store.Incident{Code: "copie_absente", Key: d.ID, Severity: store.Warning, Title: "Copie des sauvegardes non faite : " + d.Name(),
					Problem:         fmt.Sprintf("%s : %s ; aucune copie réussie depuis plus de 7 jours.", d.Name(), cs.Error),
					Consequences:    "Si le disque de l'ordinateur tombe en panne ou si le PC est volé, les sauvegardes restées sur ce PC seraient perdues avec lui.",
					Recommendations: []string{map[bool]string{true: "Branchez le support « " + d.Label + " » : la copie se fait toute seule en moins de 30 minutes.", false: "Vérifiez que le dossier existe et que l'ordinateur y a accès (partage réseau allumé, droits d'écriture)."}[d.Kind == "support"]},
					Details:         map[string]string{"Destination": d.Name()}, Confidence: "certain"})
			}
			continue
		}
		folder := filepath.Join(root, "MARWA GUARDIAN", hostname())
		cs.Folder = folder
		err := a.copyTo(d, folder, verified, &cs)
		if free, e := platform.FreeSpace(folder); e == nil {
			cs.Free = free
		}
		if err != nil {
			cs.Error = err.Error()
			a.saveCopy(cs)
			a.raise(store.Incident{Code: "copie_echec", Key: d.ID, Severity: store.Warning, Title: "Copie des sauvegardes en échec : " + d.Name(),
				Problem:         "La copie des sauvegardes vers « " + d.Name() + " » n'a pas abouti : " + err.Error(),
				Consequences:    "Les copies hors de ce PC ne sont plus à jour.",
				Recommendations: []string{"Vérifiez l'espace libre et l'état de la destination, puis attendez le prochain passage (toutes les 30 minutes)."},
				Details:         map[string]string{"Destination": d.Name(), "Dossier": folder}, Confidence: "certain"})
			continue
		}
		t := now
		cs.LastOK = &t
		a.saveCopy(cs)
		a.clear("copie_echec", d.ID, "copie réussie")
		a.clear("copie_absente", d.ID, "copie réussie")
	}
}

func (a *Agent) saveCopy(cs CopyStatus) {
	if raw, err := json.Marshal(cs); err == nil {
		_ = a.S.Set("copie:"+cs.Dest.ID, string(raw))
	}
	a.set(func(s *Status) {
		for i := range s.Backups.Copies {
			if s.Backups.Copies[i].Dest.ID == cs.Dest.ID {
				s.Backups.Copies[i] = cs
				return
			}
		}
		s.Backups.Copies = append(s.Backups.Copies, cs)
	})
}

func (a *Agent) copyTo(d Destination, folder string, verified []BackupFile, cs *CopyStatus) error {
	if err := os.MkdirAll(folder, 0o750); err != nil {
		return fmt.Errorf("impossible d'écrire sur la destination : %v", err)
	}
	keep := d.Keep
	if keep <= 0 {
		keep = 30
	}
	// Du plus ancien au plus récent ; seulement les « keep » plus récentes
	sort.Slice(verified, func(i, j int) bool { return verified[i].Name < verified[j].Name })
	if len(verified) > keep {
		verified = verified[len(verified)-keep:]
	}
	for _, b := range verified {
		files := []string{b.Name, b.Name + ".sha256"}
		if b.Zip != "" {
			files = append(files, b.Zip, b.Zip+".sha256")
		}
		for _, f := range files {
			src := filepath.Join(filepath.Dir(b.Path), f)
			si, err := os.Stat(src)
			if err != nil {
				continue // .sha256 de l'archive des photos absent : rien à copier
			}
			dst := filepath.Join(folder, f)
			if di, err := os.Stat(dst); err == nil && di.Size() == si.Size() {
				continue // déjà copié
			}
			if free, err := platform.FreeSpace(folder); err == nil && free < uint64(si.Size())+(50<<20) {
				return fmt.Errorf("espace insuffisant sur la destination pour %s (%s nécessaires, %s libres)", f, size(si.Size()), size(int64(free)))
			}
			if err := copyFile(src, dst); err != nil {
				return err
			}
			cs.Copied++
		}
	}
	if err := a.saveConfigCopy(folder, cs); err != nil {
		return err
	}
	// Rotation : uniquement les copies de MARWA GUARDIAN dans son dossier, au-delà des « keep » plus récentes
	held, err := ScanBackups(folder)
	if err != nil {
		return err
	}
	for i, b := range held {
		if i < keep {
			continue
		}
		for _, f := range []string{b.Name, b.Name + ".sha256", b.Zip, b.Zip + ".sha256"} {
			if f != "" && f != ".sha256" {
				_ = os.Remove(filepath.Join(folder, f))
			}
		}
	}
	cs.Held = min(len(held), keep)
	return nil
}

// saveConfigCopy : configuration de POS Marwa (édition Bureau) une fois par jour : réglages, environnement de
// l'application et fichier des accès chiffrés (lisible seulement sur CET ordinateur). 10 jours gardés.
func (a *Agent) saveConfigCopy(folder string, cs *CopyStatus) error {
	p := a.Status().Pos
	if p.Kind != "bureau" || p.Dir == "" {
		return nil
	}
	name := "configuration_" + a.Now().Format("20060102") + ".zip"
	dst := filepath.Join(folder, name)
	if _, err := os.Stat(dst); err == nil {
		return nil
	}
	var files []string
	for _, rel := range []string{"pos-marwa.ini", filepath.Join("app", ".env"), filepath.Join("donnees", "secret", "bd.dat")} {
		if _, err := os.Stat(filepath.Join(p.Dir, rel)); err == nil {
			files = append(files, rel)
		}
	}
	if len(files) == 0 {
		return nil
	}
	tmp := dst + ".copie-en-cours"
	f, err := os.Create(tmp)
	if err != nil {
		return err
	}
	zw := zip.NewWriter(f)
	for _, rel := range files {
		w, err := zw.Create(strings.ReplaceAll(rel, `\`, "/"))
		if err == nil {
			var src *os.File
			if src, err = os.Open(filepath.Join(p.Dir, rel)); err == nil {
				_, err = io.Copy(w, src)
				src.Close()
			}
		}
		if err != nil {
			zw.Close()
			f.Close()
			os.Remove(tmp)
			return fmt.Errorf("copie de la configuration : %v", err)
		}
	}
	if err := errors.Join(zw.Close(), f.Close()); err != nil {
		os.Remove(tmp)
		return err
	}
	if err := os.Rename(tmp, dst); err != nil {
		return err
	}
	t := a.Now()
	cs.ConfigSaved = &t
	// 10 copies de configuration au plus
	m, _ := filepath.Glob(filepath.Join(folder, "configuration_*.zip"))
	sort.Strings(m)
	for len(m) > 10 {
		_ = os.Remove(m[0])
		m = m[1:]
	}
	return nil
}
