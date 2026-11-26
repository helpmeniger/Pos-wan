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

	// Sauvegardes (phase 3)
	PosAppDir       string        `json:"pos_app_dir"`       // WampServer : dossier de l'application POS Marwa (contient storage\backups)
	MariaDBBin      string        `json:"mariadb_bin"`       // dossier des programmes MariaDB/MySQL (test de restauration) ; vide = celui de POS Marwa Bureau
	BackupWarnHours int           `json:"backup_warn_hours"` // dernière sauvegarde vérifiée plus ancienne : avertissement
	BackupCritHours int           `json:"backup_crit_hours"` // … critique
	RestoreTestDays int           `json:"restore_test_days"` // test de restauration au moins tous les N jours (0 = jamais automatiquement)
	Destinations    []Destination `json:"destinations"`      // copies des sauvegardes vérifiées
}

// Destination : endroit où MARWA GUARDIAN recopie les sauvegardes vérifiées (et la configuration de POS Marwa).
type Destination struct {
	ID    string `json:"id"`
	Kind  string `json:"kind"`  // dossier (autre disque, partage réseau \\serveur\partage) ou support (clé USB / disque externe reconnu par son nom)
	Path  string `json:"path"`  // dossier
	Label string `json:"label"` // nom du support
	Keep  int    `json:"keep"`  // nombre de sauvegardes gardées sur la destination
}

// Name : libellé affiché.
func (d Destination) Name() string {
	if d.Kind == "support" {
		return "Support « " + d.Label + " »"
	}
	return d.Path
}

func Defaults() Config {
	return Config{Port: 8750, DiskWarnPercent: 15, DiskCritPercent: 5, DiskCritBytes: 2 << 30, MemWarnPercent: 10, MemCritPercent: 5,
		CPUWarnPercent: 90, RebootsPerDayWarn: 3, MonitorPerformance: true, BackupWarnHours: 26, BackupCritHours: 72, RestoreTestDays: 7}
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
	if c.BackupWarnHours < 1 {
		c.BackupWarnHours = 26
	}
	if c.BackupCritHours < c.BackupWarnHours {
		c.BackupCritHours = c.BackupWarnHours * 3
	}
	return c, nil
}

// SaveConfig : écriture atomique (fichier temporaire puis remplacement : jamais de fichier à moitié écrit).
func SaveConfig(dir string, c Config) error {
	b, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	tmp := filepath.Join(dir, "guardian.json.tmp")
	if err := os.WriteFile(tmp, b, 0o640); err != nil {
		return err
	}
	return os.Rename(tmp, filepath.Join(dir, "guardian.json"))
}
