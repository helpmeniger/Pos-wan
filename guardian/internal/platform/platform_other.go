//go:build !windows

package platform

// Hors Windows (essais automatiques) : mémoire, processeur, disques et processus réels de Linux ; journaux
// d'événements, services et registre lus dans le dossier d'exemples GUARDIAN_FIXTURES :
//   <journal>.xml   événements au format de « wevtutil qe /f:xml » (ex. System.xml)
//   services.json   {"nom": "running" | "stopped"}
//   registry.json   {"chemin\\valeur": "texte"}

import (
	"bufio"
	"encoding/json"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

func fixtures() string { return os.Getenv("GUARDIAN_FIXTURES") }

func Events(log string, ids []int, since time.Time, max int) ([]Event, error) {
	raw, err := os.ReadFile(filepath.Join(fixtures(), strings.ReplaceAll(log, "/", "_")+".xml"))
	if err != nil {
		return nil, nil // journal absent : aucun événement
	}
	all, err := ParseEvents(raw)
	if err != nil {
		return nil, err
	}
	want := map[int]bool{}
	for _, id := range ids {
		want[id] = true
	}
	var out []Event
	for _, e := range all {
		if want[e.ID] && (since.IsZero() || !e.Time.Before(since)) {
			out = append(out, e)
		}
	}
	return out, nil
}

func ReadMemory() (Memory, error) {
	f, err := os.Open("/proc/meminfo")
	if err != nil {
		return Memory{}, err
	}
	defer f.Close()
	var m Memory
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		k, v, _ := strings.Cut(sc.Text(), ":")
		n, _ := strconv.ParseUint(strings.Fields(v + " 0")[0], 10, 64)
		switch k {
		case "MemTotal":
			m.Total = n * 1024
		case "MemAvailable":
			m.Available = n * 1024
		}
	}
	return m, nil
}

func cpuTimes() (idle, total uint64) {
	b, _ := os.ReadFile("/proc/stat")
	line, _, _ := strings.Cut(string(b), "\n")
	for i, f := range strings.Fields(line)[1:] {
		n, _ := strconv.ParseUint(f, 10, 64)
		total += n
		if i == 3 || i == 4 {
			idle += n
		}
	}
	return
}

func CPUPercent(sample time.Duration) (float64, error) {
	i1, t1 := cpuTimes()
	time.Sleep(sample)
	i2, t2 := cpuTimes()
	if t2 <= t1 {
		return 0, nil
	}
	return 100 * (1 - float64(i2-i1)/float64(t2-t1)), nil
}

func Disks() ([]Disk, error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs("/", &st); err != nil {
		return nil, err
	}
	return []Disk{{Mount: "/", Total: st.Blocks * uint64(st.Bsize), Free: st.Bavail * uint64(st.Bsize), System: true}}, nil
}

func BootTime() time.Time {
	b, _ := os.ReadFile("/proc/stat")
	for _, l := range strings.Split(string(b), "\n") {
		if strings.HasPrefix(l, "btime ") {
			n, _ := strconv.ParseInt(strings.TrimSpace(l[6:]), 10, 64)
			return time.Unix(n, 0)
		}
	}
	return time.Time{}
}

func jsonFixture(name string) map[string]string {
	m := map[string]string{}
	b, err := os.ReadFile(filepath.Join(fixtures(), name))
	if err == nil {
		_ = json.Unmarshal(b, &m)
	}
	return m
}

func ServiceState(name string) Service {
	st, ok := jsonFixture("services.json")[name]
	return Service{Name: name, Exists: ok, Running: st == "running", State: map[string]string{"running": "en marche", "stopped": "arrêté"}[st]}
}

func ServiceNames(prefix string) []string {
	var out []string
	for k := range jsonFixture("services.json") {
		if strings.HasPrefix(strings.ToLower(k), strings.ToLower(prefix)) {
			out = append(out, k)
		}
	}
	return out
}

func RegString(path, name string) string { return jsonFixture("registry.json")[path+`\`+name] }

func ProcessRunning(exe string) bool {
	dirs, _ := os.ReadDir("/proc")
	for _, d := range dirs {
		if c, err := os.ReadFile("/proc/" + d.Name() + "/comm"); err == nil && strings.EqualFold(strings.TrimSpace(string(c)), strings.TrimSuffix(exe, ".exe")) {
			return true
		}
	}
	return false
}

func DataDir() string {
	if d := os.Getenv("GUARDIAN_DATA"); d != "" {
		return d
	}
	return "/var/lib/marwa-guardian"
}
