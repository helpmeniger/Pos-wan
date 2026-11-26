package store

import (
	"strings"
	"testing"
	"time"
)

func open(t *testing.T) *Store {
	t.Helper()
	s, err := Open(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { s.Close() })
	return s
}

func TestRaiseDedupResolve(t *testing.T) {
	s := open(t)
	now := time.Date(2026, 11, 26, 10, 0, 0, 0, time.UTC)
	in := Incident{Code: "disque_plein", Key: `C:\`, Severity: Warning, Title: "Disque presque plein", Problem: "10 % libre", Recommendations: []string{"Libérez de l'espace"}}
	a, isNew, err := s.Raise(in, now)
	if err != nil || !isNew || a.ID == 0 {
		t.Fatalf("création : %v %v %+v", err, isNew, a)
	}
	b, isNew, _ := s.Raise(in, now.Add(time.Minute))
	if isNew || b.ID != a.ID || b.Occurrences != 2 {
		t.Fatalf("même problème : un seul incident attendu, %+v", b)
	}
	// Aggravation : nouvel incident (à notifier), l'ancien clos
	in.Severity = Critical
	c, isNew, _ := s.Raise(in, now.Add(2*time.Minute))
	if !isNew || c.ID == a.ID || c.Severity != Critical {
		t.Fatalf("aggravation : nouvel incident attendu, %+v", c)
	}
	if old, _ := s.Get(a.ID); old.Status != "resolved" || old.ResolvedBy != "aggravation" {
		t.Fatalf("ancien incident : %+v", old)
	}
	if done, _ := s.ResolveOpen("disque_plein", `C:\`, "20 % libre", now.Add(3*time.Minute)); !done {
		t.Fatal("résolution automatique attendue")
	}
	if err := s.Resolve(c.ID, "administrateur", "", now); err == nil {
		t.Fatal("double résolution refusée attendue")
	}
	open, _ := s.List("open", 10)
	if len(open) != 0 {
		t.Fatalf("aucun incident ouvert attendu, %d", len(open))
	}
	if bad, _ := s.Verify(); len(bad) != 0 {
		t.Fatalf("historique intact attendu : %v", bad)
	}
}

func TestPendingNotified(t *testing.T) {
	s := open(t)
	now := time.Now()
	a, _, _ := s.Raise(Incident{Code: "x", Severity: Info, Title: "t", Problem: "p"}, now)
	p, _ := s.Pending()
	if len(p) != 1 {
		t.Fatalf("1 notification en attente attendue, %d", len(p))
	}
	_ = s.MarkNotified(a.ID)
	if p, _ := s.Pending(); len(p) != 0 {
		t.Fatal("notification déjà remise")
	}
}

func TestTamperDetection(t *testing.T) {
	s := open(t)
	now := time.Now()
	var ids []int64
	for i, code := range []string{"a", "b", "c"} {
		in, _, _ := s.Raise(Incident{Code: code, Severity: Warning, Title: "Incident " + code, Problem: "fait réel"}, now.Add(time.Duration(i)*time.Second))
		ids = append(ids, in.ID)
	}
	if bad, _ := s.Verify(); len(bad) != 0 {
		t.Fatalf("intact attendu : %v", bad)
	}
	// Contenu modifié
	if err := s.ExecForTest("UPDATE incidents SET problem = 'rien de grave' WHERE id = ?", ids[0]); err != nil {
		t.Fatal(err)
	}
	bad, _ := s.Verify()
	if len(bad) == 0 || !strings.Contains(strings.Join(bad, " "), "contenu modifié") {
		t.Fatalf("modification non détectée : %v", bad)
	}
	// Incident supprimé
	s2 := open(t)
	for i, code := range []string{"a", "b", "c"} {
		_, _, _ = s2.Raise(Incident{Code: code, Severity: Warning, Title: "Incident " + code, Problem: "fait"}, now.Add(time.Duration(i)*time.Second))
	}
	_ = s2.ExecForTest("DELETE FROM incidents WHERE code = 'b'")
	bad, _ = s2.Verify()
	if !strings.Contains(strings.Join(bad, " "), "rompu") {
		t.Fatalf("suppression non détectée : %v", bad)
	}
	// Journal modifié
	_ = s2.ExecForTest("UPDATE journal SET actor = 'quelqu''un' WHERE id = 1")
	bad, _ = s2.Verify()
	if !strings.Contains(strings.Join(bad, " "), "journal 1") {
		t.Fatalf("journal modifié non détecté : %v", bad)
	}
}

func TestStateAndMetrics(t *testing.T) {
	s := open(t)
	_ = s.Set("k", "v1")
	_ = s.Set("k", "v2")
	if s.Get1("k") != "v2" || s.Get1("absent") != "" {
		t.Fatal("état")
	}
	now := time.Now()
	_ = s.Metric("processeur", 12, now.Add(-8*24*time.Hour))
	_ = s.Metric("processeur", 34, now)
	_ = s.PruneMetrics(now)
	ser, _ := s.Series("processeur", now.Add(-30*24*time.Hour))
	if len(ser) != 1 {
		t.Fatalf("mesures de plus de 7 jours supprimées, %d", len(ser))
	}
}
