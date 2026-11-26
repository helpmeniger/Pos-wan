// Package platform : ce que MARWA GUARDIAN lit du système. Sous Windows, les API et outils natifs (journaux
// d'événements, gestionnaire de services, mémoire, disques, registre) ; ailleurs (essais automatiques), des valeurs
// réelles de Linux ou des fichiers d'exemples fournis par les tests.
package platform

import (
	"encoding/xml"
	"strconv"
	"strings"
	"time"
)

// Disk : un volume.
type Disk struct {
	Mount  string `json:"mount"`  // C:\ ou /
	Total  uint64 `json:"total"`  // octets
	Free   uint64 `json:"free"`   // octets disponibles
	System bool   `json:"system"` // volume du système
}

// Memory : mémoire physique.
type Memory struct {
	Total     uint64 `json:"total"`
	Available uint64 `json:"available"`
}

// Service : état d'un service.
type Service struct {
	Name    string `json:"name"`
	Exists  bool   `json:"exists"`
	Running bool   `json:"running"`
	State   string `json:"state"`
}

// Event : un événement des journaux Windows (format de « wevtutil qe /f:xml »).
type Event struct {
	Provider string            `json:"provider"`
	ID       int               `json:"id"`
	Level    int               `json:"level"` // 1 critique, 2 erreur, 3 avertissement, 4 information
	Time     time.Time         `json:"time"`
	RecordID int64             `json:"record_id"`
	Computer string            `json:"computer"`
	Data     map[string]string `json:"data"`   // données nommées (BugcheckCode, …)
	Values   []string          `json:"values"` // données sans nom, dans l'ordre (6008, 1074…)
}

// Fichier XML de « wevtutil qe /f:xml » : suite d'éléments <Event> sans racine.
type xmlEvent struct {
	System struct {
		Provider struct {
			Name string `xml:"Name,attr"`
		} `xml:"Provider"`
		EventID     string `xml:"EventID"`
		Level       string `xml:"Level"`
		TimeCreated struct {
			SystemTime string `xml:"SystemTime,attr"`
		} `xml:"TimeCreated"`
		EventRecordID string `xml:"EventRecordID"`
		Computer      string `xml:"Computer"`
	} `xml:"System"`
	EventData struct {
		Data []struct {
			Name  string `xml:"Name,attr"`
			Value string `xml:",chardata"`
		} `xml:"Data"`
	} `xml:"EventData"`
	UserData struct {
		Inner []byte `xml:",innerxml"`
	} `xml:"UserData"`
}

// ParseEvents lit la sortie XML de wevtutil (événements bout à bout).
func ParseEvents(raw []byte) ([]Event, error) {
	dec := xml.NewDecoder(strings.NewReader("<Events>" + string(raw) + "</Events>"))
	var wrap struct {
		Events []xmlEvent `xml:"Event"`
	}
	if err := dec.Decode(&wrap); err != nil {
		return nil, err
	}
	out := make([]Event, 0, len(wrap.Events))
	for _, e := range wrap.Events {
		ev := Event{Provider: e.System.Provider.Name, Computer: strings.TrimSpace(e.System.Computer), Data: map[string]string{}}
		ev.ID, _ = strconv.Atoi(strings.TrimSpace(e.System.EventID))
		ev.Level, _ = strconv.Atoi(strings.TrimSpace(e.System.Level))
		ev.RecordID, _ = strconv.ParseInt(strings.TrimSpace(e.System.EventRecordID), 10, 64)
		ev.Time, _ = time.Parse(time.RFC3339Nano, strings.TrimSpace(e.System.TimeCreated.SystemTime))
		for _, d := range e.EventData.Data {
			v := strings.TrimSpace(d.Value)
			if d.Name != "" {
				ev.Data[d.Name] = v
			} else {
				ev.Values = append(ev.Values, v)
			}
		}
		out = append(out, ev)
	}
	return out, nil
}

// EventQuery : requête XPath pour wevtutil (identifiants et date de début).
func EventQuery(ids []int, since time.Time) string {
	parts := make([]string, len(ids))
	for i, id := range ids {
		parts[i] = "EventID=" + strconv.Itoa(id)
	}
	q := "*[System[(" + strings.Join(parts, " or ") + ")"
	if !since.IsZero() {
		q += " and TimeCreated[@SystemTime>='" + since.UTC().Format("2006-01-02T15:04:05.000Z") + "']"
	}
	return q + "]]"
}

// Volume : lecteur avec son nom (une clé USB est reconnue par son nom, même si sa lettre change).
type Volume struct {
	Root      string `json:"root"`
	Label     string `json:"label"`
	Removable bool   `json:"removable"`
}
