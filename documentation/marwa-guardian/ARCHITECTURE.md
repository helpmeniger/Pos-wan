# MARWA GUARDIAN — Phase 1 : audit et architecture

« Votre caisse protégée, vos données en sécurité. »

Document de phase 1 (26/11/2026). Rien n'est encore développé : ce document décrit ce qui existe, les contraintes
réelles et l'architecture proposée, à valider avant la phase 2.

---

## 1. Audit de l'existant

### 1.1 POS Marwa (ce dépôt)

| Élément | Réalité constatée | Conséquence pour MARWA GUARDIAN |
|---|---|---|
| Application | PHP 8.1+ sans framework, MariaDB/MySQL, interface web (Bootstrap) | Surveillance « boîte noire » + API applicative à ajouter |
| Édition Bureau | `POS Marwa.exe` (Go) : MariaDB portable (port **3407**, 127.0.0.1), serveurs PHP (ports **18800+**), répartiteur (**8740**), fenêtre Edge, pont e-SECeF (Node, **8760**) | Les ports et chemins sont lisibles dans `pos-marwa.ini` et `donnees\mariadb.ini` : à **lire**, jamais à supposer |
| Installation Bureau | NSIS, dossier par défaut `C:\POS Marwa`, registre `HKLM\Software\POSMarwaBureau\Dossier` | Détection fiable de l'installation Bureau |
| Installation WampServer | Ancienne installation : Apache + PHP + MySQL/MariaDB de Wamp (services `wampapache64`, `wampmysqld64` ou `wampmariadb64` selon la version) | Services à **découvrir** dans le gestionnaire de services, chemins dans la configuration de Wamp |
| Secrets | Accès base et `APP_KEY` chiffrés DPAPI portée « ordinateur » (`donnees\secret\bd.dat`) | Guardian **ne lit pas** ces secrets : il interroge POS Marwa par une API authentifiée |
| Sauvegardes | Existantes : export logique cohérent (PHP), SHA-256 par fichier, rotation, sauvegarde automatique (1 à 48 h), copie contrôlée sur clé USB reconnue par son nom, sauvegarde avant mise à jour | Guardian **ne duplique pas** : il vérifie, alerte, ajoute le **test de restauration** et la copie distante |
| Mises à jour | `UpdateService` : paquet vérifié, sauvegarde avant, journal `system.update` | Point d'entrée de la **liste blanche** des modifications de fichiers |
| Journal d'audit | `audit_logs` (connexions échouées `auth.failed`, restaurations, sauvegardes…) | Source pour les tentatives d'accès à l'application |
| Diagnostic | `/__bureau/diagnostic`, `diagnostic.txt`, MariaDB relancée automatiquement, chien de garde | Guardian reprend ces informations, sans les recopier |

### 1.2 Environnement de développement disponible ici

| Outil | État | Conséquence |
|---|---|---|
| Go 1.24 (compilation croisée Windows) | ✅ ; modules `golang.org/x/sys/windows` (service, journal d'événements, registre) et `modernc.org/sqlite` (SQLite sans C) téléchargeables | Service Windows et SQLite possibles **sans runtime à installer** |
| NSIS (`makensis`) | ✅ (déjà utilisé pour POS Marwa) | Installateur et désinstallateur Windows |
| Chromium + Playwright | ✅ | Essais automatiques de la console |
| .NET / C# / WPF | ❌ SDK absent, sites de téléchargement Microsoft bloqués par le réseau de cet environnement | C#/WPF **ne peut être ni compilé ni testé ici** |
| Ordinateur Windows | ❌ aucun dans cet environnement (Linux) | Les parties propres à Windows sont compilées ici mais **ne peuvent être exécutées qu'à la phase 7 sur un vrai PC** (le vôtre ou un PC de test) |

---

## 2. Architecture recommandée

### 2.1 Choix techniques (et pourquoi pas C#/WPF)

| Besoin | Choix | Raison |
|---|---|---|
| Service Windows | **Go** (`golang.org/x/sys/windows/svc`) | Un seul `.exe`, aucun runtime .NET à installer sur des PC modestes, ~15–30 Mo de mémoire, même langage que `POS Marwa.exe` (code et savoir-faire réutilisés), compilable et testable ici |
| Base locale | **SQLite** (`modernc.org/sqlite`, sans C) | Fichier unique, transactions, pas de serveur |
| Console | **Interface web locale** servie par l'agent sur `127.0.0.1`, ouverte dans une fenêtre Edge « application » (comme POS Marwa) | Interface moderne, testable automatiquement ici ; WPF impossible à compiler ici |
| Notifications | **Notificateur** dans la session de l'utilisateur (icône près de l'horloge + notifications Windows) | Un service Windows (session 0) **ne peut pas** afficher de fenêtre à l'utilisateur |
| Données Windows | Journaux d'événements (`wevtutil`, API EvtQuery), CIM/WMI, `Get-MpComputerStatus` (Defender), `Get-NetTCPConnection`, journal du pare-feu | Mécanismes natifs, aucun pilote noyau |
| IA | **Moteur de diagnostic local** (règles + faits enregistrés) | Hors ligne, gratuit, ne peut citer que des faits réellement enregistrés ; un modèle externe reste une option désactivée, après consentement |

### 2.2 Composants

```
┌─────────────────────────── PC équipé de POS Marwa ───────────────────────────┐
│                                                                              │
│  MarwaGuardian (service Windows, démarrage automatique, compte de service)   │
│   ├─ Collecteurs : système, arrêts anormaux, base, POS Marwa, fichiers,      │
│   │               accès / USB, réseau, Defender, ransomware, sauvegardes     │
│   ├─ Moteur d'incidents (gravité, déduplication, résolution)                 │
│   ├─ Moteur de diagnostic (IA locale)                                        │
│   ├─ SQLite : %ProgramData%\MarwaGuardian\guardian.db (journal chaîné)       │
│   └─ API locale 127.0.0.1:8750 (console, notificateur, POS Marwa ; jetons)   │
│                                                                              │
│  MarwaGuardian Notificateur (session utilisateur, démarre à l'ouverture)     │
│   └─ icône, notifications Windows, ouvre la console                          │
│                                                                              │
│  Console (fenêtre Edge sur 127.0.0.1:8750, mot de passe administrateur)      │
│                                                                              │
│  POS Marwa ◄── API authentifiée ──► état applicatif, sauvegardes, accès      │
└──────────────────────────────────────────────────────────────────────────────┘
          ▲ remontée des alertes (phase 6, chiffrée, appairage par code)
┌─────────┴─────────┐
│ Poste serveur     │  « Gestion des postes » : vue de tous les agents du réseau
└───────────────────┘
```

### 2.3 Sécurité de l'agent

- **Compte de service virtuel** `NT SERVICE\MarwaGuardian` (pas « Administrateur ») : membre de « Lecteurs des journaux
  d'événements », lecture seule sur le dossier POS Marwa, écriture seulement dans `%ProgramData%\MarwaGuardian`.
  Les réglages qui exigent l'administrateur (audit d'accès aux fichiers, journal du pare-feu) sont faits **une fois,
  par l'installateur, avec votre accord**, jamais par le service.
- **Journal infalsifiable sans le voir** : chaque incident porte l'empreinte du précédent (chaîne SHA-256) ; une
  modification ou une suppression est détectée et signalée.
- **API locale** : `127.0.0.1` uniquement, jeton par client (console, notificateur, POS Marwa), mot de passe de la
  console haché (Argon2id), limitation des essais.
- **Jamais** : collecte de mots de passe, lecture du contenu des ventes ou des cartes, envoi de données vers
  l'extérieur sans consentement, désactivation de Defender ou du pare-feu, arrêt d'un processus, suppression ou mise en
  quarantaine automatique d'un fichier, blocage d'une adresse IP sans politique validée.
- **Mises à jour de Guardian** : paquet signé (Ed25519), vérifié avant installation.

---

## 3. Ce que Windows permet réellement (limites annoncées)

| Demande | Ce qui est possible | Limite honnête |
|---|---|---|
| Arrêts anormaux | Événements Kernel-Power 41, EventLog 6008/6005/6006, User32 1074, BugCheck 1001, WHEA (erreurs matérielles) | Une **coupure de courant** n'est jamais certaine : « arrêt sans fermeture propre, sans écran bleu ni appui long sur le bouton → coupure d'alimentation **probable** » |
| Santé des disques | `MSFT_PhysicalDisk` / `Get-StorageReliabilityCounter`, événements disque 7/11/51/153, NTFS 55 | SMART et températures **seulement si le disque et le pilote les fournissent** |
| Températures | Capteurs ACPI (`MSAcpi_ThermalZoneTemperature`) | Absents sur beaucoup de PC : affiché « non disponible », jamais inventé |
| Copie vers une clé USB | Audit Windows de l'accès aux fichiers (événement 4663, à activer à l'installation) + arrivée des supports amovibles + fichiers apparus sur le support | Windows **ne dit pas** « copié vers E: ». Guardian **déduit** une copie probable (lectures massives par l'Explorateur + mêmes noms et tailles apparus sur la clé), avec un niveau de confiance |
| Identité de la personne | Compte Windows et nom de l'ordinateur | Pas l'identité physique : un compte partagé reste partagé |
| Réseau | Connexions et ports à l'écoute, table ARP (appareils déjà vus), journal du pare-feu, connexions échouées de POS Marwa | **Aucun scan actif** du réseau, aucune écoute du contenu des communications |
| Antivirus | État et détections de Microsoft Defender (journal Defender 1116/1117/5001) | Avec un autre antivirus, seul son état « actif / à jour » (Centre de sécurité) est lisible |
| Rançongiciels | Modifications massives, changements d'extension, fichiers « appâts » dans le dossier des sauvegardes, suppressions de sauvegardes | Détection **comportementale** : Guardian alerte et guide ; il ne remplace pas Defender |
| Test de restauration | Restauration de la dernière sauvegarde dans une base **séparée** (`poswan_verif`) puis comparaison | Possible sur l'édition Bureau et sur WampServer si le compte MySQL le permet |

---

## 4. Phases de développement

| Phase | Contenu | Testé ici (Linux) | Testé sur Windows |
|---|---|---|---|
| 1 | Audit, architecture (ce document) | — | — |
| 2 | Service (installation, démarrage auto, arrêt propre), SQLite, incidents, système (CPU, RAM, disques, services), **arrêts anormaux**, POS Marwa (base, application), notificateur, console tableau de bord | Moteur, règles, analyse d'événements (exemples au format Windows), console, API | Service, journaux réels, notifications : **à la phase 7** |
| 3 | Sauvegardes : contrôle (fraîcheur, empreintes), test de restauration, copie distante (option), alertes — **fait (0.3.0), voir RAPPORT_PHASE3.md** | ✅ avec MariaDB | Chemins Windows |
| 4 | Intégrité des fichiers (SHA-256, liste blanche des mises à jour), accès et USB, réseau, Defender, rançongiciels | Moteur, empreintes, scénarios simulés | Audit Windows, USB, pare-feu |
| 5 | Assistant IA local (questions en français, faits et hypothèses séparés, niveau de confiance) | ✅ | — |
| 6 | Intégration POS Marwa (API, état dans le diagnostic), gestion des postes (appairage, remontée chiffrée) | ✅ | Réseau réel |
| 7 | Tests de pannes : arrêt brutal, base arrêtée, disque plein, fichier modifié, copie USB, sauvegarde ratée | Scénarios simulés | **Sur votre PC ou un PC de test** (indispensable) |
| 8 | Installateur / désinstallateur NSIS, documentation (technique, installation, utilisation, dépannage), rapport final | Construction | Installation réelle |

Chaque phase se termine par : code, tests automatiques, rapport de ce qui a réellement été testé, et votre validation.

---

## 5. Points à valider avant la phase 2

1. **Langage** : Go (recommandé) au lieu de C#/.NET — C#/WPF ne peut être ni compilé ni testé dans cet environnement.
2. **Console** : interface web locale dans une fenêtre Edge (recommandé) au lieu de WPF.
3. **Distribution** : MARWA GUARDIAN livré **avec** l'installateur POS Marwa Bureau (option cochée), **et** en
   installateur séparé (postes WampServer, postes sans POS Marwa Bureau).
4. **Audit des accès aux fichiers et journal du pare-feu** : activés par l'installateur (case à cocher, administrateur),
   nécessaires aux alertes de copie et de connexions refusées.
5. **Information du personnel** : la surveillance des accès et des clés USB concerne des employés ; ils doivent en être
   informés (loi n° 2017-28 sur la protection des données à caractère personnel). Guardian affichera la liste de ce
   qu'il surveille, et l'administrateur pourra désactiver la surveillance non essentielle.
6. **Essais sur Windows** : prévoir un PC Windows 10 ou 11 de test (ou le PC de la boutique, hors heures d'ouverture)
   pour la phase 7.

---

## 6. Décisions (26/11/2026)

| Point | Décision |
|---|---|
| Langage et interface | Go + console web locale (option recommandée retenue, sans préférence exprimée) |
| Distribution | Option de l'installateur POS Marwa Bureau **et** installateur séparé (option recommandée retenue, sans préférence exprimée) |
| Audit des accès et journal du pare-feu | Proposés par l'installateur, **cochés par défaut**, désactivables ; information du personnel rappelée |
| Essais Windows (phase 7) | Sur un **PC de test** Windows 10/11 sans données réelles |
