# Architecture technique

## 1. Processus

| Processus | Technologie | Rôle | Fichiers |
|---|---|---|---|
| Serveur web | Apache + PHP 8.2+ (WampServer) | interface, API JSON, flux SSE, rapports | `public/`, `src/` |
| Base de données | MySQL 8 / MariaDB 10.6+ | état partagé, file de tâches | `database/schema.sql` |
| Moteur de surveillance | Node.js 18+ / Playwright 1.56 | pilote un navigateur par session | `monitor/src/` |
| Moteur d'analyse | PHP CLI (1 à n processus) | consomme la file `analysis_jobs` | `bin/worker.php`, `src/Analysis/` |

Les moteurs ne dépendent pas d'Apache pour survivre : si l'interface est fermée, la surveillance et l'analyse continuent.
Si le moteur de surveillance perd le contact avec le serveur plus de 60 s, il ferme les navigateurs par sécurité
(l'arrêt immédiat doit toujours rester effectif).

## 2. Pilotage des sessions : état demandé / état réel

L'interface n'agit jamais directement sur un navigateur. Elle écrit l'**état demandé** (`desired_state`) ;
le moteur de surveillance interroge `api/monitor.php?action=poll` chaque seconde, converge vers cet état et
rapporte l'**état réel** (`run_state`, `connection_state`, message, URL courante).

```
desired_state : stopped ─start─► running ◄─resume─ paused
                   ▲               │  └────pause────►  │
                   └──── stop ─────┴───────────────────┘        close ─► closed (définitif, alertes conservées)

run_state     : stopped → starting → running ⇄ paused (utilisateur, CAPTCHA, connexion, limite atteinte)
                                         └─► error (après 3 redémarrages automatiques) ─ Redémarrer ─► starting
```

Commandes ponctuelles (`session_commands`, délivrées une seule fois) : `scan_once` (analyser les publications
visibles maintenant), `focus` (ramener la fenêtre au premier plan), `restart`, `reload_config`.

**Arrêt immédiat** : `application_settings.emergency_stop = 1` + toutes les sessions passent à `stopped`.
Le moteur ferme tous les navigateurs dès son interrogation suivante (cycle d'une seconde ; quelques secondes au total, mesuré dans le test de bout en bout) et refuse tout démarrage tant que l'arrêt n'est pas levé.

## 3. Moteur de surveillance (une boucle indépendante par session)

Pour chaque session, `SessionRunner` :

1. ouvre un **contexte persistant** Chromium (`storage/profiles/session-N`, fenêtre positionnable) ;
2. à chaque cycle (`processing_interval_ms`) :
   - `detectBlockers()` : CAPTCHA (iframes reCAPTCHA/hCaptcha/Cloudflare, textes « je ne suis pas un robot »),
     connexion requise (URL de login, champ mot de passe sans fil), blocage (« trop de requêtes », 403/429)
     → **suspension** + message ; reprise automatique quand l'obstacle a disparu (résolu par l'humain) ;
   - `extractVisiblePosts()` : publications **dans la zone visible** (adaptateurs `facebook`, `x`, `generic`) :
     texte, lien permanent, auteur et date affichés, liens sortants, images de contenu (≥ 200 px) ;
   - pour chaque publication nouvelle (clé = lien permanent ou empreinte du texte) : capture JPEG de la zone
     visible (miniature), capture de chaque image entièrement visible, envoi **une publication par requête** ;
     la clé n'est marquée « vue » qu'après accusé de réception du serveur ;
   - limites : nombre maximal de publications et durée maximale → défilement arrêté, session suspendue ;
   - défilement : `scrollBy(pas)`, puis si la hauteur du document change (chargement de contenus),
     **pause** jusqu'à 1,2 s sans mutation du DOM (10 s max), puis attente `scroll_delay_ms` ;
3. capture un aperçu basse résolution toutes les 10 s pour la grille des sessions (`storage/run/screens`, écrasé).

Aucune action autre que le défilement n'est simulée : pas de clic, pas de saisie, pas de lecture des cookies.

## 4. Réception et dédoublonnage (`PublicationIngestor`)

- Empreinte : SHA-256 du lien permanent normalisé (sans paramètres de suivi `utm_*`, `fbclid`, `__cft__`…),
  sinon de la clé fournie par le site, sinon du texte normalisé (minuscules, sans accents ni ponctuation) par site.
- `detected_publications.fingerprint` est **unique** ; la même publication vue par d'autres sessions ajoute une ligne
  dans `publication_occurrences` (unique par publication et session) et incrémente `occurrences`.
  `first_session_id` conserve la session d'origine.
- Concurrence : deux sessions peuvent envoyer la même publication au même instant ; une violation de clé unique ou
  un interblocage InnoDB fait rejouer la transaction (testé : 4 processus × 1 000 publications identiques).
- Images : contenu vérifié par `getimagesizefromstring` (PNG, JPEG, WebP, GIF), 3 Mo max, 4 par publication,
  stockées hors de la racine web (`storage/images/AAAA/MM/`), servies par `media.php` aux seuls utilisateurs connectés.
- Mise en file : priorité 3 (manuelle), 4 (avec image), 5 (texte).

## 5. File de tâches (`JobQueue`)

- Table `analysis_jobs` ; réservation atomique `UPDATE … WHERE status='pending' AND available_at<=now ORDER BY priority, id LIMIT 1`
  avec un identifiant de verrou unique : plusieurs workers en parallèle sans double traitement (testé).
- Échec : nouvelle tentative après 15 s, 30 s, puis statut `failed` (3 essais) et événement dans le journal.
- Worker disparu (verrou de plus de 10 min) : tâche remise en file.
- Interblocages InnoDB : transaction rejouée (`Database::transaction`), instruction isolée rejouée (`Database::run`).

## 6. Analyse (`AnalysisEngine`)

1. **Images** : SHA-256, dHash et aHash 64 bits (GD), EXIF utiles, OCR (si Tesseract), comparaison avec les images
   antérieures : identique (SHA-256) ou perceptuelle (dHash **et** aHash ≤ seuil, 10 par défaut).
2. **Texte** (`TextAnalyzer`) : catégorie parmi 14 (lexiques), sujet, marqueurs d'opinion / satire / publicité / rumeur,
   affirmations vérifiables (phrases avec chiffres, verbes factuels, noms propres ; pas les questions ni les opinions),
   dates (formats numériques, mois en toutes lettres, « en 2019 »), lieux probables, sources citées (« selon… », liens).
3. **Indices** (poids) : sensationnalisme (1–3), incitation au partage (2), rhétorique de dissimulation (2),
   promesse miracle (3), majuscules (1), ponctuation (1), rumeur (2), affirmations sans source (1),
   incohérence temporelle « aujourd'hui » + date ancienne (2), date improbable (1),
   **image déjà rencontrée (4)**, image réutilisée présentée comme actuelle (2),
   **affirmation identique à une affirmation contredite (4)**, **texte proche d'une publication contredite (4)**,
   texte proche d'une publication déjà signalée (2).
4. **Priorité** (`PriorityScorer`) :
   - faible : rien de notable ;
   - normale : affirmation vérifiable ou score ≥ 2 ;
   - élevée : score ≥ 4 ou indice fort (image réutilisée, contradiction) ;
   - critique : ≥ 3 indices de poids ≥ 2 **et** score ≥ 8.
   Opinion et satire : poids de style divisés par deux ; satire plafonnée à « normale » sauf indice fort.
5. **Enregistrement** : `analysis_results` (indices, dates, lieux, sources, similarités, limites), `claims`.
6. **Alerte** si priorité ≥ `alert_min_priority` (par défaut « élevée »). Une réanalyse met l'alerte à jour sans
   jamais modifier le statut décidé par un humain.

## 7. Centre d'alertes

- `alerts` : une alerte par publication ; nom de session et de site **copiés** dans l'alerte, clé étrangère
  `ON DELETE SET NULL` vers la session : l'alerte survit à la fermeture et à la suppression de la session.
  `ON DELETE RESTRICT` vers la publication : la purge automatique ne peut pas supprimer une publication signalée.
- `alert_history` trace chaque changement (création, lecture, affectation, statut, rapport, réanalyse).
- Temps réel : `api/stream.php` (Server-Sent Events, 25 s par connexion puis reconnexion avec `Last-Event-ID`) ;
  repli automatique sur une interrogation toutes les 4 s.
- Rapports : `ReportService` (Dompdf, rendu sans accès réseau, images intégrées), copie conservée dans
  `storage/reports` avec empreinte SHA-256 dans `reports`.

## 8. Base de données (21 tables)

`roles`, `users`, `monitored_sites`, `monitoring_sessions`, `session_commands`, `detected_publications`,
`publication_occurrences`, `publication_content`, `analysis_jobs`, `analysis_results`, `claims`,
`evidence_sources`, `image_assets`, `image_matches`, `alerts`, `alert_history`, `reports`, `system_events`,
`audit_logs`, `application_settings`, `service_heartbeats`.
Toutes les dates sont stockées en UTC (`time_zone = '+00:00'` à la connexion) et affichées dans le fuseau configuré.

## 9. Sécurité

| Menace | Mesure |
|---|---|
| Injection SQL | requêtes préparées partout (`PDO::ATTR_EMULATE_PREPARES = false`) ; colonnes et tris jamais issus de l'utilisateur ; jokers `LIKE` échappés |
| XSS | échappement systématique (`e()` côté PHP, `V.esc()` côté JS) ; CSP `script-src 'self'` sans script en ligne |
| CSRF | jeton de session sur tout POST (formulaire ou en-tête `X-CSRF-Token`) + contrôle de l'en-tête `Origin` ; cookie `SameSite=Strict`, `HttpOnly` |
| Accès | rôles admin / analyst / viewer vérifiés côté serveur sur chaque page et API ; verrouillage après 5 échecs de connexion ; première création d'administrateur limitée au poste local |
| API du moteur | jeton aléatoire de 256 bits comparé à temps constant **et** adresse de boucle locale obligatoire |
| Fichiers | `storage/`, `src/`, `config/`, `vendor/` interdits par `.htaccess` ; `media.php` n'accepte qu'un motif de chemin strict, vérifié par `realpath` ; type d'image contrôlé sur le contenu |
| Traçabilité | `audit_logs` (connexions, actions sur sessions et alertes, sources, verdicts, paramètres, suppressions) |
| Données | aucun mot de passe de site tiers ; cookies jamais lus ; purge par ancienneté ; effacement complet |

## 10. Points d'extension

- **Adaptateur de site** : `monitor/src/extractor.js` (`extractVisiblePosts`, branche selon `adapter`) et
  `SessionService::siteFor()` pour l'associer à un domaine.
- **Lexiques** : `src/Analysis/Lexicon.php`.
- **Nouvel indice** : `TextAnalyzer::analyze()` ou `AnalysisEngine::analyze()` ; pondération dans `PriorityScorer`.
- **Connecteur de recherche non social** (moteur de recherche avec clé API, base de vérifications) : à brancher
  dans `AnalysisEngine` pour remplir `evidence_sources` en indiquant la méthode ; non fourni dans cette version.
