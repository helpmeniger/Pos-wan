# MARWA GUARDIAN — Rapport de la phase 2 (prototype fonctionnel)

26/11/2026 — version 0.2.0. Code : `guardian/` (Go). Construction : `guardian/construire.sh <sortie> <version>`.

## 1. Ce qui est développé

| Fonction | Fichiers | État |
|---|---|---|
| Service Windows « MarwaGuardian » : démarrage automatique, relance automatique en cas d'arrêt, arrêt propre | `system_windows.go` | Développé, **compilé pour Windows, non exécuté** (pas de PC Windows ici) |
| Installation : compte virtuel `NT SERVICE\MarwaGuardian` (pas administrateur), dossier de données réservé (SYSTEM, Administrateurs, service), lecture seule sur le dossier POS Marwa, groupe « Lecteurs des journaux d'événements », notificateur à l'ouverture de session, désinstallation (historique conservé) | `system_windows.go` | Développé, **non exécuté** |
| Base locale SQLite, incidents (4 gravités, un seul incident ouvert par problème, aggravation, résolution automatique ou par l'administrateur) | `internal/store` | ✅ testé |
| Historique infalsifiable sans le voir : chaînes SHA-256 des incidents et du journal, contrôle horaire, incident critique en cas de modification | `internal/store`, `CheckIntegrity` | ✅ testé (modification, suppression, journal modifié) |
| Système : espace des disques (seuils configurables), mémoire, processeur (moyenne de 5 mesures), mesures gardées 7 jours | `CheckSystem`, `internal/platform` | ✅ testé sous Linux ; API Windows écrites, **non exécutées** |
| **Arrêts anormaux** : Kernel-Power 41, EventLog 6008/6006/6005, User32 1074, écran bleu 1001, erreurs matérielles WHEA ; nature (plantage, matériel, forcé, coupure probable, indéterminé) et niveau de certitude ; onduleur conseillé quand cela se répète ; journal de MariaDB lu au redémarrage (récupération, corruption) | `internal/core/shutdown.go`, `CheckShutdowns` | ✅ testé sur des exemples au format de `wevtutil` (voir § 3) |
| Erreurs de disque (disk 7/11/51/153, NTFS 55/98), redémarrages inhabituels (6005) | `CheckDiskErrors`, `CheckReboots` | ✅ testé (exemples) |
| Services Windows essentiels : journal, WMI, pare-feu, Defender (s'il existe), DHCP, DNS | `CheckServices` | ✅ testé (exemples) ; lecture Windows **non exécutée** |
| POS Marwa : installation détectée et **vérifiée** (registre, `pos-marwa.ini`, port réel dans `donnees\mariadb.ini`), application qui répond, base qui répond, incidents « Base de données arrêtée » / « POS Marwa ne répond pas », résolution automatique ; WampServer : services découverts (`wamp…`) | `CheckPos` | ✅ testé (faux POS Marwa : base coupée puis revenue) |
| API locale : 127.0.0.1 seulement, nom d'hôte vérifié, origine vérifiée, mot de passe Argon2id, 5 essais / 5 min, session 30 min, jeton anti-falsification, jeton du notificateur limité aux alertes | `internal/web/server.go` | ✅ testé |
| Console : premier mot de passe, tableau de bord (état général, POS Marwa, base, disque, mémoire, processeur, arrêts sur 30 jours, services, intégrité), alertes, détail (problème, certitude, conséquences, que faire, faits), « Marquer comme résolu » avec note, historique, journal et intégrité, page « ce que Guardian surveille » | `internal/web/console` | ✅ testé dans Chromium (aucune erreur JavaScript) |
| Notificateur : interroge l'agent toutes les 20 s, chaque alerte une seule fois ; Windows : notification du centre de notifications (« Voir les détails » ouvre la console), boîte de message de secours pour les alertes graves | `notifier.go`, `system_windows.go` | ✅ testé sous Linux (texte) ; notification Windows **non exécutée** |

## 2. Tests réellement lancés

- `go test ./...` : 4 paquets, **16 tests**, tous réussis (stockage, falsification, analyse des arrêts, contrôles, POS Marwa, API et sécurité).
- Essai de bout en bout sous Linux : agent lancé (`guardian run`), faux POS Marwa (application + base), base arrêtée au bout de 45 s :
  « Base de données arrêtée » détectée en moins de 30 s et notifiée ; notificateur : 8 alertes initiales puis seulement la nouvelle ;
  console pilotée par Chromium : premier mot de passe, tableau de bord, détail, résolution avec note, journal intact.
- Compilation Windows 64 bits : `MarwaGuardian.exe` et `MarwaGuardian-Notificateur.exe` (11 Mo chacun, aucun runtime).

## 3. Non testé (à faire en phase 7 sur le PC de test Windows)

1. Installation, démarrage automatique, relance et arrêt du service ; droits du compte virtuel (lecture du journal Système, du dossier POS Marwa).
2. Lecture réelle des journaux par `wevtutil` : les exemples d'événements ont été **écrits d'après le format documenté de Windows**, ils ne proviennent pas d'un vrai PC.
3. Mesures Windows (mémoire, processeur, disques, démarrage, services, processus).
4. Notifications Windows (méthode PowerShell / WinRT) et ouverture de la console dans Edge.
5. Scénarios réels : arrêt brutal (débrancher le PC de test), base arrêtée, disque rempli.

## 4. Limites connues de la phase 2

- Le **premier mot de passe** peut être choisi depuis la console tant qu'aucun n'existe ; l'installateur (phase 8) le demandera à l'installation.
- Températures, SMART, batterie, réseau, Defender détaillé, fichiers, sauvegardes, IA : phases 3 à 6 (la console l'indique).
- Le compteur « arrêts anormaux sur 30 jours » compte les incidents détectés par Guardian (pas les arrêts antérieurs à son installation au-delà de 7 jours).
