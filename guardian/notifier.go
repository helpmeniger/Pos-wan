package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"strings"
	"time"

	"marwa/guardian/internal/platform"
	"marwa/guardian/internal/web"
)

// Alert : alerte à afficher (renvoyée par l'agent).
type Alert struct {
	ID       int64  `json:"id"`
	Severity int    `json:"gravite"`
	Label    string `json:"gravite_libelle"`
	Title    string `json:"titre"`
	Problem  string `json:"probleme"`
	Advice   string `json:"conseil"`
}

// readNotifierFile : port de la console et jeton du notificateur (écrits par le service à son démarrage).
func readNotifierFile() (port, token string, err error) {
	b, err := os.ReadFile(web.NotifierTokenFile(platform.DataDir()))
	if err != nil {
		return "", "", err
	}
	p, t, ok := strings.Cut(strings.TrimSpace(string(b)), "\n")
	if !ok {
		return "", "", fmt.Errorf("fichier du notificateur illisible")
	}
	return strings.TrimSpace(p), strings.TrimSpace(t), nil
}

// fetchAlerts : alertes non encore affichées.
func fetchAlerts(cl *http.Client) ([]Alert, string, error) {
	port, tok, err := readNotifierFile()
	if err != nil {
		return nil, "", fmt.Errorf("agent pas encore démarré (%v)", err)
	}
	req, _ := http.NewRequest(http.MethodGet, "http://127.0.0.1:"+port+"/api/notificateur/alertes", nil)
	req.Header.Set("X-Guardian-Jeton", tok)
	r, err := cl.Do(req)
	if err != nil {
		return nil, "", err
	}
	defer r.Body.Close()
	var d struct {
		OK      bool    `json:"ok"`
		Alertes []Alert `json:"alertes"`
		Console string  `json:"console"`
	}
	if err := json.NewDecoder(r.Body).Decode(&d); err != nil || !d.OK {
		return nil, "", fmt.Errorf("réponse de l'agent refusée (%d)", r.StatusCode)
	}
	return d.Alertes, d.Console, nil
}

// runNotifier : boucle du notificateur (session de l'utilisateur) : interroge l'agent toutes les 20 secondes.
func runNotifier() error {
	lg := log.New(os.Stderr, "notificateur ", log.LstdFlags)
	cl := &http.Client{Timeout: 10 * time.Second}
	quiet := false
	for {
		alerts, console, err := fetchAlerts(cl)
		if err != nil {
			if !quiet {
				lg.Printf("%v", err)
				quiet = true
			}
		} else {
			quiet = false
			for _, a := range alerts {
				if err := showNotification(a, console); err != nil {
					lg.Printf("notification %d : %v", a.ID, err)
				}
			}
		}
		time.Sleep(20 * time.Second)
	}
}
