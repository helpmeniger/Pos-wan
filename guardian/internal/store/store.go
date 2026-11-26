// Package store : base locale de MARWA GUARDIAN (SQLite, un seul fichier dans le dossier de données).
//
// Les incidents forment une chaîne : chaque incident enregistre l'empreinte SHA-256 de son contenu et celle de
// l'incident précédent. Une ligne modifiée ou supprimée hors de MARWA GUARDIAN casse la chaîne, ce que Verify signale.
package store

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"path/filepath"
	"strings"
	"sync"
	"time"

	_ "modernc.org/sqlite"
)

// Niveaux de gravité (ordre croissant).
const (
	Info      = 1
	Warning   = 2
	Critical  = 3
	Emergency = 4
)

// SeverityLabel : libellé français d'un niveau.
func SeverityLabel(s int) string {
	switch s {
	case Warning:
		return "Avertissement"
	case Critical:
		return "Critique"
	case Emergency:
		return "Urgence"
	}
	return "Information"
}

// Incident : un problème détecté (ou un événement à signaler).
type Incident struct {
	ID              int64             `json:"id"`
	Code            string            `json:"code"`            // type de problème (ex. disk_low)
	Key             string            `json:"key"`             // objet concerné (ex. C:) : un incident ouvert par code+clé
	Severity        int               `json:"severity"`        // 1 à 4
	Title           string            `json:"title"`           // titre clair
	Problem         string            `json:"problem"`         // problème détecté (faits)
	Consequences    string            `json:"consequences"`    // conséquences possibles
	Recommendations []string          `json:"recommendations"` // actions conseillées
	Details         map[string]string `json:"details"`         // faits techniques (valeurs mesurées, événements)
	Confidence      string            `json:"confidence"`      // « certain », « probable », « possible » (causes déduites)
	Status          string            `json:"status"`          // open, resolved
	CreatedAt       time.Time         `json:"created_at"`      // première détection
	LastSeen        time.Time         `json:"last_seen"`       // dernière détection
	Occurrences     int               `json:"occurrences"`     // nombre de détections
	ResolvedAt      *time.Time        `json:"resolved_at"`     // résolution
	ResolvedBy      string            `json:"resolved_by"`     // « auto » (condition disparue) ou administrateur
	Notified        bool              `json:"notified"`        // notification déjà affichée
	Hash            string            `json:"-"`
}

// Store : accès concurrent sûr à la base.
type Store struct {
	db *sql.DB
	mu sync.Mutex
}

const schema = `
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;
CREATE TABLE IF NOT EXISTS incidents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL, key TEXT NOT NULL DEFAULT '', severity INTEGER NOT NULL,
  title TEXT NOT NULL, problem TEXT NOT NULL, consequences TEXT NOT NULL DEFAULT '', recommendations TEXT NOT NULL DEFAULT '[]',
  details TEXT NOT NULL DEFAULT '{}', confidence TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'open', created_at TEXT NOT NULL, last_seen TEXT NOT NULL, occurrences INTEGER NOT NULL DEFAULT 1,
  resolved_at TEXT, resolved_by TEXT NOT NULL DEFAULT '', notified INTEGER NOT NULL DEFAULT 0,
  prev_hash TEXT NOT NULL, hash TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_inc_open ON incidents(status, code, key);
CREATE TABLE IF NOT EXISTS journal (
  id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT NOT NULL, incident_id INTEGER, action TEXT NOT NULL, actor TEXT NOT NULL, note TEXT NOT NULL DEFAULT '',
  prev_hash TEXT NOT NULL, hash TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS state (k TEXT PRIMARY KEY, v TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS metrics (ts TEXT NOT NULL, name TEXT NOT NULL, value REAL NOT NULL);
CREATE INDEX IF NOT EXISTS idx_metrics ON metrics(name, ts);
`

// Open ouvre (ou crée) la base dans le dossier de données.
func Open(dir string) (*Store, error) {
	db, err := sql.Open("sqlite", filepath.Join(dir, "guardian.db"))
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1) // SQLite : un seul écrivain, aucune attente de verrou entre nos propres requêtes
	if _, err := db.Exec(schema); err != nil {
		db.Close()
		return nil, fmt.Errorf("initialisation de la base : %w", err)
	}
	return &Store{db: db}, nil
}

func (s *Store) Close() error { return s.db.Close() }

const tsFmt = time.RFC3339Nano

func sum(parts ...string) string {
	h := sha256.New()
	for _, p := range parts {
		h.Write([]byte(p))
		h.Write([]byte{0})
	}
	return hex.EncodeToString(h.Sum(nil))
}

// incidentHash : empreinte des champs qui ne changent jamais après la création (le suivi — occurrences, statut —
// est tracé dans le journal, lui-même chaîné).
func incidentHash(prev string, in *Incident) string {
	rec, _ := json.Marshal(in.Recommendations)
	det, _ := json.Marshal(in.Details)
	return sum(prev, in.Code, in.Key, fmt.Sprint(in.Severity), in.Title, in.Problem, in.Consequences, string(rec), string(det), in.Confidence, in.CreatedAt.UTC().Format(tsFmt))
}

func (s *Store) lastHash(tx *sql.Tx, table string) (string, error) {
	var h string
	err := tx.QueryRow("SELECT hash FROM " + table + " ORDER BY id DESC LIMIT 1").Scan(&h)
	if errors.Is(err, sql.ErrNoRows) {
		return "origine", nil
	}
	return h, err
}

func (s *Store) journal(tx *sql.Tx, incidentID int64, action, actor, note string, at time.Time) error {
	prev, err := s.lastHash(tx, "journal")
	if err != nil {
		return err
	}
	ts := at.UTC().Format(tsFmt)
	h := sum(prev, ts, fmt.Sprint(incidentID), action, actor, note)
	_, err = tx.Exec("INSERT INTO journal (ts, incident_id, action, actor, note, prev_hash, hash) VALUES (?,?,?,?,?,?,?)", ts, incidentID, action, actor, note, prev, h)
	return err
}

// Raise enregistre un incident. S'il en existe déjà un ouvert pour le même code et la même clé, il est mis à jour
// (occurrences, dernière détection, gravité si elle augmente) au lieu d'en créer un second.
// Retourne l'incident et vrai s'il est nouveau (ou s'il s'est aggravé : à notifier).
func (s *Store) Raise(in Incident, now time.Time) (Incident, bool, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	tx, err := s.db.Begin()
	if err != nil {
		return in, false, err
	}
	defer tx.Rollback()
	var id int64
	var sev int
	err = tx.QueryRow("SELECT id, severity FROM incidents WHERE status = 'open' AND code = ? AND key = ?", in.Code, in.Key).Scan(&id, &sev)
	if err == nil {
		worse := in.Severity > sev
		if _, err := tx.Exec("UPDATE incidents SET occurrences = occurrences + 1, last_seen = ? WHERE id = ?", now.UTC().Format(tsFmt), id); err != nil {
			return in, false, err
		}
		if worse {
			// Aggravation : nouvel incident (le précédent est clos, la chaîne reste intacte)
			if _, err := tx.Exec("UPDATE incidents SET status = 'resolved', resolved_at = ?, resolved_by = 'aggravation' WHERE id = ?", now.UTC().Format(tsFmt), id); err != nil {
				return in, false, err
			}
			if err := s.journal(tx, id, "aggravé", "agent", SeverityLabel(in.Severity), now); err != nil {
				return in, false, err
			}
		} else {
			if err := tx.Commit(); err != nil {
				return in, false, err
			}
			got, err := s.Get(id)
			return got, false, err
		}
	} else if !errors.Is(err, sql.ErrNoRows) {
		return in, false, err
	}
	in.CreatedAt, in.LastSeen, in.Status, in.Occurrences = now, now, "open", 1
	if in.Recommendations == nil {
		in.Recommendations = []string{}
	}
	if in.Details == nil {
		in.Details = map[string]string{}
	}
	prev, err := s.lastHash(tx, "incidents")
	if err != nil {
		return in, false, err
	}
	in.Hash = incidentHash(prev, &in)
	rec, _ := json.Marshal(in.Recommendations)
	det, _ := json.Marshal(in.Details)
	res, err := tx.Exec(`INSERT INTO incidents (code, key, severity, title, problem, consequences, recommendations, details, confidence, status, created_at, last_seen, occurrences, prev_hash, hash)
		VALUES (?,?,?,?,?,?,?,?,?,'open',?,?,1,?,?)`, in.Code, in.Key, in.Severity, in.Title, in.Problem, in.Consequences, string(rec), string(det), in.Confidence,
		now.UTC().Format(tsFmt), now.UTC().Format(tsFmt), prev, in.Hash)
	if err != nil {
		return in, false, err
	}
	in.ID, _ = res.LastInsertId()
	if err := s.journal(tx, in.ID, "détecté", "agent", in.Title, now); err != nil {
		return in, false, err
	}
	return in, true, tx.Commit()
}

// Resolve clôt un incident ouvert (actor : « auto » quand la condition a disparu, sinon le nom de l'administrateur).
func (s *Store) Resolve(id int64, actor, note string, now time.Time) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	tx, err := s.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	r, err := tx.Exec("UPDATE incidents SET status = 'resolved', resolved_at = ?, resolved_by = ? WHERE id = ? AND status = 'open'", now.UTC().Format(tsFmt), actor, id)
	if err != nil {
		return err
	}
	if n, _ := r.RowsAffected(); n == 0 {
		return errors.New("incident introuvable ou déjà résolu")
	}
	if err := s.journal(tx, id, "résolu", actor, note, now); err != nil {
		return err
	}
	return tx.Commit()
}

// ResolveOpen clôt l'incident ouvert d'un code et d'une clé, s'il existe (condition revenue à la normale).
func (s *Store) ResolveOpen(code, key, note string, now time.Time) (bool, error) {
	var id int64
	err := s.db.QueryRow("SELECT id FROM incidents WHERE status = 'open' AND code = ? AND key = ?", code, key).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return true, s.Resolve(id, "auto", note, now)
}

// MarkNotified : la notification de l'incident a été remise au notificateur.
func (s *Store) MarkNotified(id int64) error {
	_, err := s.db.Exec("UPDATE incidents SET notified = 1 WHERE id = ?", id)
	return err
}

const cols = "id, code, key, severity, title, problem, consequences, recommendations, details, confidence, status, created_at, last_seen, occurrences, resolved_at, resolved_by, notified, hash"

func scan(row interface{ Scan(...any) error }) (Incident, error) {
	var in Incident
	var rec, det, created, last string
	var resolved sql.NullString
	var notified int
	err := row.Scan(&in.ID, &in.Code, &in.Key, &in.Severity, &in.Title, &in.Problem, &in.Consequences, &rec, &det, &in.Confidence, &in.Status, &created, &last, &in.Occurrences, &resolved, &in.ResolvedBy, &notified, &in.Hash)
	if err != nil {
		return in, err
	}
	_ = json.Unmarshal([]byte(rec), &in.Recommendations)
	_ = json.Unmarshal([]byte(det), &in.Details)
	in.CreatedAt, _ = time.Parse(tsFmt, created)
	in.LastSeen, _ = time.Parse(tsFmt, last)
	if resolved.Valid {
		t, _ := time.Parse(tsFmt, resolved.String)
		in.ResolvedAt = &t
	}
	in.Notified = notified == 1
	return in, nil
}

func (s *Store) Get(id int64) (Incident, error) {
	return scan(s.db.QueryRow("SELECT "+cols+" FROM incidents WHERE id = ?", id))
}

// List : incidents (status : « open », « resolved » ou vide pour tous), les plus récents d'abord.
func (s *Store) List(status string, limit int) ([]Incident, error) {
	q := "SELECT " + cols + " FROM incidents"
	args := []any{}
	if status != "" {
		q += " WHERE status = ?"
		args = append(args, status)
	}
	q += " ORDER BY id DESC LIMIT ?"
	args = append(args, limit)
	rows, err := s.db.Query(q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []Incident{}
	for rows.Next() {
		in, err := scan(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, in)
	}
	return out, rows.Err()
}

// Pending : incidents ouverts pas encore notifiés (notificateur).
func (s *Store) Pending() ([]Incident, error) {
	rows, err := s.db.Query("SELECT " + cols + " FROM incidents WHERE status = 'open' AND notified = 0 ORDER BY id")
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []Incident{}
	for rows.Next() {
		in, err := scan(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, in)
	}
	return out, rows.Err()
}

// Verify contrôle les deux chaînes (incidents et journal). Retourne la liste des anomalies (vide = intact).
func (s *Store) Verify() ([]string, error) {
	var bad []string
	rows, err := s.db.Query("SELECT " + cols + ", prev_hash FROM incidents ORDER BY id")
	if err != nil {
		return nil, err
	}
	prev := "origine"
	for rows.Next() {
		var in Incident
		var rec, det, created, last, ph string
		var resolved sql.NullString
		var notified int
		if err := rows.Scan(&in.ID, &in.Code, &in.Key, &in.Severity, &in.Title, &in.Problem, &in.Consequences, &rec, &det, &in.Confidence, &in.Status, &created, &last, &in.Occurrences, &resolved, &in.ResolvedBy, &notified, &in.Hash, &ph); err != nil {
			rows.Close()
			return nil, err
		}
		_ = json.Unmarshal([]byte(rec), &in.Recommendations)
		_ = json.Unmarshal([]byte(det), &in.Details)
		in.CreatedAt, _ = time.Parse(tsFmt, created)
		if ph != prev {
			bad = append(bad, fmt.Sprintf("incident %d : lien avec l'incident précédent rompu (incident supprimé ?)", in.ID))
		}
		if incidentHash(ph, &in) != in.Hash {
			bad = append(bad, fmt.Sprintf("incident %d : contenu modifié après son enregistrement", in.ID))
		}
		prev = in.Hash
	}
	rows.Close()
	jr, err := s.db.Query("SELECT id, ts, incident_id, action, actor, note, prev_hash, hash FROM journal ORDER BY id")
	if err != nil {
		return nil, err
	}
	defer jr.Close()
	prev = "origine"
	for jr.Next() {
		var id, inc int64
		var ts, action, actor, note, ph, h string
		if err := jr.Scan(&id, &ts, &inc, &action, &actor, &note, &ph, &h); err != nil {
			return nil, err
		}
		if ph != prev || sum(ph, ts, fmt.Sprint(inc), action, actor, note) != h {
			bad = append(bad, fmt.Sprintf("journal %d : entrée modifiée ou supprimée", id))
		}
		prev = h
	}
	return bad, jr.Err()
}

// JournalEntry : une ligne du journal (historique des actions).
type JournalEntry struct {
	TS       time.Time `json:"ts"`
	Incident int64     `json:"incident_id"`
	Action   string    `json:"action"`
	Actor    string    `json:"actor"`
	Note     string    `json:"note"`
}

func (s *Store) Journal(limit int) ([]JournalEntry, error) {
	rows, err := s.db.Query("SELECT ts, incident_id, action, actor, note FROM journal ORDER BY id DESC LIMIT ?", limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []JournalEntry{}
	for rows.Next() {
		var e JournalEntry
		var ts string
		if err := rows.Scan(&ts, &e.Incident, &e.Action, &e.Actor, &e.Note); err != nil {
			return nil, err
		}
		e.TS, _ = time.Parse(tsFmt, ts)
		out = append(out, e)
	}
	return out, rows.Err()
}

// AddJournal : action hors incident (connexion à la console, réglage…).
func (s *Store) AddJournal(action, actor, note string, now time.Time) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	tx, err := s.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if err := s.journal(tx, 0, action, actor, note, now); err != nil {
		return err
	}
	return tx.Commit()
}

// État persistant (dernière date analysée, dernier démarrage…).
func (s *Store) Get1(k string) string {
	var v string
	_ = s.db.QueryRow("SELECT v FROM state WHERE k = ?", k).Scan(&v)
	return v
}

func (s *Store) Set(k, v string) error {
	_, err := s.db.Exec("INSERT INTO state (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", k, v)
	return err
}

// Metric : mesure horodatée (courbes du tableau de bord) ; conservée 7 jours.
func (s *Store) Metric(name string, v float64, now time.Time) error {
	_, err := s.db.Exec("INSERT INTO metrics (ts, name, value) VALUES (?,?,?)", now.UTC().Format(tsFmt), name, v)
	return err
}

func (s *Store) PruneMetrics(now time.Time) error {
	_, err := s.db.Exec("DELETE FROM metrics WHERE ts < ?", now.Add(-7*24*time.Hour).UTC().Format(tsFmt))
	return err
}

// Series : mesures d'un indicateur depuis « since ».
func (s *Store) Series(name string, since time.Time) ([][2]any, error) {
	rows, err := s.db.Query("SELECT ts, value FROM metrics WHERE name = ? AND ts >= ? ORDER BY ts", name, since.UTC().Format(tsFmt))
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := [][2]any{}
	for rows.Next() {
		var ts string
		var v float64
		if err := rows.Scan(&ts, &v); err != nil {
			return nil, err
		}
		out = append(out, [2]any{ts, v})
	}
	return out, rows.Err()
}

// CountOpen : incidents ouverts par gravité.
func (s *Store) CountOpen() (map[int]int, error) {
	rows, err := s.db.Query("SELECT severity, COUNT(*) FROM incidents WHERE status = 'open' GROUP BY severity")
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[int]int{}
	for rows.Next() {
		var sev, n int
		if err := rows.Scan(&sev, &n); err != nil {
			return nil, err
		}
		out[sev] = n
	}
	return out, rows.Err()
}

// CountSince : incidents d'un code créés depuis une date (ex. arrêts anormaux sur 30 jours).
func (s *Store) CountSince(code string, since time.Time) (int, error) {
	var n int
	err := s.db.QueryRow("SELECT COUNT(*) FROM incidents WHERE code LIKE ? AND created_at >= ?", code, since.UTC().Format(tsFmt)).Scan(&n)
	return n, err
}

// Exec brut (tests de falsification uniquement).
func (s *Store) ExecForTest(q string, args ...any) error {
	if !strings.HasPrefix(q, "UPDATE") && !strings.HasPrefix(q, "DELETE") {
		return errors.New("refusé")
	}
	_, err := s.db.Exec(q, args...)
	return err
}
