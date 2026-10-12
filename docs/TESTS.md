# Tests exécutés et résultats

Résultats obtenus le 12 octobre 2026 sur le code de ce dépôt, **exécutés réellement** (aucun résultat simulé).

## Environnement de test

| Élément | Version utilisée | Version cible WampServer |
|---|---|---|
| Système | Linux (conteneur de développement) | Windows 10/11 |
| PHP | 8.3.6 CLI | 8.2+ |
| Base | MariaDB 10.11.14 | MySQL 8 / MariaDB 10.6+ |
| Serveur web | serveur intégré PHP (`php -S`, 8 workers) | Apache (WampServer) |
| Node.js | 22.22.0 | 20 LTS+ |
| Playwright / Chromium | 1.56.1 / 141.0.7390.37 (headless) | 1.56.1 / Chromium ou Edge (fenêtres visibles) |
| Dompdf | 3.1.6 | idem |

## 1. Tests unitaires et d'intégration (PHPUnit)

```
vendor/bin/phpunit          # base « verifia_test » EFFACÉE et recréée à partir de database/schema.sql
```
Variables : `VERIFIA_TEST_DSN`, `VERIFIA_TEST_DB_USER`, `VERIFIA_TEST_DB_PASS` (défaut : root sans mot de passe).

Résultat : **OK (49 tests, 193 assertions)** en ~5 s.

```
Analysis Flow (Verifia\Tests\Integration\AnalysisFlow)
 ✔ Old image presented as current raises alert with reference
 ✔ Exact same image match
 ✔ Alerts survive session close and deletion
 ✔ Contradicted claim propagates to new publications
 ✔ Minimum alert priority setting
 ✔ No automatic external sources are invented
 ✔ Pdf report
 ✔ Reanalysis keeps human status
Hashing (Verifia\Tests\Unit\Hashing)
 ✔ Hamming handles sign bit
 ✔ Sim hash similarity
 ✔ Normalization removes accents and punctuation
 ✔ Perceptual hash robust to resize and recompression
 ✔ Invalid image rejected
Ingest And Queue (Verifia\Tests\Integration\IngestAndQueue)
 ✔ Create then deduplicate within and across sessions
 ✔ Same site same text without permalink is deduplicated
 ✔ Rejects empty and invalid images
 ✔ Stores valid images
 ✔ Queue priority exclusive claim retry and failure
 ✔ Stale locks are recovered
Priority And Permalink (Verifia\Tests\Unit\PriorityAndPermalink)
 ✔ Levels
 ✔ Critical requires several important indicators
 ✔ Satire keeps image reuse
 ✔ At least
 ✔ Confidence bounded
 ✔ Permalink normalization
Sessions Alerts Security (Verifia\Tests\Integration\SessionsAlertsSecurity)
 ✔ Session lifecycle
 ✔ Rejects dangerous urls
 ✔ Capacity limit
 ✔ Emergency stop
 ✔ Monitor api authorization
 ✔ Monitor poll state and commands
 ✔ Monitor ingest respects analysis switch
 ✔ Alert filters and sql injection safety
 ✔ User creation rules
 ✔ Login lockout
 ✔ Data wipe keeps accounts and settings
 ✔ Retention purge never deletes alerted publications
Text Analyzer (Verifia\Tests\Unit\TextAnalyzer)
 ✔ Detects manipulation indicators
 ✔ Neutral news is low or normal
 ✔ Opinion is never treated as misleading by style alone
 ✔ Satire is classified and capped
 ✔ Known satire site flag
 ✔ Temporal inconsistency
 ✔ Date extraction french months
 ✔ Questions are not claims
 ✔ Statistic claim type
 ✔ Advertising category
 ✔ Rumor marker
 ✔ English text language
```

## 2. Test de bout en bout (moteur réel + navigateur réel)

```
cd monitor && npm test      # base « verifia_e2e » EFFACÉE et recréée
```
Variables : `VERIFIA_E2E_DB_USER`, `VERIFIA_E2E_DB_PASS`, `VERIFIA_E2E_DB_HOST`, `VERIFIA_PHP`.

Le test démarre réellement : le serveur web PHP, MariaDB, le moteur d'analyse (`bin/worker.php`),
le moteur de surveillance (`monitor/src/index.js`, Chromium headless) et un **site d'actualités fictif local**
(`monitor/test/fixtures/server.js` : fil à défilement infini avec chargement différé, page CAPTCHA, page de connexion,
une photo « archives 2021 » republiée recadrée et recompressée comme « aujourd'hui »).
Tout passe par l'interface HTTP réelle (connexion, jeton CSRF, cookies), comme un utilisateur.

Résultat sur deux exécutions consécutives : **13/13 réussis** (~29 s chacune).

```
ok 1 - 1. services démarrés (moteur de surveillance et moteur d'analyse)
ok 2 - 2. plusieurs sessions indépendantes créées et démarrées
ok 3 - 3. CAPTCHA et connexion requise détectés : session suspendue, rien n'est lu
ok 4 - 4. défilement automatique : publications chargées dynamiquement détectées
ok 5 - 5. déduplication : une publication vue par deux sessions n'est analysée qu'une fois
ok 6 - 6. analyses en arrière-plan et alertes dans le centre (API + flux SSE)
ok 7 - 7. fiche détaillée et rapport PDF
ok 8 - 8. suspendre / reprendre / arrêter une session sans affecter les autres
ok 9 - 9. analyse manuelle d'une session (« Analyser maintenant ») est transmise
ok 10 - 10. arrêt immédiat de toute la surveillance
ok 11 - 11. les alertes restent consultables après fermeture des sessions
ok 12 - 12. erreurs enregistrées et visibles dans le journal
ok 13 - 13. sécurité : authentification, rôles, CSRF, jeton du moteur, XSS, chemins
```

Ce que vérifie chaque scénario :

1. les deux moteurs envoient leurs signaux de vie ; les ressources de la machine sont rapportées ;
2. 4 sessions créées et démarrées → 4 navigateurs indépendants ouverts ;
3. CAPTCHA et page de connexion détectés, sessions suspendues avec message, **0 publication lue** derrière le CAPTCHA ;
4. le défilement charge les pages 2 et 3 du fil et leurs publications sont détectées ;
5. deux sessions sur le même fil : chaque publication n'existe qu'une fois, avec 2 occurrences,
   **une seule tâche d'analyse par publication**, aucune erreur d'enregistrement concurrent ;
6. analyses terminées en arrière-plan, ≥ 3 alertes avec session et site ; l'image d'archives republiée est signalée
   avec **une seule** correspondance (aucun faux positif entre images différentes) ; l'opinion reste « opinion »
   en priorité faible/normale ; la satire est classée « satire » ; une alerte créée pendant le test arrive par **SSE** ;
7. fiche détaillée (chronologie, avertissement sur les dates) et **PDF** valide (`%PDF`, `application/pdf`) ;
8. pause → reprise → arrêt d'une session, l'autre continue ;
9. « Analyser maintenant » est délivré et exécuté par le moteur ;
10. arrêt immédiat : tous les navigateurs fermés (compteur du moteur à 0), démarrage refusé tant que l'arrêt est actif ;
11. fermeture de toutes les sessions : nombre d'alertes inchangé, fiches toujours consultables ;
12. avertissements CAPTCHA / connexion présents dans le journal et visibles dans l'écran *Journal* ;
13. sécurité : API sans connexion → 401 ; POST sans jeton CSRF → 419 ; origine étrangère → 419 ;
    observateur → 403 sur les actions et l'administration ; API du moteur avec mauvais jeton → 403 ;
    traversée de chemin dans `media.php` → 404 ; contenu `<script>` échappé dans les fiches ; CSP présente.

## 3. Test de charge

```
php tests/load/loadtest.php --config=<config d'une base *load*> --posts=1000 --sessions=4 --workers=4 --images=0.3
```
4 processus simulent 4 sessions qui transmettent **les mêmes** 1 000 publications dans un ordre différent,
en parallèle ; 4 moteurs d'analyse traitent ensuite la file en parallèle.

```
== Test de charge : 1000 publications × 4 sessions concurrentes, 4 workers, 30 % avec image ==
Ingestion : 4000 requêtes en 3.1 s (1308/s) — créées 1000, doublons 3000, erreurs 0
  publications uniques : 1000 (attendu 1000) · occurrences : 4000 (attendu 4000) · tâches : 1000
Analyse : 1000 tâches en 6.2 s (161.7/s) — terminées 1000, échouées 0, en attente 0, analyses en double : 0
  alertes : 262 · correspondances d'images : 97
RÉSULTAT : OK
```

Mesure complémentaire (même machine) : 5 000 publications × 2 sessions, 2 workers →
ingestion 555 requêtes/s, analyse **36,6 publications/s**, 0 erreur, 0 double analyse.
La baisse de débit vient des comparaisons linéaires (textes, images) ; voir LIMITES.md §7.

### Défauts trouvés par les tests et corrigés

| Défaut | Trouvé par | Correction |
|---|---|---|
| Validation des noms de colonnes refusant `sha256` | PHPUnit | expression régulière corrigée |
| Interblocage InnoDB quand deux sessions envoient la même publication au même instant (occurrence perdue) | bout en bout | transaction rejouée ; le moteur ne marque une publication « vue » qu'après accusé de réception |
| Fausse correspondance entre deux images différentes (dHash seul, distance 10) | bout en bout | correspondance exigée sur dHash **et** aHash |
| Interblocages entre workers (verrous d'intervalle du `DELETE`, réservation vs fin de tâche) | test de charge | suppression par clé primaire ; reprise automatique des instructions et transactions interbloquées |
| Bandeau « arrêt immédiat » toujours visible | contrôle visuel (captures Playwright) | conflit `hidden` / `d-flex` de Bootstrap |
| Auteur détecté = titre de l'article (sites génériques) | contrôle visuel | sélecteurs d'auteur dédiés |

## 4. Non testé dans cet environnement (à valider sur le poste Windows)

- **Scripts Windows** (`install.bat`, `start.bat`, `stop.bat`, `run_*.bat`) : écrits mais **non exécutés**
  (pas de Windows disponible). Points à vérifier en premier : détection de `php.exe`, `npx playwright install`,
  arrêt par `stop.bat`.
- **Apache / WampServer** et règles `.htaccess` (tests faits avec le serveur intégré de PHP, qui ignore `.htaccess`).
- **Sites réels** (Facebook, X, sites d'actualité) : aucun site réel n'a été sollicité. L'adaptateur Facebook
  n'a pas été validé sur le site actuel ; voir LIMITES.md.
- **Fenêtres visibles et positionnement multi-écrans** (tests en mode headless), API Window Management.
- **Microsoft Edge** comme navigateur piloté (`browser_channel = 'msedge'`).
- **OCR Tesseract** : seul le cas « non configuré » est couvert.
- **MySQL 8** et **PHP 8.2** exactement (tests faits sous MariaDB 10.11 et PHP 8.3 ; aucune fonction propre à 8.3 n'est utilisée).
