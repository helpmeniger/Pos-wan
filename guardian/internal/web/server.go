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
