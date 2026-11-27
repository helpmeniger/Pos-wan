package web

import (
	"encoding/json"
	"io"
	"log"
	"net/http"
	"net/http/cookiejar"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"

	"marwa/guardian/internal/core"
	"marwa/guardian/internal/store"
)

type client struct {
	t    *testing.T
	base string
	host string
	hc   *http.Client
	csrf string
}

func (c *client) do(method, path, body string, hdr map[string]string) (int, map[string]any) {
	req, _ := http.NewRequest(method, c.base+path, strings.NewReader(body))
	req.Host = c.host
	if body != "" {
		req.Header.Set("Content-Type", "application/json")
	}
	if c.csrf != "" && method != http.MethodGet {
		req.Header.Set("X-Guardian-CSRF", c.csrf)
	}
	for k, v := range hdr {
		req.Header.Set(k, v)
	}
	r, err := c.hc.Do(req)
	if err != nil {
		c.t.Fatal(err)
	}
	defer r.Body.Close()
	var d map[string]any
	b, _ := io.ReadAll(r.Body)
	_ = json.Unmarshal(b, &d)
	return r.StatusCode, d
}

func setup(t *testing.T) (*client, *store.Store, *Server) {
	t.Helper()
	dir := t.TempDir()
	s, err := store.Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { s.Close() })
	a := core.New(s, core.Defaults(), dir, log.New(io.Discard, "", 0))
	srv, err := New(a, 8750)
	if err != nil {
		t.Fatal(err)
	}
	ts := httptest.NewServer(srv.Handler())
	t.Cleanup(ts.Close)
	jar, _ := cookiejar.New(nil)
	return &client{t: t, base: ts.URL, host: "127.0.0.1:8750", hc: &http.Client{Jar: jar, Timeout: 10 * time.Second}}, s, srv
}

func TestHostAndAuth(t *testing.T) {
	c, _, _ := setup(t)
	// Nom d'hôte étranger (DNS rebinding) refusé
	bad := *c
	bad.host = "evil.example:8750"
	if code, _ := bad.do("GET", "/api/sante", "", nil); code != http.StatusForbidden {
		t.Fatalf("hôte étranger : 403 attendu, %d", code)
	}
	if code, d := c.do("GET", "/api/sante", "", nil); code != 200 || d["produit"] != "MARWA GUARDIAN" {
		t.Fatalf("santé : %d %v", code, d)
	}
	for _, p := range []string{"/api/etat", "/api/incidents", "/api/journal"} {
		if code, _ := c.do("GET", p, "", nil); code != http.StatusUnauthorized {
			t.Fatalf("%s sans connexion : 401 attendu, %d", p, code)
		}
	}
	// Origine étrangère refusée pour une écriture
	if code, _ := c.do("POST", "/api/connexion", `{"mot_de_passe":"x"}`, map[string]string{"Origin": "http://evil.example"}); code != http.StatusForbidden {
		t.Fatalf("origine étrangère : 403 attendu, %d", code)
	}
}

func TestPasswordLoginCSRFResolve(t *testing.T) {
	c, s, _ := setup(t)
	if code, d := c.do("POST", "/api/premier-mot-de-passe", `{"mot_de_passe":"court"}`, nil); code != 400 || !strings.Contains(d["erreur"].(string), "8 caractères") {
		t.Fatalf("mot de passe trop court : %d %v", code, d)
	}
	code, d := c.do("POST", "/api/premier-mot-de-passe", `{"mot_de_passe":"Caisse-Securisee-2026"}`, nil)
	if code != 200 || d["csrf"] == nil {
		t.Fatalf("premier mot de passe : %d %v", code, d)
	}
	if stored := s.Get1("admin_mdp"); !strings.HasPrefix(stored, "argon2id$") || strings.Contains(stored, "Caisse") {
		t.Fatalf("mot de passe haché (Argon2id) attendu : %q", stored)
	}
	other := &client{t: t, base: c.base, host: c.host, hc: &http.Client{}}
	if code, _ := other.do("POST", "/api/premier-mot-de-passe", `{"mot_de_passe":"AutreMotDePasse99"}`, nil); code != http.StatusForbidden {
		t.Fatalf("second « premier mot de passe » : 403 attendu, %d", code)
	}
	c.csrf = d["csrf"].(string)
	in, _, _ := s.Raise(store.Incident{Code: "disque_plein", Key: "C:", Severity: store.Critical, Title: "Disque presque plein", Problem: "2 Go libres"}, time.Now())
	// Action sans jeton anti-falsification : refusée
	saved := c.csrf
	c.csrf = ""
	if code, _ := c.do("POST", "/api/incidents/"+strconv.FormatInt(in.ID, 10)+"/resoudre", `{}`, nil); code != http.StatusForbidden {
		t.Fatalf("sans jeton CSRF : 403 attendu, %d", code)
	}
	c.csrf = saved
	code, d = c.do("GET", "/api/etat", "", nil)
	if code != 200 || d["etat"] != "critique" || d["alertes_ouvertes"].(float64) != 1 {
		t.Fatalf("état « critique » avec 1 alerte attendu : %v", d)
	}
	if code, _ := c.do("POST", "/api/incidents/"+strconv.FormatInt(in.ID, 10)+"/resoudre", `{"note":"Disque nettoyé"}`, nil); code != 200 {
		t.Fatalf("résolution : %d", code)
	}
	got, _ := s.Get(in.ID)
	if got.Status != "resolved" || got.ResolvedBy != "administrateur" {
		t.Fatalf("incident résolu par l'administrateur : %+v", got)
	}
	_, d = c.do("GET", "/api/etat", "", nil)
	if d["etat"] != "bon" {
		t.Fatalf("état « bon » attendu après résolution : %v", d["etat"])
	}
	_, j := c.do("GET", "/api/journal", "", nil)
	if j["intact"] != true {
		t.Fatalf("journal intact attendu : %v", j)
	}
	// Déconnexion
	c.do("POST", "/api/deconnexion", "", nil)
	if code, _ := c.do("GET", "/api/etat", "", nil); code != http.StatusUnauthorized {
		t.Fatal("après déconnexion : 401 attendu")
	}
}

func TestLoginRateLimit(t *testing.T) {
	c, s, _ := setup(t)
	_ = SetPassword(s, "Caisse-Securisee-2026")
	for i := 0; i < 5; i++ {
		if code, _ := c.do("POST", "/api/connexion", `{"mot_de_passe":"faux-mot-de-passe"}`, nil); code != http.StatusUnauthorized {
			t.Fatalf("essai %d : 401 attendu, %d", i+1, code)
		}
	}
	if code, _ := c.do("POST", "/api/connexion", `{"mot_de_passe":"Caisse-Securisee-2026"}`, nil); code != http.StatusTooManyRequests {
		t.Fatalf("6e essai, même correct : 429 attendu, %d", code)
	}
	j, _ := s.Journal(20)
	n := 0
	for _, e := range j {
		if e.Action == "connexion refusée" {
			n++
		}
	}
	if n != 5 {
		t.Fatalf("5 refus inscrits au journal attendus, %d", n)
	}
}

func TestNotifierToken(t *testing.T) {
	c, s, srv := setup(t)
	b, err := os.ReadFile(NotifierTokenFile(srv.A.Dir))
	if err != nil {
		t.Fatal(err)
	}
	port, tok, _ := strings.Cut(string(b), "\n")
	if port != "8750" || len(tok) != 64 {
		t.Fatalf("fichier du notificateur : %q", string(b))
	}
	if code, _ := c.do("GET", "/api/notificateur/alertes", "", map[string]string{"X-Guardian-Jeton": "faux"}); code != http.StatusUnauthorized {
		t.Fatalf("jeton faux : 401 attendu, %d", code)
	}
	_, _, _ = s.Raise(store.Incident{Code: "base_arretee", Severity: store.Critical, Title: "Base de données arrêtée", Problem: "La base ne répond pas.", Recommendations: []string{"Relancez POS Marwa."}}, time.Now())
	code, d := c.do("GET", "/api/notificateur/alertes", "", map[string]string{"X-Guardian-Jeton": tok})
	al := d["alertes"].([]any)
	if code != 200 || len(al) != 1 || al[0].(map[string]any)["conseil"] != "Relancez POS Marwa." {
		t.Fatalf("alerte à notifier : %d %v", code, d)
	}
	// Le jeton du notificateur ne donne accès à rien d'autre
	if code, _ := c.do("GET", "/api/incidents", "", map[string]string{"X-Guardian-Jeton": tok}); code != http.StatusUnauthorized {
		t.Fatalf("jeton du notificateur sur la console : 401 attendu, %d", code)
	}
	_, d = c.do("GET", "/api/notificateur/alertes", "", map[string]string{"X-Guardian-Jeton": tok})
	if len(d["alertes"].([]any)) != 0 {
		t.Fatal("une alerte n'est notifiée qu'une fois")
	}
}

func TestPasswordHash(t *testing.T) {
	h := hashPassword("MotDePasse-123")
	if !checkPassword(h, "MotDePasse-123") || checkPassword(h, "motdepasse-123") || checkPassword("argon2id$1$1$1$x$y", "a") {
		t.Fatal("vérification du mot de passe")
	}
	if hashPassword("MotDePasse-123") == h {
		t.Fatal("sel aléatoire attendu")
	}
}

func TestBackupEndpoints(t *testing.T) {
	c, s, srv := setup(t)
	_ = SetPassword(s, "Caisse-Securisee-2026")
	_, d := c.do("POST", "/api/connexion", `{"mot_de_passe":"Caisse-Securisee-2026"}`, nil)
	c.csrf = d["csrf"].(string)
	usb := t.TempDir()
	fix := t.TempDir()
	_ = os.WriteFile(fix+"/volumes.json", []byte(`[{"root": "`+usb+`", "label": "CLE-CAISSE", "removable": true}]`), 0o644)
	t.Setenv("GUARDIAN_FIXTURES", fix)
	if code, d := c.do("GET", "/api/sauvegardes", "", nil); code != 200 || len(d["volumes"].([]any)) != 1 {
		t.Fatalf("supports branchés listés : %d %v", code, d)
	}
	if _, d := c.do("GET", "/api/sauvegardes", "", nil); d["volumes"].([]any)[0].(map[string]any)["used"] != false {
		t.Fatalf("support pas encore utilisé : %v", d["volumes"])
	}
	// Position du menu : à droite par défaut (comme POS Marwa), modifiable, valeur contrôlée
	if _, d := c.do("GET", "/api/session", "", nil); d["menu"] != "droite" {
		t.Fatalf("menu à droite par défaut : %v", d)
	}
	if code, _ := c.do("POST", "/api/affichage", `{"menu":"milieu"}`, nil); code != 400 {
		t.Fatalf("position inconnue refusée : %d", code)
	}
	if code, _ := c.do("POST", "/api/affichage", `{"menu":"gauche"}`, nil); code != 200 {
		t.Fatalf("menu à gauche : %d", code)
	}
	if _, d := c.do("GET", "/api/session", "", nil); d["menu"] != "gauche" {
		t.Fatalf("menu à gauche enregistré : %v", d)
	}
	if code, d := c.do("POST", "/api/sauvegardes/tester", "", nil); code != http.StatusConflict || !strings.Contains(d["erreur"].(string), "aucune sauvegarde vérifiée") {
		t.Fatalf("test sans sauvegarde : %d %v", code, d)
	}
	cases := []struct{ body, want string }{
		{`{"kind":"support","label":"CLE-INCONNUE"}`, "branchez-le"},
		{`{"kind":"dossier","path":"relatif"}`, "dossier complet"},
		{`{"kind":"dossier","path":"/n/existe/pas"}`, "introuvable"},
		{`{"kind":"dossier","path":"` + srv.A.Dir + `"}`, "données de MARWA GUARDIAN"},
		{`{"kind":"dossier","path":"` + usb + `","keep":0}`, ""},
		{`{"kind":"autre"}`, "inconnu"},
	}
	for _, cc := range cases {
		code, d := c.do("POST", "/api/destinations", cc.body, nil)
		if cc.want == "" {
			if code != 200 {
				t.Fatalf("%s : accepté attendu, %d %v", cc.body, code, d)
			}
			continue
		}
		if code != 400 || !strings.Contains(d["erreur"].(string), cc.want) {
			t.Fatalf("%s : refus « %s » attendu, %d %v", cc.body, cc.want, code, d)
		}
	}
	if code, _ := c.do("POST", "/api/destinations", `{"kind":"support","label":"cle-caisse","keep":10}`, nil); code != 200 {
		t.Fatalf("support branché accepté (nom sans tenir compte des majuscules) : %d", code)
	}
	if _, d := c.do("GET", "/api/sauvegardes", "", nil); d["volumes"].([]any)[0].(map[string]any)["used"] != true {
		t.Fatalf("support marqué comme utilisé : %v", d["volumes"])
	}
	if code, d := c.do("POST", "/api/destinations", `{"kind":"dossier","path":"`+usb+`"}`, nil); code != 400 || !strings.Contains(d["erreur"].(string), "existe déjà") {
		t.Fatalf("doublon refusé : %d %v", code, d)
	}
	dests := srv.A.Config().Destinations
	if len(dests) != 2 || dests[0].Keep != 30 {
		t.Fatalf("destinations enregistrées (30 gardées par défaut) : %+v", dests)
	}
	if b, _ := os.ReadFile(srv.A.Dir + "/guardian.json"); !strings.Contains(string(b), "CLE-CAISSE") && !strings.Contains(string(b), "cle-caisse") {
		t.Fatal("destinations écrites dans guardian.json")
	}
	if code, _ := c.do("POST", "/api/destinations/"+dests[0].ID+"/retirer", "", nil); code != 200 || len(srv.A.Config().Destinations) != 1 {
		t.Fatal("destination retirée")
	}
	if code, d := c.do("POST", "/api/sauvegardes/reglages", `{"backup_warn_hours":48,"backup_crit_hours":24,"restore_test_days":7}`, nil); code != 400 || !strings.Contains(d["erreur"].(string), "hors limites") {
		t.Fatalf("seuil critique inférieur à l'alerte refusé : %d %v", code, d)
	}
	if code, _ := c.do("POST", "/api/sauvegardes/reglages", `{"backup_warn_hours":30,"backup_crit_hours":96,"restore_test_days":14}`, nil); code != 200 || srv.A.Config().BackupCritHours != 96 {
		t.Fatal("réglages enregistrés")
	}
	// Sans jeton anti-falsification : refusé
	c.csrf = ""
	if code, _ := c.do("POST", "/api/destinations", `{"kind":"dossier","path":"`+usb+`"}`, nil); code != http.StatusForbidden {
		t.Fatal("action sans jeton refusée")
	}
}
