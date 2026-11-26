package core

import (
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"marwa/guardian/internal/platform"
	"marwa/guardian/internal/store"
)

func events(t *testing.T) []platform.Event {
	t.Helper()
	raw, err := os.ReadFile("testdata/System.xml")
	if err != nil {
		t.Fatal(err)
	}
	ev, err := platform.ParseEvents(raw)
	if err != nil {
		t.Fatal(err)
	}
	return ev
}

func TestParseEvents(t *testing.T) {
	ev := events(t)
	if len(ev) != 12 {
		t.Fatalf("12 événements attendus, %d", len(ev))
	}
	kp := ev[3]
	if kp.ID != 41 || kp.Provider != "Microsoft-Windows-Kernel-Power" || kp.Data["BugcheckCode"] != "0" || kp.Computer != "CAISSE-01" || kp.RecordID != 2041 {
		t.Fatalf("Kernel-Power 41 mal lu : %+v", kp)
	}
	if ev[4].ID != 6008 || len(ev[4].Values) < 2 || ev[4].Values[0] != "18:47:05" {
		t.Fatalf("6008 (données sans nom) mal lu : %+v", ev[4])
	}
}

func TestAnalyzeShutdowns(t *testing.T) {
	got := AnalyzeShutdowns(events(t))
	want := []struct {
		kind ShutdownKind
		conf string
	}{
		{KindPower, "probable"},    // 20/11 : 41 sans code ni bouton + 6008 → un seul arrêt
		{KindCrash, "certain"},     // 22/11 : écran bleu (1001, code 0x9f)
		{KindForced, "certain"},    // 24/11 : appui long sur le bouton
		{KindHardware, "probable"}, // 25/11 : erreur matérielle WHEA juste avant
		{KindUnknown, "possible"},  // 26/11 : 6008 seul
	}
	if len(got) != len(want) {
		for _, s := range got {
			t.Logf("%s %s %s", s.At, s.Kind, s.Confidence)
		}
		t.Fatalf("%d arrêts anormaux attendus (l'arrêt normal 1074/6006 exclu), %d", len(want), len(got))
	}
	for i, w := range want {
		if got[i].Kind != w.kind || got[i].Confidence != w.conf {
			t.Errorf("arrêt %d (%s) : %s/%s attendu, %s/%s obtenu", i, got[i].At, w.kind, w.conf, got[i].Kind, got[i].Confidence)
		}
	}
	if !strings.HasPrefix(got[1].Details["Code d'arrêt"], "0x0000009f") {
		t.Errorf("code d'arrêt de l'écran bleu : %v", got[1].Details)
	}
	title, problem, _, recos, _ := ShutdownIncident(got[0], 2)
	if !strings.Contains(title, "probable") || !strings.Contains(problem, "sans être certaine") || !strings.Contains(strings.Join(recos, " "), "onduleur") {
		t.Errorf("coupure : jamais présentée comme certaine, onduleur conseillé quand cela se répète : %s / %s", title, problem)
	}
}

func testAgent(t *testing.T) (*Agent, *store.Store) {
	t.Helper()
	dir := t.TempDir()
	s, err := store.Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { s.Close() })
	a := New(s, Defaults(), dir, log.New(io.Discard, "", 0))
	a.Now = func() time.Time { return time.Date(2026, 11, 26, 12, 0, 0, 0, time.UTC) }
	return a, s
}

func fixtures(t *testing.T, files map[string]string) string {
	t.Helper()
	d := t.TempDir()
	for n, c := range files {
		if err := os.WriteFile(filepath.Join(d, n), []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	t.Setenv("GUARDIAN_FIXTURES", d)
	return d
}

func open(s *store.Store, code string) []store.Incident {
	l, _ := s.List("open", 100)
	var out []store.Incident
	for _, in := range l {
		if strings.HasPrefix(in.Code, code) {
			out = append(out, in)
		}
	}
	return out
}

func TestCheckShutdownsAndDiskErrors(t *testing.T) {
	raw, _ := os.ReadFile("testdata/System.xml")
	fixtures(t, map[string]string{"System.xml": string(raw)})
	a, s := testAgent(t)
	a.CheckShutdowns()
	inc := open(s, "arret_")
	if len(inc) != 5 {
		t.Fatalf("5 incidents d'arrêt (7 derniers jours) attendus, %d", len(inc))
	}
	a.CheckShutdowns() // seconde analyse : rien de nouveau
	if n := len(open(s, "arret_")); n != 5 {
		t.Fatalf("aucun doublon attendu, %d", n)
	}
	if a.Status().Shutdowns30 != 5 {
		t.Fatalf("compteur 30 jours : %d", a.Status().Shutdowns30)
	}
	a.CheckDiskErrors()
	if d := open(s, "disque_erreurs"); len(d) != 1 || d[0].Severity != store.Critical {
		t.Fatalf("erreur de disque (événement 7) attendue : %+v", d)
	}
}

func TestServicesFixture(t *testing.T) {
	fixtures(t, map[string]string{"services.json": `{"EventLog":"running","Winmgmt":"running","mpssvc":"stopped","Dhcp":"running","Dnscache":"running"}`})
	a, s := testAgent(t)
	a.CheckServices()
	in := open(s, "service_arrete")
	if len(in) != 1 || in[0].Key != "mpssvc" || in[0].Severity != store.Critical {
		t.Fatalf("pare-feu arrêté : incident critique attendu : %+v", in)
	}
	// WinDefend absent (autre antivirus) : rien d'inventé
	for _, x := range a.Status().Services {
		if x.Name == "WinDefend" && x.Exists {
			t.Fatal("WinDefend absent ne doit pas apparaître comme présent")
		}
	}
	fixtures(t, map[string]string{"services.json": `{"EventLog":"running","Winmgmt":"running","mpssvc":"running","Dhcp":"running","Dnscache":"running"}`})
	a.CheckServices()
	if len(open(s, "service_arrete")) != 0 {
		t.Fatal("pare-feu relancé : incident résolu automatiquement")
	}
}

func TestPosBureau(t *testing.T) {
	// Faux POS Marwa : répartiteur (/__bureau/sante) et port de la base
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/__bureau/sante" {
			io.WriteString(w, "POS Marwa Bureau 3.17.1 pret=true inactif=3s")
		}
	}))
	defer app.Close()
	webPort, _ := strconv.Atoi(app.URL[strings.LastIndex(app.URL, ":")+1:])
	db, _ := net.Listen("tcp", "127.0.0.1:0")
	dbPort := db.Addr().(*net.TCPAddr).Port
	go func() {
		for {
			c, err := db.Accept()
			if err != nil {
				return
			}
			c.Close()
		}
	}()
	pos := t.TempDir()
	_ = os.MkdirAll(filepath.Join(pos, "donnees", "journaux"), 0o755)
	_ = os.WriteFile(filepath.Join(pos, "pos-marwa.ini"), []byte("[POS Marwa]\nPORT_WEB="+strconv.Itoa(webPort)+"\nPORT_BD=3407\n"), 0o644)
	_ = os.WriteFile(filepath.Join(pos, "donnees", "mariadb.ini"), []byte("[mysqld]\nport="+strconv.Itoa(dbPort)+"\n"), 0o644)
	fixtures(t, map[string]string{"registry.json": `{"SOFTWARE\\POSMarwaBureau\\Dossier": "` + strings.ReplaceAll(pos, `\`, `\\`) + `"}`})
	a, s := testAgent(t)
	a.CheckPos()
	p := a.Status().Pos
	if p.Kind != "bureau" || !p.Running || p.AppOK == nil || !*p.AppOK || p.DBOK == nil || !*p.DBOK || p.DBPort != dbPort {
		t.Fatalf("POS Marwa Bureau détecté et fonctionnel attendu (port de la base lu dans mariadb.ini) : %+v", p)
	}
	// Base arrêtée pendant que POS Marwa tourne
	db.Close()
	a.CheckPos()
	in := open(s, "base_arretee")
	if len(in) != 1 || in[0].Severity != store.Critical || !strings.Contains(in[0].Consequences, "enregistrer les ventes") {
		t.Fatalf("incident « Base de données arrêtée » attendu : %+v", in)
	}
	// Base revenue : résolution automatique
	db2, err := net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(dbPort))
	if err != nil {
		t.Skip("port repris par un autre programme : " + err.Error())
	}
	defer db2.Close()
	a.CheckPos()
	if len(open(s, "base_arretee")) != 0 {
		t.Fatal("base revenue : incident résolu automatiquement")
	}
	// Journal MariaDB : récupération après arrêt brutal, puis corruption
	_ = os.WriteFile(filepath.Join(pos, "donnees", "journaux", "mariadb.err"), []byte(
		"2026-11-20  7:00:00 0 [Note] InnoDB: ancienne ligne\n"+
			"2026-11-20 8:05:01 0 [Note] InnoDB: Starting crash recovery from checkpoint LSN=123\n"+
			"2026-11-20 8:05:02 0 [ERROR] InnoDB: Page [page id: space=5, page number=3] log sequence number is in the future; database may be corrupt\n"), 0o644)
	note := a.posRecoveryNote(time.Date(2026, 11, 20, 8, 0, 0, 0, time.Local))
	if !strings.Contains(note, "récupération automatique") || !strings.Contains(note, "corruption") {
		t.Fatalf("journal MariaDB : %q", note)
	}
}

func TestPosAbsentAndClosed(t *testing.T) {
	fixtures(t, map[string]string{})
	a, s := testAgent(t)
	a.CheckPos()
	if a.Status().Pos.Kind != "absent" {
		t.Fatalf("aucune installation : « absent » attendu, %+v", a.Status().Pos)
	}
	// Installation Bureau présente mais fermée : aucun incident (fermé hors des heures d'ouverture)
	pos := t.TempDir()
	_ = os.WriteFile(filepath.Join(pos, "pos-marwa.ini"), []byte("PORT_WEB=1\n"), 0o644) // port invalide : valeur par défaut
	a.Cfg.PosDir = pos
	a.CheckPos()
	p := a.Status().Pos
	if p.Kind != "bureau" || p.WebPort != 8740 || len(open(s, "")) != 0 {
		t.Fatalf("Bureau fermé : aucun incident, port par défaut : %+v", p)
	}
}

func TestSystemDiskThreshold(t *testing.T) {
	a, s := testAgent(t)
	a.Cfg.DiskWarnPercent, a.Cfg.DiskCritPercent, a.Cfg.DiskCritBytes = 101, 0, 0 // tout disque est « presque plein »
	a.Cfg.MonitorPerformance = false
	a.CheckSystem()
	if d := open(s, "disque_plein"); len(d) == 0 || d[0].Severity != store.Warning {
		t.Fatalf("seuil d'avertissement : %+v", d)
	}
	a.Cfg.DiskWarnPercent = 0
	a.CheckSystem()
	if len(open(s, "disque_plein")) != 0 {
		t.Fatal("seuil revenu : résolution automatique attendue")
	}
}

func TestIntegrityIncident(t *testing.T) {
	a, s := testAgent(t)
	_, _, _ = s.Raise(store.Incident{Code: "x", Severity: store.Info, Title: "t", Problem: "p"}, a.Now())
	a.CheckIntegrity()
	if st := a.Status(); st.LogIntact == nil || !*st.LogIntact {
		t.Fatal("intact attendu")
	}
	_ = s.ExecForTest("UPDATE incidents SET title = 'falsifié'")
	a.CheckIntegrity()
	if len(open(s, "journal_modifie")) != 1 {
		t.Fatal("falsification signalée par un incident critique")
	}
}
