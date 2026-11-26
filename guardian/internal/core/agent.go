package core

import (
	"context"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"runtime/debug"
	"strconv"
	"strings"
	"sync"
	"time"

	"marwa/guardian/internal/platform"
	"marwa/guardian/internal/store"
)

// Version de l'agent (fixée à la construction).
var Version = "dev"

// Agent : boucle de surveillance. Chaque contrôle s'exécute à son rythme ; une erreur dans un contrôle est journalisée
// et n'arrête jamais les autres.
type Agent struct {
	S    *store.Store
	Cfg  Config
	Dir  string
	Log  *log.Logger
	Now  func() time.Time
	Http *http.Client

	mu      sync.Mutex
	status  Status
	cpuHist []float64
	cfgMu   sync.RWMutex
	busy    sync.Map // tâches longues en cours (test de restauration, copies)
}

// cfg : réglages courants (modifiables depuis la console pendant que l'agent tourne).
func (a *Agent) cfg() Config {
	a.cfgMu.RLock()
	defer a.cfgMu.RUnlock()
	return a.Cfg
}

// SetConfig : nouveaux réglages, enregistrés puis appliqués.
func (a *Agent) SetConfig(c Config) error {
	if err := SaveConfig(a.Dir, c); err != nil {
		return err
	}
	a.cfgMu.Lock()
	a.Cfg = c
	a.cfgMu.Unlock()
	return nil
}

func (a *Agent) Config() Config { return a.cfg() }

// Status : état courant affiché par la console (dernières valeurs mesurées, jamais inventées).
type Status struct {
	UpdatedAt   time.Time          `json:"updated_at"`
	Boot        time.Time          `json:"boot"`
	CPU         *float64           `json:"cpu"`
	Memory      *platform.Memory   `json:"memory"`
	Disks       []platform.Disk    `json:"disks"`
	Pos         PosStatus          `json:"pos"`
	Services    []platform.Service `json:"services"`
	Shutdowns30 int                `json:"shutdowns_30d"`
	LogIntact   *bool              `json:"log_intact"`
	Backups     BackupStatus       `json:"backups"`
	Errors      map[string]string  `json:"errors"` // contrôle → erreur de lecture (honnêteté : « non vérifié »)
}

// PosStatus : POS Marwa vu par l'agent.
type PosStatus struct {
	Kind      string    `json:"kind"` // bureau, wamp, absent
	Dir       string    `json:"dir"`
	Running   bool      `json:"running"` // programme ou services en marche
	AppOK     *bool     `json:"app_ok"`  // application qui répond (nil : non vérifiable)
	DBOK      *bool     `json:"db_ok"`   // base qui répond
	WebPort   int       `json:"web_port"`
	DBPort    int       `json:"db_port"`
	Detail    string    `json:"detail"`
	CheckedAt time.Time `json:"checked_at"`
}

func New(s *store.Store, cfg Config, dir string, lg *log.Logger) *Agent {
	return &Agent{S: s, Cfg: cfg, Dir: dir, Log: lg, Now: time.Now, Http: &http.Client{Timeout: 8 * time.Second},
		status: Status{Errors: map[string]string{}}}
}

func (a *Agent) Status() Status {
	a.mu.Lock()
	defer a.mu.Unlock()
	st := a.status
	st.Errors = map[string]string{}
	for k, v := range a.status.Errors {
		st.Errors[k] = v
	}
	return st
}

func (a *Agent) set(f func(*Status)) {
	a.mu.Lock()
	f(&a.status)
	a.status.UpdatedAt = a.Now()
	a.mu.Unlock()
}

func (a *Agent) fail(check string, err error) {
	a.Log.Printf("contrôle %s : %v", check, err)
	a.set(func(s *Status) { s.Errors[check] = err.Error() })
}

func (a *Agent) ok(check string) { a.set(func(s *Status) { delete(s.Errors, check) }) }

// raise : enregistre l'incident (ou le met à jour s'il est déjà ouvert).
func (a *Agent) raise(in store.Incident) {
	got, isNew, err := a.S.Raise(in, a.Now())
	if err != nil {
		a.Log.Printf("incident %s : %v", in.Code, err)
		return
	}
	if isNew {
		a.Log.Printf("INCIDENT [%s] %s — %s", store.SeverityLabel(got.Severity), got.Title, got.Problem)
	}
}

func (a *Agent) clear(code, key, note string) {
	if done, err := a.S.ResolveOpen(code, key, note, a.Now()); err != nil {
		a.Log.Printf("résolution %s : %v", code, err)
	} else if done {
		a.Log.Printf("Résolu automatiquement : %s %s (%s)", code, key, note)
	}
}

// Run : boucle principale (jusqu'à l'annulation du contexte).
func (a *Agent) Run(ctx context.Context) {
	type job struct {
		name  string
		every time.Duration
		fn    func()
		next  time.Time
	}
	jobs := []*job{
		{name: "intégrité du journal", every: time.Hour, fn: a.CheckIntegrity},
		{name: "arrêts anormaux", every: 10 * time.Minute, fn: a.CheckShutdowns},
		{name: "POS Marwa", every: 30 * time.Second, fn: a.CheckPos},
		{name: "système", every: time.Minute, fn: a.CheckSystem},
		{name: "erreurs disque", every: 15 * time.Minute, fn: a.CheckDiskErrors},
		{name: "services Windows", every: 5 * time.Minute, fn: a.CheckServices},
		{name: "redémarrages", every: 30 * time.Minute, fn: a.CheckReboots},
		{name: "sauvegardes", every: 30 * time.Minute, fn: a.CheckBackups},
		{name: "copies des sauvegardes", every: 30 * time.Minute, fn: a.CheckCopies},
		{name: "test de restauration", every: 6 * time.Hour, fn: a.CheckRestoreSchedule},
		{name: "nettoyage", every: 24 * time.Hour, fn: func() { _ = a.S.PruneMetrics(a.Now()) }},
	}
	for {
		now := a.Now()
		for _, j := range jobs {
			if now.Before(j.next) {
				continue
			}
			j.next = now.Add(j.every)
			func() {
				defer func() {
					if r := recover(); r != nil {
						a.Log.Printf("contrôle %s : erreur interne %v\n%s", j.name, r, debug.Stack())
					}
				}()
				j.fn()
			}()
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(5 * time.Second):
		}
	}
}

// ------------------------------------------------------------------------------------------------ intégrité

func (a *Agent) CheckIntegrity() {
	bad, err := a.S.Verify()
	if err != nil {
		a.fail("integrite", err)
		return
	}
	a.ok("integrite")
	okv := len(bad) == 0
	a.set(func(s *Status) { s.LogIntact = &okv })
	if okv {
		return
	}
	a.raise(store.Incident{Code: "journal_modifie", Severity: store.Critical, Title: "Historique de MARWA GUARDIAN modifié",
		Problem:         "L'historique des incidents a été modifié ou des entrées ont été supprimées en dehors de MARWA GUARDIAN.",
		Consequences:    "Des incidents passés peuvent avoir été effacés ou falsifiés.",
		Recommendations: []string{"Vérifiez qui a accès au compte administrateur de cet ordinateur.", "Conservez le rapport de diagnostic et prévenez votre responsable informatique."},
		Details:         map[string]string{"Anomalies": strings.Join(bad, " ; ")}, Confidence: "certain"})
}

// ------------------------------------------------------------------------------------------------ système

func pct(free, total uint64) float64 {
	if total == 0 {
		return 100
	}
	return 100 * float64(free) / float64(total)
}

func gb(b uint64) string {
	return strings.Replace(strconv.FormatFloat(float64(b)/(1<<30), 'f', 1, 64), ".", ",", 1) + " Go"
}

func (a *Agent) CheckSystem() {
	now := a.Now()
	a.set(func(s *Status) { s.Boot = platform.BootTime() })
	// Disques
	disks, err := platform.Disks()
	if err != nil {
		a.fail("disques", err)
	} else {
		a.ok("disques")
		a.set(func(s *Status) { s.Disks = disks })
		for _, d := range disks {
			p := pct(d.Free, d.Total)
			_ = a.S.Metric("disque_libre:"+d.Mount, p, now)
			sev := 0
			switch {
			case p < a.cfg().DiskCritPercent || d.Free < a.cfg().DiskCritBytes:
				sev = store.Critical
			case p < a.cfg().DiskWarnPercent:
				sev = store.Warning
			}
			if sev == 0 {
				a.clear("disque_plein", d.Mount, fmt.Sprintf("%.0f %% libre", p))
				continue
			}
			what := "Le disque " + d.Mount
			if d.System {
				what = "Le disque système (" + d.Mount + ")"
			}
			a.raise(store.Incident{Code: "disque_plein", Key: d.Mount, Severity: sev, Title: "Disque presque plein : " + d.Mount,
				Problem:      fmt.Sprintf("%s n'a plus que %s libres (%.0f %%).", what, gb(d.Free), p),
				Consequences: "Sans espace libre, POS Marwa ne peut plus enregistrer les ventes ni faire de sauvegarde, et Windows devient instable.",
				Recommendations: []string{"Videz la corbeille et supprimez les fichiers inutiles (téléchargements, vidéos).",
					"Déplacez les anciennes sauvegardes sur un support externe.", "Si le disque reste plein : ajoutez un disque adapté ou remplacez-le par un plus grand."},
				Details: map[string]string{"Libre": gb(d.Free), "Total": gb(d.Total)}, Confidence: "certain"})
		}
	}
	if !a.cfg().MonitorPerformance {
		return
	}
	// Mémoire
	m, err := platform.ReadMemory()
	if err != nil || m.Total == 0 {
		a.fail("memoire", fmt.Errorf("mémoire illisible : %v", err))
	} else {
		a.ok("memoire")
		a.set(func(s *Status) { mm := m; s.Memory = &mm })
		p := pct(m.Available, m.Total)
		_ = a.S.Metric("memoire_libre", p, now)
		sev := 0
		switch {
		case p < a.cfg().MemCritPercent:
			sev = store.Critical
		case p < a.cfg().MemWarnPercent:
			sev = store.Warning
		}
		if sev == 0 {
			a.clear("memoire_faible", "", fmt.Sprintf("%.0f %% disponible", p))
		} else {
			a.raise(store.Incident{Code: "memoire_faible", Severity: sev, Title: "Mémoire insuffisante",
				Problem:      fmt.Sprintf("Il ne reste que %s de mémoire disponible sur %s (%.0f %%).", gb(m.Available), gb(m.Total), p),
				Consequences: "La caisse peut devenir très lente ou se fermer d'elle-même.",
				Recommendations: []string{"Fermez les programmes et onglets inutiles pendant les ventes.",
					"Si cela se répète, ajoutez de la mémoire (8 Go conseillés pour POS Marwa et un navigateur)."},
				Details: map[string]string{"Disponible": gb(m.Available), "Total": gb(m.Total)}, Confidence: "certain"})
		}
	}
	// Processeur : moyenne des 5 dernières mesures (un pic isolé n'est pas un problème)
	c, err := platform.CPUPercent(2 * time.Second)
	if err != nil {
		a.fail("processeur", err)
		return
	}
	a.ok("processeur")
	_ = a.S.Metric("processeur", c, now)
	a.mu.Lock()
	a.cpuHist = append(a.cpuHist, c)
	if len(a.cpuHist) > 5 {
		a.cpuHist = a.cpuHist[len(a.cpuHist)-5:]
	}
	hist := append([]float64(nil), a.cpuHist...)
	a.mu.Unlock()
	a.set(func(s *Status) { cc := c; s.CPU = &cc })
	if len(hist) < 5 {
		return
	}
	avg := 0.0
	for _, v := range hist {
		avg += v
	}
	avg /= float64(len(hist))
	if avg < a.cfg().CPUWarnPercent {
		a.clear("processeur_sature", "", fmt.Sprintf("%.0f %% en moyenne", avg))
		return
	}
	a.raise(store.Incident{Code: "processeur_sature", Severity: store.Warning, Title: "Processeur saturé",
		Problem:         fmt.Sprintf("Le processeur est utilisé à %.0f %% en moyenne depuis environ 5 minutes.", avg),
		Consequences:    "La caisse et l'impression des tickets peuvent ralentir fortement.",
		Recommendations: []string{"Ouvrez le Gestionnaire des tâches (Ctrl + Maj + Échap) pour voir quel programme utilise le processeur.", "Évitez les mises à jour et analyses lourdes pendant les heures de vente."},
		Details:         map[string]string{"Moyenne": fmt.Sprintf("%.0f %%", avg)}, Confidence: "certain"})
}

// ------------------------------------------------------------------------------------------------ arrêts anormaux

func (a *Agent) CheckShutdowns() {
	now := a.Now()
	// Première analyse : les 30 derniers jours, mais seuls les 7 derniers deviennent des incidents à notifier
	since := now.Add(-30 * 24 * time.Hour)
	if v := a.S.Get1("arrets_depuis"); v != "" {
		if t, err := time.Parse(time.RFC3339, v); err == nil {
			since = t
		}
	}
	first := a.S.Get1("arrets_depuis") == ""
	evs, err := platform.Events("System", ShutdownEventIDs, since.Add(-20*time.Minute), 2000)
	if err != nil {
		a.fail("arrets", err)
		return
	}
	a.ok("arrets")
	for _, s := range AnalyzeShutdowns(evs) {
		if !s.At.After(since) || (first && s.At.Before(now.Add(-7*24*time.Hour))) {
			continue
		}
		recent, _ := a.S.CountSince("arret_%", s.At.Add(-30*24*time.Hour))
		title, problem, cons, recos, sev := ShutdownIncident(s, recent+1)
		det := map[string]string{"Événements": strings.Join(s.Evidence, " | "), "Nature": string(s.Kind), "Niveau de certitude": s.Confidence}
		for k, v := range s.Details {
			det[k] = v
		}
		// Après un arrêt brutal : état de la base de POS Marwa au redémarrage
		if note := a.posRecoveryNote(s.At); note != "" {
			det["Base de données au redémarrage"] = note
		}
		a.raise(store.Incident{Code: "arret_" + string(s.Kind), Key: s.At.UTC().Format(time.RFC3339), Severity: sev, Title: title, Problem: problem,
			Consequences: cons, Recommendations: recos, Details: det, Confidence: s.Confidence})
	}
	n, _ := a.S.CountSince("arret_%", now.Add(-30*24*time.Hour))
	a.set(func(st *Status) { st.Shutdowns30 = n })
	_ = a.S.Set("arrets_depuis", now.UTC().Format(time.RFC3339))
}

// posRecoveryNote : ce que le journal de MariaDB de POS Marwa (édition Bureau) dit du redémarrage qui a suivi.
func (a *Agent) posRecoveryNote(after time.Time) string {
	p := a.posInfo()
	if p.Kind != "bureau" {
		return ""
	}
	b, err := os.ReadFile(filepath.Join(p.Dir, "donnees", "journaux", "mariadb.err"))
	if err != nil {
		return ""
	}
	text := string(b)
	if len(text) > 400000 {
		text = text[len(text)-400000:]
	}
	var notes []string
	for _, l := range strings.Split(text, "\n") {
		ts, ok := mariaTime(l)
		if !ok || ts.Before(after) {
			continue
		}
		low := strings.ToLower(l)
		switch {
		case strings.Contains(low, "corrupt"):
			notes = append(notes, "ALERTE : MariaDB signale une corruption → « "+strings.TrimSpace(l)+" »")
		case strings.Contains(low, "crash recovery") || strings.Contains(low, "starting crash recovery"):
			notes = append(notes, "récupération automatique après arrêt brutal effectuée par MariaDB")
		}
	}
	if len(notes) == 0 {
		return ""
	}
	return strings.Join(uniq(notes), " ; ")
}

func uniq(in []string) []string {
	seen := map[string]bool{}
	var out []string
	for _, s := range in {
		if !seen[s] {
			seen[s] = true
			out = append(out, s)
		}
	}
	return out
}

// mariaTime : horodatage d'une ligne du journal MariaDB (« 2026-11-20 8:12:03 0 [Note] … »).
func mariaTime(l string) (time.Time, bool) {
	f := strings.Fields(l)
	if len(f) < 2 {
		return time.Time{}, false
	}
	t, err := time.ParseInLocation("2006-01-02 15:04:05", f[0]+" "+leftPad(f[1]), time.Local)
	return t, err == nil
}

func leftPad(hms string) string {
	if len(hms) == 7 { // 8:12:03
		return "0" + hms
	}
	return hms
}

// ------------------------------------------------------------------------------------------------ disques (journal)

func (a *Agent) CheckDiskErrors() {
	evs, err := platform.Events("System", []int{7, 11, 51, 153, 55, 98}, a.Now().Add(-24*time.Hour), 500)
	if err != nil {
		a.fail("erreurs_disque", err)
		return
	}
	a.ok("erreurs_disque")
	var hits []string
	for _, e := range evs {
		p := strings.ToLower(e.Provider)
		if (p == "disk" && (e.ID == 7 || e.ID == 11 || e.ID == 51 || e.ID == 153)) || (strings.Contains(p, "ntfs") && (e.ID == 55 || e.ID == 98)) {
			hits = append(hits, describe(e))
		}
	}
	if len(hits) == 0 {
		return // pas de résolution automatique : une erreur de disque reste à vérifier par une personne
	}
	a.raise(store.Incident{Code: "disque_erreurs", Key: "24h", Severity: store.Critical, Title: "Erreurs de disque signalées par Windows",
		Problem:      fmt.Sprintf("Windows a enregistré %d erreur(s) de lecture, d'écriture ou de système de fichiers en 24 heures.", len(hits)),
		Consequences: "Un disque qui produit des erreurs peut tomber en panne et faire perdre la base de POS Marwa.",
		Recommendations: []string{"Faites une sauvegarde tout de suite et copiez-la sur un support externe.",
			"Faites vérifier ou remplacer le disque par un technicien.", "Ne lancez pas de réparation du disque pendant les ventes."},
		Details: map[string]string{"Événements": strings.Join(firstN(hits, 10), " | ")}, Confidence: "certain"})
}

func firstN(s []string, n int) []string {
	if len(s) > n {
		return s[:n]
	}
	return s
}

// ------------------------------------------------------------------------------------------------ redémarrages

func (a *Agent) CheckReboots() {
	evs, err := platform.Events("System", []int{6005}, a.Now().Add(-24*time.Hour), 200)
	if err != nil {
		a.fail("redemarrages", err)
		return
	}
	a.ok("redemarrages")
	n := 0
	for _, e := range evs {
		if e.Provider == provEventLog {
			n++
		}
	}
	if n <= a.cfg().RebootsPerDayWarn {
		return
	}
	a.raise(store.Incident{Code: "redemarrages", Key: a.Now().Format("2006-01-02"), Severity: store.Warning, Title: "Redémarrages inhabituels",
		Problem:         fmt.Sprintf("Windows a démarré %d fois en 24 heures.", n),
		Consequences:    "Des redémarrages fréquents interrompent les ventes et peuvent abîmer la base de données.",
		Recommendations: []string{"Vérifiez s'il s'agit de mises à jour de Windows, de coupures de courant ou de plantages (voir les autres incidents).", "Planifiez les mises à jour de Windows en dehors des heures d'ouverture."},
		Details:         map[string]string{"Démarrages en 24 h": strconv.Itoa(n)}, Confidence: "certain"})
}

// ------------------------------------------------------------------------------------------------ services Windows

// Services essentiels surveillés : journal d'événements, WMI, pare-feu, Defender (s'il est installé), client DHCP/DNS.
var EssentialServices = []struct{ Name, Label string }{
	{"EventLog", "Journal des événements Windows"},
	{"Winmgmt", "Infrastructure de gestion Windows (WMI)"},
	{"mpssvc", "Pare-feu Windows Defender"},
	{"WinDefend", "Antivirus Microsoft Defender"},
	{"Dhcp", "Client DHCP (adresse réseau)"},
	{"Dnscache", "Client DNS"},
}

func (a *Agent) CheckServices() {
	var list []platform.Service
	for _, s := range EssentialServices {
		st := platform.ServiceState(s.Name)
		list = append(list, st)
		if !st.Exists {
			continue // Defender remplacé par un autre antivirus, par exemple : rien à signaler ici (phase 4)
		}
		if st.Running {
			a.clear("service_arrete", s.Name, "en marche")
			continue
		}
		sev := store.Warning
		if s.Name == "mpssvc" || s.Name == "WinDefend" || s.Name == "EventLog" {
			sev = store.Critical
		}
		a.raise(store.Incident{Code: "service_arrete", Key: s.Name, Severity: sev, Title: "Service Windows arrêté : " + s.Label,
			Problem:         fmt.Sprintf("Le service « %s » (%s) n'est pas en marche (%s).", s.Label, s.Name, orDash(st.State)),
			Consequences:    "Une protection ou une fonction de base de Windows ne fonctionne plus.",
			Recommendations: []string{"Redémarrez l'ordinateur ; si le service reste arrêté, faites vérifier le PC (un logiciel malveillant peut arrêter les protections)."},
			Details:         map[string]string{"Service": s.Name, "État": orDash(st.State)}, Confidence: "certain"})
	}
	a.set(func(s *Status) { s.Services = list })
}

func orDash(s string) string {
	if s == "" {
		return "inconnu"
	}
	return s
}

// ------------------------------------------------------------------------------------------------ POS Marwa

// posInfo : installation de POS Marwa, vérifiée (jamais supposée).
func (a *Agent) posInfo() PosStatus {
	dir := a.cfg().PosDir
	if dir == "" {
		dir = platform.RegString(`SOFTWARE\POSMarwaBureau`, "Dossier")
	}
	if dir != "" {
		if _, err := os.Stat(filepath.Join(dir, "pos-marwa.ini")); err == nil {
			p := PosStatus{Kind: "bureau", Dir: dir, WebPort: 8740, DBPort: 3407}
			ini := readIni(filepath.Join(dir, "pos-marwa.ini"))
			if n, err := strconv.Atoi(ini["PORT_WEB"]); err == nil && n > 1023 {
				p.WebPort = n
			}
			if n, err := strconv.Atoi(ini["PORT_BD"]); err == nil && n > 1023 {
				p.DBPort = n
			}
			// Port réellement écrit dans la configuration de MariaDB, s'il existe
			if n, err := strconv.Atoi(readIni(filepath.Join(dir, "donnees", "mariadb.ini"))["PORT"]); err == nil && n > 1023 {
				p.DBPort = n
			}
			return p
		}
	}
	if names := platform.ServiceNames("wamp"); len(names) > 0 {
		return PosStatus{Kind: "wamp", Dir: dir, Detail: strings.Join(names, ", ")}
	}
	return PosStatus{Kind: "absent", Detail: "POS Marwa n'a pas été trouvé sur cet ordinateur."}
}

func readIni(p string) map[string]string {
	out := map[string]string{}
	b, err := os.ReadFile(p)
	if err != nil {
		return out
	}
	for _, l := range strings.Split(string(b), "\n") {
		l = strings.TrimSpace(l)
		if l == "" || l[0] == ';' || l[0] == '#' || l[0] == '[' {
			continue
		}
		if k, v, ok := strings.Cut(l, "="); ok {
			out[strings.ToUpper(strings.TrimSpace(k))] = strings.TrimSpace(v)
		}
	}
	return out
}

func portOpen(port int) bool {
	c, err := net.DialTimeout("tcp", "127.0.0.1:"+strconv.Itoa(port), 1500*time.Millisecond)
	if err != nil {
		return false
	}
	c.Close()
	return true
}

func bptr(b bool) *bool { return &b }

func (a *Agent) CheckPos() {
	p := a.posInfo()
	p.CheckedAt = a.Now()
	switch p.Kind {
	case "bureau":
		p.Running = platform.ProcessRunning("POS Marwa.exe") || portOpen(p.WebPort)
		if p.Running {
			r, err := a.Http.Get(fmt.Sprintf("http://127.0.0.1:%d/__bureau/sante", p.WebPort))
			if err == nil {
				b := make([]byte, 200)
				n, _ := r.Body.Read(b)
				r.Body.Close()
				p.AppOK = bptr(strings.Contains(string(b[:n]), "pret=true"))
			} else {
				p.AppOK = bptr(false)
			}
			p.DBOK = bptr(portOpen(p.DBPort))
		}
	case "wamp":
		apacheOK, dbOK := false, false
		var db, web []string
		for _, n := range strings.Split(p.Detail, ", ") {
			st := platform.ServiceState(n)
			l := strings.ToLower(n)
			if strings.Contains(l, "mysql") || strings.Contains(l, "maria") {
				db = append(db, n+" "+orDash(st.State))
				dbOK = dbOK || st.Running
			}
			if strings.Contains(l, "apache") {
				web = append(web, n+" "+orDash(st.State))
				apacheOK = apacheOK || st.Running
			}
		}
		p.Running = apacheOK || dbOK
		if len(web) > 0 {
			p.AppOK = bptr(apacheOK)
		}
		if len(db) > 0 {
			p.DBOK = bptr(dbOK)
		}
		p.Detail = strings.Join(append(web, db...), " ; ")
	}
	a.set(func(s *Status) { s.Pos = p })
	_ = a.S.Set("pos_vu", p.Kind)
	// Incidents : seulement quand POS Marwa est en marche (fermé hors des heures d'ouverture n'est pas un problème)
	if p.Running && p.DBOK != nil && !*p.DBOK {
		a.raise(store.Incident{Code: "base_arretee", Key: p.Kind, Severity: store.Critical, Title: "Base de données arrêtée",
			Problem:      "Le service de base de données de POS Marwa ne répond pas.",
			Consequences: "POS Marwa risque de ne plus pouvoir enregistrer les ventes.",
			Recommendations: []string{"Ne validez plus de vente tant que la base ne répond pas.",
				map[string]string{"bureau": "Fermez puis relancez POS Marwa (menu Démarrer → Arrêter POS Marwa, puis l'icône POS Marwa).", "wamp": "Dans WampServer, redémarrez les services (icône verte → Redémarrer les services)."}[p.Kind],
				"Si la base ne redémarre pas : consultez le rapport de diagnostic et contactez l'assistance."},
			Details: map[string]string{"Installation": p.Kind, "Port de la base": strconv.Itoa(p.DBPort), "Services": p.Detail}, Confidence: "certain"})
	} else if p.DBOK != nil && *p.DBOK {
		a.clear("base_arretee", p.Kind, "la base répond")
	}
	if p.Running && p.AppOK != nil && !*p.AppOK && (p.DBOK == nil || *p.DBOK) {
		a.raise(store.Incident{Code: "pos_ne_repond_pas", Key: p.Kind, Severity: store.Warning, Title: "POS Marwa ne répond pas",
			Problem:         "POS Marwa est lancé mais son application ne répond pas normalement.",
			Consequences:    "La caisse peut être bloquée ou lente.",
			Recommendations: []string{"Patientez une minute (démarrage en cours), puis fermez et relancez POS Marwa.", "Si le problème continue, ouvrez le rapport de diagnostic de POS Marwa."},
			Details:         map[string]string{"Installation": p.Kind, "Port": strconv.Itoa(p.WebPort), "Services": p.Detail}, Confidence: "certain"})
	} else if p.AppOK != nil && *p.AppOK {
		a.clear("pos_ne_repond_pas", p.Kind, "l'application répond")
	}
}
