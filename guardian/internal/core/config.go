package core

import (
	"encoding/json"
	"os"
	"path/filepath"
)

// Config : réglages de l'agent (guardian.json dans le dossier de données). Valeurs absentes = valeurs par défaut.
type Config struct {
	Port int `json:"port"` // API locale et console (127.0.0.1)

	// POS Marwa : détecté automatiquement (registre de l'édition Bureau, services de WampServer). Un chemin saisi ici
	// est prioritaire ; il est toujours vérifié avant usage.
	PosDir string `json:"pos_dir"`

	// Seuils
	DiskWarnPercent   float64 `json:"disk_warn_percent"` // espace libre sous ce pourcentage : avertissement
	DiskCritPercent   float64 `json:"disk_crit_percent"` // … critique
	DiskCritBytes     uint64  `json:"disk_crit_bytes"`   // ou moins que ce nombre d'octets libres : critique
	MemWarnPercent    float64 `json:"mem_warn_percent"`  // mémoire disponible sous ce pourcentage : avertissement
	MemCritPercent    float64 `json:"mem_crit_percent"`
	CPUWarnPercent    float64 `json:"cpu_warn_percent"`     // utilisation moyenne sur 5 mesures au-dessus : avertissement
	RebootsPerDayWarn int     `json:"reboots_per_day_warn"` // démarrages de Windows en 24 h au-dessus : avertissement

	// Surveillance non essentielle désactivable (les protections critiques ne le sont pas)
	MonitorPerformance bool `json:"monitor_performance"`
}

func Defaults() Config {
	return Config{Port: 8750, DiskWarnPercent: 15, DiskCritPercent: 5, DiskCritBytes: 2 << 30, MemWarnPercent: 10, MemCritPercent: 5,
		CPUWarnPercent: 90, RebootsPerDayWarn: 3, MonitorPerformance: true}
}

// LoadConfig lit guardian.json (et le crée avec les valeurs par défaut s'il n'existe pas).
func LoadConfig(dir string) (Config, error) {
	c := Defaults()
	p := filepath.Join(dir, "guardian.json")
	b, err := os.ReadFile(p)
	if os.IsNotExist(err) {
		out, _ := json.MarshalIndent(c, "", "  ")
		return c, os.WriteFile(p, out, 0o640)
	}
	if err != nil {
		return c, err
	}
	if err := json.Unmarshal(b, &c); err != nil {
		return Defaults(), err
	}
	if c.Port < 1024 || c.Port > 65535 {
		c.Port = 8750
	}
	return c, nil
}
