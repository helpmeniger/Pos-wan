package core

import (
	"fmt"
	"sort"
	"strings"
	"time"

	"marwa/guardian/internal/platform"
)

// Événements du journal « Système » utiles à l'analyse des arrêts.
var ShutdownEventIDs = []int{41, 1074, 6005, 6006, 6008, 1001, 18, 19, 46, 47}

const (
	provKernelPower = "Microsoft-Windows-Kernel-Power"
	provWER         = "Microsoft-Windows-WER-SystemErrorReporting" // 1001 : écran bleu (BugCheck)
	provWHEA        = "Microsoft-Windows-WHEA-Logger"              // erreurs matérielles
	provEventLog    = "EventLog"                                   // 6005 démarrage, 6006 arrêt propre, 6008 arrêt inattendu
	provUser32      = "User32"                                     // 1074 : arrêt ou redémarrage demandé
)

// ShutdownKind : nature probable d'un arrêt anormal.
type ShutdownKind string

const (
	KindCrash    ShutdownKind = "plantage"     // écran bleu enregistré
	KindHardware ShutdownKind = "materiel"     // erreur matérielle fatale signalée (WHEA)
	KindForced   ShutdownKind = "force"        // appui long sur le bouton d'alimentation
	KindPower    ShutdownKind = "alimentation" // coupure d'alimentation probable (aucune autre trace)
	KindUnknown  ShutdownKind = "indetermine"  // arrêt inattendu sans indice suffisant
)

// Shutdown : un arrêt anormal reconstitué à partir des événements disponibles.
type Shutdown struct {
	At         time.Time // heure de l'événement de redémarrage (Kernel-Power 41 ou EventLog 6008)
	Kind       ShutdownKind
	Confidence string            // « certain », « probable », « possible »
	Evidence   []string          // événements cités, tels qu'ils existent
	Details    map[string]string // valeurs lues (code d'arrêt, bouton…)
}

// AnalyzeShutdowns regroupe les événements (fenêtre de 15 minutes autour de chaque Kernel-Power 41 / EventLog 6008)
// et attribue à chaque arrêt anormal une nature probable. Règles, de la plus sûre à la moins sûre :
//   - écran bleu (1001) ou code d'arrêt (BugcheckCode ≠ 0)          → plantage de Windows (certain)
//   - erreur matérielle fatale (WHEA 18, 46, 47)                     → erreur matérielle (probable)
//   - appui long sur le bouton (LongPowerButtonPressDetected = true) → arrêt forcé (certain)
//   - bouton pressé (PowerButtonTimestamp ≠ 0)                       → arrêt forcé (probable)
//   - aucune trace : arrêt brutal sans écran bleu ni bouton          → coupure d'alimentation PROBABLE
//     (le même tableau se produit aussi avec un blocage complet du PC : ce n'est jamais certain)
//   - 6008 seul (sans Kernel-Power 41 lisible)                        → indéterminé (possible)
//
// Un arrêt propre (6006) ou demandé (1074) n'est jamais compté.
func AnalyzeShutdowns(events []platform.Event) []Shutdown {
	sort.Slice(events, func(i, j int) bool { return events[i].Time.Before(events[j].Time) })
	var anchors []platform.Event
	for _, e := range events {
		if (e.ID == 41 && e.Provider == provKernelPower) || (e.ID == 6008 && e.Provider == provEventLog) {
			anchors = append(anchors, e)
		}
	}
	var out []Shutdown
	used := map[int64]bool{}
	for _, a := range anchors {
		if used[a.RecordID] {
			continue
		}
		// Les 41 et 6008 d'un même arrêt sont écrits au redémarrage, à quelques secondes d'écart : un seul arrêt
		group := []platform.Event{a}
		for _, b := range anchors {
			if b.RecordID != a.RecordID && !used[b.RecordID] && absDur(b.Time.Sub(a.Time)) <= 15*time.Minute {
				group = append(group, b)
			}
		}
		for _, g := range group {
			used[g.RecordID] = true
		}
		s := Shutdown{At: a.Time, Details: map[string]string{}}
		var kp *platform.Event
		for i := range group {
			s.Evidence = append(s.Evidence, describe(group[i]))
			if group[i].ID == 41 {
				kp = &group[i]
			}
		}
		// Indices voisins : écran bleu et erreurs matérielles enregistrés au redémarrage (après l'arrêt) ou juste avant
		var bsod, whea *platform.Event
		for i := range events {
			e := events[i]
			if absDur(e.Time.Sub(a.Time)) > 15*time.Minute {
				continue
			}
			if e.ID == 1001 && e.Provider == provWER {
				bsod = &events[i]
			}
			if e.Provider == provWHEA && (e.ID == 18 || e.ID == 46 || e.ID == 47) {
				whea = &events[i]
			}
		}
		bug := ""
		if kp != nil {
			bug = strings.TrimSpace(kp.Data["BugcheckCode"])
			// Valeurs de l'événement Kernel-Power 41, sous un libellé français (nom Windows entre parenthèses)
			for k, label := range map[string]string{"BugcheckCode": "Code d'erreur Windows (BugcheckCode)", "PowerButtonTimestamp": "Bouton d'alimentation pressé (PowerButtonTimestamp)",
				"LongPowerButtonPressDetected": "Appui long sur le bouton (LongPowerButtonPressDetected)", "SleepInProgress": "Mise en veille en cours (SleepInProgress)"} {
				if v, ok := kp.Data[k]; ok {
					s.Details[label] = v
				}
			}
		}
		switch {
		case bsod != nil || (bug != "" && bug != "0"):
			s.Kind, s.Confidence = KindCrash, "certain"
			if bsod != nil {
				s.Evidence = append(s.Evidence, describe(*bsod))
				if v := bsod.Data["param1"]; v != "" {
					s.Details["Code d'arrêt"] = v
				} else if len(bsod.Values) > 0 {
					s.Details["Code d'arrêt"] = bsod.Values[0]
				}
			}
		case whea != nil:
			s.Kind, s.Confidence = KindHardware, "probable"
			s.Evidence = append(s.Evidence, describe(*whea))
		case kp != nil && strings.EqualFold(kp.Data["LongPowerButtonPressDetected"], "true"):
			s.Kind, s.Confidence = KindForced, "certain"
		case kp != nil && kp.Data["PowerButtonTimestamp"] != "" && kp.Data["PowerButtonTimestamp"] != "0":
			s.Kind, s.Confidence = KindForced, "probable"
		case kp != nil:
			s.Kind, s.Confidence = KindPower, "probable"
		default:
			s.Kind, s.Confidence = KindUnknown, "possible"
		}
		out = append(out, s)
	}
	return out
}

func absDur(d time.Duration) time.Duration {
	if d < 0 {
		return -d
	}
	return d
}

func describe(e platform.Event) string {
	return fmt.Sprintf("%s — %s, événement %d", e.Time.Local().Format("02/01/2006 15:04:05"), e.Provider, e.ID)
}

// ShutdownIncident : texte de l'incident selon la nature de l'arrêt (faits et hypothèses distingués).
func ShutdownIncident(s Shutdown, recent int) (title, problem, consequences string, recos []string, severity int) {
	when := s.At.Local().Format("02/01/2006 à 15:04")
	consequences = "Un arrêt brutal peut endommager la base de données du logiciel de caisse ou faire perdre la dernière vente en cours d'enregistrement."
	recos = []string{
		"Ouvrez POS Marwa et vérifiez que la dernière vente de la journée est bien enregistrée.",
		"Lancez une sauvegarde dès que possible (POS Marwa → Sauvegardes).",
	}
	severity = 2
	switch s.Kind {
	case KindCrash:
		title = "Plantage de Windows (écran bleu)"
		problem = fmt.Sprintf("Windows s'est arrêté sur une erreur grave le %s (écran bleu enregistré dans les journaux).", when)
		recos = append(recos, "Si cela se répète : faites vérifier le PC (pilotes, mémoire, disque) par un technicien en lui donnant le code d'arrêt.")
		severity = 3
	case KindHardware:
		title = "Arrêt causé par une erreur matérielle probable"
		problem = fmt.Sprintf("L'ordinateur s'est arrêté brutalement le %s ; Windows a signalé une erreur matérielle juste avant ou au redémarrage.", when)
		recos = append(recos, "Faites contrôler le matériel (mémoire, processeur, carte mère, alimentation) : une panne peut s'aggraver.")
		severity = 3
	case KindForced:
		title = "Arrêt forcé par le bouton d'alimentation"
		problem = fmt.Sprintf("L'ordinateur a été éteint en maintenant le bouton d'alimentation le %s (%s d'après les journaux).", when, s.Confidence)
		recos = append(recos, "Évitez d'éteindre en maintenant le bouton : fermez POS Marwa puis utilisez Démarrer → Arrêter. Si le PC était bloqué, notez-le : un blocage qui se répète doit être diagnostiqué.")
	case KindPower:
		title = "Arrêt inattendu : coupure de courant probable"
		problem = fmt.Sprintf("Votre ordinateur s'est arrêté sans fermeture normale le %s. Aucun écran bleu ni appui sur le bouton d'alimentation n'est enregistré : une coupure d'alimentation est probable, sans être certaine (un blocage complet du PC laisse les mêmes traces).", when)
		recos = append(recos, "Vérifiez la prise, la multiprise et le câble d'alimentation.")
		if recent >= 2 {
			recos = append(recos, "Les arrêts brutaux se répètent : utilisez un onduleur adapté à l'ordinateur et aux équipements réseau (box, routeur), qui laisse le temps de fermer proprement en cas de coupure.")
		} else {
			recos = append(recos, "Si les coupures se répètent, un onduleur adapté à votre ordinateur et à vos équipements réseau est recommandé.")
		}
	default:
		title = "Arrêt inattendu de l'ordinateur"
		problem = fmt.Sprintf("Windows signale que l'arrêt précédent (avant le %s) était inattendu. Les journaux disponibles ne permettent pas d'en déterminer la cause.", when)
	}
	if recent >= 3 {
		severity = 3
		problem += fmt.Sprintf(" C'est le %de arrêt anormal en 30 jours.", recent)
	}
	return
}
