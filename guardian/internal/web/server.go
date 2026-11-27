// Package web : API locale et console de MARWA GUARDIAN (127.0.0.1 uniquement).
//
// Sécurité : écoute sur 127.0.0.1 seulement ; nom d'hôte vérifié (contre le « DNS rebinding ») ; console protégée par
// un mot de passe administrateur (Argon2id, essais limités) et une session ; actions avec jeton anti-falsification ;
// le notificateur n'a qu'un jeton de lecture des alertes.
package web

import (
	"crypto/rand"
	"crypto/subtle"
	"embed"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/argon2"

	"marwa/guardian/internal/core"
	"marwa/guardian/internal/platform"
	"marwa/guardian/internal/store"
)

//go:embed console
var consoleFS embed.FS

type session struct {
	csrf    string
	expires time.Time
}

// Server : API et console.
type Server struct {
	A        *core.Agent
	Port     int
	notifTok string

	mu       sync.Mutex
	sessions map[string]*session
	fails    []time.Time // échecs de connexion récents (limitation)
}

// NotifierTokenFile : jeton du notificateur (lecture des alertes seulement) et port de la console, recréés à chaque
// démarrage du service. Seul le sous-dossier « notificateur » est lisible par les utilisateurs de l'ordinateur.
func NotifierTokenFile(dir string) string { return filepath.Join(dir, "notificateur", "jeton") }

func randHex(n int) string {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b)
}

func New(a *core.Agent, port int) (*Server, error) {
	s := &Server{A: a, Port: port, sessions: map[string]*session{}, notifTok: randHex(32)}
	if err := os.MkdirAll(filepath.Dir(NotifierTokenFile(a.Dir)), 0o755); err != nil {
		return nil, err
	}
	if err := os.WriteFile(NotifierTokenFile(a.Dir), []byte(strconv.Itoa(port)+"\n"+s.notifTok), 0o644); err != nil {
		return nil, err
	}
	return s, nil
}

// Listener : 127.0.0.1 seulement (jamais le réseau).
func (s *Server) Listen() (net.Listener, error) {
	return net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(s.Port))
}

func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	sub, _ := fs.Sub(consoleFS, "console")
	mux.Handle("GET /", http.FileServer(http.FS(sub)))
	mux.HandleFunc("GET /api/sante", s.health)
	mux.HandleFunc("GET /api/session", s.sessionInfo)
	mux.HandleFunc("POST /api/premier-mot-de-passe", s.setup)
	mux.HandleFunc("POST /api/connexion", s.login)
	mux.HandleFunc("POST /api/deconnexion", s.auth(s.logout))
	mux.HandleFunc("GET /api/etat", s.auth(s.state))
	mux.HandleFunc("GET /api/incidents", s.auth(s.incidents))
	mux.HandleFunc("GET /api/incidents/{id}", s.auth(s.incident))
	mux.HandleFunc("POST /api/incidents/{id}/resoudre", s.auth(s.resolve))
	mux.HandleFunc("GET /api/journal", s.auth(s.journal))
	mux.HandleFunc("GET /api/mesures", s.auth(s.metrics))
	mux.HandleFunc("GET /api/notificateur/alertes", s.notifier)
	mux.HandleFunc("GET /api/sauvegardes", s.auth(s.backups))
	mux.HandleFunc("POST /api/sauvegardes/tester", s.auth(s.restoreTest))
	mux.HandleFunc("POST /api/sauvegardes/copier", s.auth(s.copyNow))
	mux.HandleFunc("POST /api/sauvegardes/reglages", s.auth(s.backupSettings))
	mux.HandleFunc("POST /api/destinations", s.auth(s.addDestination))
	mux.HandleFunc("POST /api/destinations/{id}/retirer", s.auth(s.removeDestination))
	mux.HandleFunc("POST /api/affichage", s.auth(s.display))
	return s.guard(mux)
}

// guard : en-têtes de sécurité, nom d'hôte attendu, origine des requêtes d'écriture.
func (s *Server) guard(next http.Handler) http.Handler {
	hosts := map[string]bool{"127.0.0.1:" + strconv.Itoa(s.Port): true, "localhost:" + strconv.Itoa(s.Port): true}
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h := w.Header()
		h.Set("X-Content-Type-Options", "nosniff")
		h.Set("X-Frame-Options", "DENY")
		h.Set("Referrer-Policy", "no-referrer")
		h.Set("Cache-Control", "no-store")
		h.Set("Content-Security-Policy", "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'")
		if !hosts[r.Host] {
			http.Error(w, "nom d'hôte refusé", http.StatusForbidden)
			return
		}
		if r.Method != http.MethodGet {
			if o := r.Header.Get("Origin"); o != "" && !hosts[strings.TrimPrefix(strings.TrimPrefix(o, "http://"), "https://")] {
				http.Error(w, "origine refusée", http.StatusForbidden)
				return
			}
		}
		next.ServeHTTP(w, r)
	})
}

func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func fail(w http.ResponseWriter, code int, msg string) {
	writeJSON(w, code, map[string]any{"ok": false, "erreur": msg})
}

// ------------------------------------------------------------------------------------------ mot de passe

func hashPassword(pw string) string {
	salt := make([]byte, 16)
	_, _ = rand.Read(salt)
	key := argon2.IDKey([]byte(pw), salt, 3, 64*1024, 2, 32)
	return "argon2id$3$65536$2$" + base64.RawStdEncoding.EncodeToString(salt) + "$" + base64.RawStdEncoding.EncodeToString(key)
}

func checkPassword(stored, pw string) bool {
	p := strings.Split(stored, "$")
	if len(p) != 6 || p[0] != "argon2id" {
		return false
	}
	t, _ := strconv.Atoi(p[1])
	m, _ := strconv.Atoi(p[2])
	th, _ := strconv.Atoi(p[3])
	salt, err1 := base64.RawStdEncoding.DecodeString(p[4])
	want, err2 := base64.RawStdEncoding.DecodeString(p[5])
	if err1 != nil || err2 != nil || t < 1 || m < 1024 || th < 1 {
		return false
	}
	got := argon2.IDKey([]byte(pw), salt, uint32(t), uint32(m), uint8(th), uint32(len(want)))
	return subtle.ConstantTimeCompare(got, want) == 1
}

// SetPassword : mot de passe administrateur de la console (installateur, ou premier accès depuis ce PC).
func SetPassword(st *store.Store, pw string) error {
	if len([]rune(pw)) < 8 {
		return errors.New("mot de passe trop court : 8 caractères au moins")
	}
	return st.Set("admin_mdp", hashPassword(pw))
}

// ------------------------------------------------------------------------------------------ sessions

const cookieName = "guardian_session"

func (s *Server) current(r *http.Request) *session {
	c, err := r.Cookie(cookieName)
	if err != nil {
		return nil
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	ss := s.sessions[c.Value]
	if ss == nil || time.Now().After(ss.expires) {
		delete(s.sessions, c.Value)
		return nil
	}
	ss.expires = time.Now().Add(30 * time.Minute) // inactivité : 30 minutes
	return ss
}

func (s *Server) auth(h http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		ss := s.current(r)
		if ss == nil {
			fail(w, http.StatusUnauthorized, "connexion requise")
			return
		}
		if r.Method != http.MethodGet && subtle.ConstantTimeCompare([]byte(r.Header.Get("X-Guardian-CSRF")), []byte(ss.csrf)) != 1 {
			fail(w, http.StatusForbidden, "jeton de sécurité invalide : rechargez la page")
			return
		}
		h(w, r)
	}
}

func (s *Server) open(w http.ResponseWriter) string {
	id, csrf := randHex(32), randHex(16)
	s.mu.Lock()
	s.sessions[id] = &session{csrf: csrf, expires: time.Now().Add(30 * time.Minute)}
	s.mu.Unlock()
	http.SetCookie(w, &http.Cookie{Name: cookieName, Value: id, Path: "/", HttpOnly: true, SameSite: http.SameSiteStrictMode})
	return csrf
}

func readBody(r *http.Request, v any) error {
	return json.NewDecoder(http.MaxBytesReader(nil, r.Body, 1<<16)).Decode(v)
}

func (s *Server) sessionInfo(w http.ResponseWriter, r *http.Request) {
	ss := s.current(r)
	out := map[string]any{"ok": true, "connecte": ss != nil, "mot_de_passe_defini": s.A.S.Get1("admin_mdp") != "", "version": core.Version}
	if ss != nil {
		out["csrf"] = ss.csrf
		out["menu"] = menuPosition(s.A.Config().MenuPosition)
	}
	writeJSON(w, http.StatusOK, out)
}

// setup : premier mot de passe (seulement s'il n'en existe aucun ; l'installateur le définit normalement).
func (s *Server) setup(w http.ResponseWriter, r *http.Request) {
	var in struct {
		MotDePasse string `json:"mot_de_passe"`
	}
	if s.A.S.Get1("admin_mdp") != "" {
		fail(w, http.StatusForbidden, "un mot de passe existe déjà")
		return
	}
	if err := readBody(r, &in); err != nil {
		fail(w, http.StatusBadRequest, "requête invalide")
		return
	}
	if err := SetPassword(s.A.S, in.MotDePasse); err != nil {
		fail(w, http.StatusBadRequest, err.Error())
		return
	}
	_ = s.A.S.AddJournal("mot de passe défini", "console", "", time.Now())
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "csrf": s.open(w)})
}

func (s *Server) login(w http.ResponseWriter, r *http.Request) {
	s.mu.Lock()
	now := time.Now()
	recent := s.fails[:0]
	for _, t := range s.fails {
		if now.Sub(t) < 5*time.Minute {
			recent = append(recent, t)
		}
	}
	s.fails = recent
	blocked := len(recent) >= 5
	s.mu.Unlock()
	if blocked {
		fail(w, http.StatusTooManyRequests, "trop d'essais : patientez 5 minutes")
		return
	}
	var in struct {
		MotDePasse string `json:"mot_de_passe"`
	}
	if err := readBody(r, &in); err != nil {
		fail(w, http.StatusBadRequest, "requête invalide")
		return
	}
	stored := s.A.S.Get1("admin_mdp")
	if stored == "" || !checkPassword(stored, in.MotDePasse) {
		s.mu.Lock()
		s.fails = append(s.fails, now)
		s.mu.Unlock()
		_ = s.A.S.AddJournal("connexion refusée", "console", "mot de passe incorrect", now)
		fail(w, http.StatusUnauthorized, "mot de passe incorrect")
		return
	}
	_ = s.A.S.AddJournal("connexion", "console", "", now)
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "csrf": s.open(w)})
}

func (s *Server) logout(w http.ResponseWriter, r *http.Request) {
	if c, err := r.Cookie(cookieName); err == nil {
		s.mu.Lock()
		delete(s.sessions, c.Value)
		s.mu.Unlock()
	}
	http.SetCookie(w, &http.Cookie{Name: cookieName, Value: "", Path: "/", MaxAge: -1, HttpOnly: true, SameSite: http.SameSiteStrictMode})
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// ------------------------------------------------------------------------------------------ données

func (s *Server) health(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "produit": "MARWA GUARDIAN", "version": core.Version})
}

// Overall : état général du poste à partir des incidents ouverts.
func Overall(counts map[int]int) (string, string) {
	switch {
	case counts[store.Emergency] > 0:
		return "urgence", "Urgence"
	case counts[store.Critical] > 0:
		return "critique", "Critique"
	case counts[store.Warning] > 0:
		return "surveiller", "À surveiller"
	}
	return "bon", "Bon"
}

func (s *Server) state(w http.ResponseWriter, r *http.Request) {
	counts, err := s.A.S.CountOpen()
	if err != nil {
		fail(w, http.StatusInternalServerError, err.Error())
		return
	}
	code, label := Overall(counts)
	total := 0
	for _, n := range counts {
		total += n
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "etat": code, "etat_libelle": label, "alertes_ouvertes": total,
		"par_gravite": map[string]int{"information": counts[store.Info], "avertissement": counts[store.Warning], "critique": counts[store.Critical], "urgence": counts[store.Emergency]},
		"systeme":     s.A.Status(), "version": core.Version, "ordinateur": hostname()})
}

func hostname() string {
	h, _ := os.Hostname()
	return h
}

func (s *Server) incidents(w http.ResponseWriter, r *http.Request) {
	st := r.URL.Query().Get("statut")
	if st != "open" && st != "resolved" {
		st = ""
	}
	list, err := s.A.S.List(st, 300)
	if err != nil {
		fail(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "incidents": list})
}

func pathID(r *http.Request) int64 {
	n, _ := strconv.ParseInt(r.PathValue("id"), 10, 64)
	return n
}

func (s *Server) incident(w http.ResponseWriter, r *http.Request) {
	in, err := s.A.S.Get(pathID(r))
	if err != nil {
		fail(w, http.StatusNotFound, "incident introuvable")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "incident": in})
}

func (s *Server) resolve(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Note string `json:"note"`
	}
	_ = readBody(r, &in)
	note := strings.TrimSpace(in.Note)
	if len([]rune(note)) > 300 {
		note = string([]rune(note)[:300])
	}
	if err := s.A.S.Resolve(pathID(r), "administrateur", note, time.Now()); err != nil {
		fail(w, http.StatusBadRequest, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (s *Server) journal(w http.ResponseWriter, r *http.Request) {
	j, err := s.A.S.Journal(300)
	if err != nil {
		fail(w, http.StatusInternalServerError, err.Error())
		return
	}
	bad, _ := s.A.S.Verify()
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "journal": j, "intact": len(bad) == 0, "anomalies": bad})
}

func (s *Server) metrics(w http.ResponseWriter, r *http.Request) {
	name := r.URL.Query().Get("nom")
	if !strings.HasPrefix(name, "processeur") && !strings.HasPrefix(name, "memoire_libre") && !strings.HasPrefix(name, "disque_libre:") {
		fail(w, http.StatusBadRequest, "mesure inconnue")
		return
	}
	series, err := s.A.S.Series(name, time.Now().Add(-24*time.Hour))
	if err != nil {
		fail(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "serie": series})
}

// notifier : alertes à afficher (jeton du notificateur). Ne renvoie que ce qu'une notification affiche.
func (s *Server) notifier(w http.ResponseWriter, r *http.Request) {
	if subtle.ConstantTimeCompare([]byte(r.Header.Get("X-Guardian-Jeton")), []byte(s.notifTok)) != 1 {
		fail(w, http.StatusUnauthorized, "jeton invalide")
		return
	}
	list, err := s.A.S.Pending()
	if err != nil {
		fail(w, http.StatusInternalServerError, err.Error())
		return
	}
	type alert struct {
		ID       int64  `json:"id"`
		Severity int    `json:"gravite"`
		Label    string `json:"gravite_libelle"`
		Title    string `json:"titre"`
		Problem  string `json:"probleme"`
		Advice   string `json:"conseil"`
	}
	out := []alert{}
	for _, in := range list {
		advice := ""
		if len(in.Recommendations) > 0 {
			advice = in.Recommendations[0]
		}
		out = append(out, alert{in.ID, in.Severity, store.SeverityLabel(in.Severity), in.Title, in.Problem, advice})
		_ = s.A.S.MarkNotified(in.ID)
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "alertes": out, "console": "http://127.0.0.1:" + strconv.Itoa(s.Port) + "/"})
}

// ------------------------------------------------------------------------------------------ sauvegardes (phase 3)

func (s *Server) backups(w http.ResponseWriter, r *http.Request) {
	c := s.A.Config()
	vols := []map[string]any{}
	for _, v := range platform.Volumes() {
		if v.Label != "" {
			used := false
			for _, d := range c.Destinations {
				used = used || (d.Kind == "support" && strings.EqualFold(d.Label, v.Label))
			}
			vols = append(vols, map[string]any{"root": v.Root, "label": v.Label, "removable": v.Removable, "used": used})
		}
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "etat": s.A.Status().Backups, "destinations": c.Destinations, "volumes": vols,
		"reglages": map[string]any{"backup_warn_hours": c.BackupWarnHours, "backup_crit_hours": c.BackupCritHours, "restore_test_days": c.RestoreTestDays,
			"pos_app_dir": c.PosAppDir, "mariadb_bin": c.MariaDBBin}})
}

func (s *Server) restoreTest(w http.ResponseWriter, r *http.Request) {
	if err := s.A.StartRestoreTest(); err != nil {
		fail(w, http.StatusConflict, err.Error())
		return
	}
	_ = s.A.S.AddJournal("test de restauration demandé", "administrateur", "", time.Now())
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (s *Server) copyNow(w http.ResponseWriter, r *http.Request) {
	if len(s.A.Config().Destinations) == 0 {
		fail(w, http.StatusBadRequest, "ajoutez d'abord une destination")
		return
	}
	s.A.CheckCopies()
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// backupSettings : seuils d'alerte, fréquence du test, dossiers (vérifiés avant d'être enregistrés).
func (s *Server) backupSettings(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Warn    int    `json:"backup_warn_hours"`
		Crit    int    `json:"backup_crit_hours"`
		Days    int    `json:"restore_test_days"`
		AppDir  string `json:"pos_app_dir"`
		MariaDB string `json:"mariadb_bin"`
	}
	if err := readBody(r, &in); err != nil {
		fail(w, http.StatusBadRequest, "requête invalide")
		return
	}
	if in.Warn < 1 || in.Warn > 24*30 || in.Crit < in.Warn || in.Crit > 24*60 || in.Days < 0 || in.Days > 90 {
		fail(w, http.StatusBadRequest, "valeurs hors limites (alerte : 1 h à 30 jours ; critique : au moins l'alerte ; test : 0 à 90 jours)")
		return
	}
	for _, d := range []string{in.AppDir, in.MariaDB} {
		if d == "" {
			continue
		}
		if st, err := os.Stat(d); err != nil || !st.IsDir() {
			fail(w, http.StatusBadRequest, "dossier introuvable : "+d)
			return
		}
	}
	c := s.A.Config()
	c.BackupWarnHours, c.BackupCritHours, c.RestoreTestDays, c.PosAppDir, c.MariaDBBin = in.Warn, in.Crit, in.Days, strings.TrimSpace(in.AppDir), strings.TrimSpace(in.MariaDB)
	if err := s.A.SetConfig(c); err != nil {
		fail(w, http.StatusInternalServerError, err.Error())
		return
	}
	_ = s.A.S.AddJournal("réglages des sauvegardes", "administrateur", fmt.Sprintf("alerte %d h, critique %d h, test tous les %d j", in.Warn, in.Crit, in.Days), time.Now())
	go s.A.CheckBackups()
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (s *Server) addDestination(w http.ResponseWriter, r *http.Request) {
	var in core.Destination
	if err := readBody(r, &in); err != nil {
		fail(w, http.StatusBadRequest, "requête invalide")
		return
	}
	in.Path, in.Label = strings.TrimSpace(in.Path), strings.TrimSpace(in.Label)
	if in.Keep == 0 {
		in.Keep = 30
	}
	if in.Keep < 1 || in.Keep > 365 {
		fail(w, http.StatusBadRequest, "nombre de sauvegardes gardées : 1 à 365")
		return
	}
	switch in.Kind {
	case "support":
		found := false
		for _, v := range platform.Volumes() {
			if v.Label != "" && strings.EqualFold(v.Label, in.Label) {
				found = true
			}
		}
		if !found {
			fail(w, http.StatusBadRequest, "support introuvable : branchez-le, puis choisissez-le dans la liste")
			return
		}
		in.Path = ""
	case "dossier":
		if in.Path == "" || len(in.Path) > 240 || !filepath.IsAbs(in.Path) {
			fail(w, http.StatusBadRequest, "indiquez un dossier complet (ex. D:\\Sauvegardes ou \\\\serveur\\partage)")
			return
		}
		if st, err := os.Stat(in.Path); err != nil || !st.IsDir() {
			fail(w, http.StatusBadRequest, "dossier introuvable ou inaccessible : "+in.Path)
			return
		}
		clean := strings.ToLower(filepath.Clean(in.Path))
		for _, forbidden := range []string{s.A.Status().Backups.Dir, s.A.Dir} {
			f := strings.ToLower(filepath.Clean(forbidden))
			if forbidden != "" && (clean == f || strings.HasPrefix(clean, f+string(filepath.Separator)) || strings.HasPrefix(f, clean+string(filepath.Separator))) {
				fail(w, http.StatusBadRequest, "ce dossier contient ou se trouve dans les sauvegardes d'origine ou les données de MARWA GUARDIAN : choisissez un autre endroit")
				return
			}
		}
		in.Label = ""
	default:
		fail(w, http.StatusBadRequest, "type de destination inconnu")
		return
	}
	c := s.A.Config()
	for _, d := range c.Destinations {
		if d.Kind == in.Kind && strings.EqualFold(d.Path, in.Path) && strings.EqualFold(d.Label, in.Label) {
			fail(w, http.StatusBadRequest, "cette destination existe déjà")
			return
		}
	}
	if len(c.Destinations) >= 5 {
		fail(w, http.StatusBadRequest, "5 destinations au plus")
		return
	}
	in.ID = randHex(6)
	c.Destinations = append(c.Destinations, in)
	if err := s.A.SetConfig(c); err != nil {
		fail(w, http.StatusInternalServerError, err.Error())
		return
	}
	_ = s.A.S.AddJournal("destination de copie ajoutée", "administrateur", in.Name(), time.Now())
	s.A.CheckCopies()
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "id": in.ID})
}

func (s *Server) removeDestination(w http.ResponseWriter, r *http.Request) {
	c := s.A.Config()
	id := r.PathValue("id")
	var kept []core.Destination
	name := ""
	for _, d := range c.Destinations {
		if d.ID == id {
			name = d.Name()
			continue
		}
		kept = append(kept, d)
	}
	if name == "" {
		fail(w, http.StatusNotFound, "destination introuvable")
		return
	}
	c.Destinations = kept
	if err := s.A.SetConfig(c); err != nil {
		fail(w, http.StatusInternalServerError, err.Error())
		return
	}
	_ = s.A.S.AddJournal("destination de copie retirée", "administrateur", name+" (copies déjà faites conservées)", time.Now())
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func menuPosition(v string) string {
	if v == "gauche" {
		return "gauche"
	}
	return "droite"
}

// display : position du menu de la console (comme le réglage « Position du menu » de POS Marwa).
func (s *Server) display(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Menu string `json:"menu"`
	}
	if err := readBody(r, &in); err != nil || (in.Menu != "droite" && in.Menu != "gauche") {
		fail(w, http.StatusBadRequest, "position du menu : « droite » ou « gauche »")
		return
	}
	c := s.A.Config()
	c.MenuPosition = in.Menu
	if err := s.A.SetConfig(c); err != nil {
		fail(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "menu": in.Menu})
}
