# VERIFIA — surveillance multi-sessions et centre d'alertes

VERIFIA ouvre plusieurs sites dans des navigateurs indépendants, fait défiler les pages de façon contrôlée,
lit les publications **visibles**, les analyse en arrière-plan et transmet celles qui méritent une
vérification humaine à un **centre d'alertes** temps réel.

Il ne s'appuie sur **aucune API de réseau social** : il lit ce que l'utilisateur voit dans un navigateur
auquel il s'est connecté lui-même.

> Une alerte « élevée » ou « critique » signifie *« à vérifier en priorité »*, jamais *« faux »*.
> VERIFIA relève des indices explicables ; la conclusion appartient à l'analyste.

## Ce que fait la version 1.0

| Fonction | État |
|---|---|
| Sessions multiples, chacune avec son propre navigateur (profil et fenêtre séparés) | ✅ testé |
| Démarrer / suspendre / reprendre / arrêter / fermer / redémarrer / renommer une session | ✅ testé |
| Défilement automatique contrôlé (pas, attente, pause pendant les chargements, limites) | ✅ testé |
| Détection des publications visibles, dédoublonnage local et en base (multi-sessions) | ✅ testé |
| Détection CAPTCHA / connexion requise / blocage → session suspendue, jamais contournée | ✅ testé |
| File d'analyse persistante (priorités, reprises, verrous expirés, workers parallèles) | ✅ testé |
| Classification (14 catégories), affirmations, dates, lieux, sources citées, indices | ✅ testé |
| Opinion / satire jamais assimilées à de la désinformation par le seul style | ✅ testé |
| Empreintes d'image SHA-256 + perceptuelles (dHash + aHash), correspondances, chronologie | ✅ testé |
| Comparaison avec les contenus antérieurs (SimHash + Jaccard), affirmations déjà contredites | ✅ testé |
| Centre d'alertes temps réel (SSE, repli périodique), filtres, recherche, statuts, affectation | ✅ testé |
| Fiche détaillée, sources ajoutées par l'analyste, verdicts, rapport PDF (Dompdf) | ✅ testé |
| Tableau de bord sur données réelles, journal, ressources, capacité limitée | ✅ testé |
| Arrêt immédiat de toute la surveillance, suppression des données | ✅ testé |
| Rôles (admin / analyst / viewer), CSRF, CSP, XSS, injections SQL, jeton du moteur | ✅ testé |
| OCR des images | ⚙️ si Tesseract est installé et configuré (sinon indiqué « indisponible ») |
| Recherche externe d'occurrences / de sources | 🔎 manuelle (liens préremplis) ; **aucun résultat n'est inventé** |
| Scripts Windows (installation, démarrage, arrêt, redémarrage contrôlé) | ⚠️ écrits, **non exécutés** (environnement de développement Linux) |

Les résultats des tests exécutés sont dans [docs/TESTS.md](docs/TESTS.md).

## Architecture

```
 ┌──────────────── Poste Windows ─────────────────────────────────────────────────────────┐
 │                                                                                         │
 │  Navigateur de l'analyste ──HTTP──►  Apache + PHP (WampServer)  ◄──SQL──►  MySQL/MariaDB │
 │  (tableau de bord, sessions,         public/  (interface + API)                ▲        │
 │   centre d'alertes, rapports)          ▲   ▲                                   │        │
 │                                        │   │ api/monitor.php                   │        │
 │                     SSE / JSON ────────┘   │ (jeton + boucle locale)           │        │
 │                                            │                                   │        │
 │  Moteur de surveillance (Node + Playwright)┘          Moteur d'analyse (PHP CLI)┘        │
 │   ├─ session 1 : Chromium, profil 1, fenêtre 1         bin/worker.php                    │
 │   ├─ session 2 : Chromium, profil 2, fenêtre 2         file analysis_jobs                │
 │   └─ session n …  (défilement, extraction, captures)   (1 à n processus)                 │
 └─────────────────────────────────────────────────────────────────────────────────────────┘
```

Quatre processus distincts : serveur web, navigateurs pilotés, moteur de surveillance, moteur d'analyse.
Une session bloquée ou un navigateur planté n'arrête ni les autres sessions ni l'analyse.
Détails : [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Démarrage rapide (Windows + WampServer)

1. Copier (ou cloner) le projet dans `C:\wamp64\www\verifia`.
2. Installer **Node.js 20 LTS** (ou plus récent) et **Composer**.
3. Démarrer WampServer (icône verte).
4. Double-cliquer `scripts\windows\install.bat` (dépendances, base, administrateur).
5. Double-cliquer `scripts\windows\start.bat` : vérifie les prérequis, lance les deux moteurs,
   ouvre `http://localhost/verifia/public/`.
6. *Sessions* → *Nouvelle session* → *Démarrer*. Dans la fenêtre qui s'ouvre, se connecter
   **manuellement** au site si nécessaire.
7. Le *Centre d'alertes* reçoit les alertes, quel que soit l'écran sur lequel on travaille.

Guide complet : [docs/INSTALLATION.md](docs/INSTALLATION.md) ·
dépannage : [docs/DEPANNAGE.md](docs/DEPANNAGE.md) ·
plusieurs écrans : [docs/MULTI-ECRANS.md](docs/MULTI-ECRANS.md) ·
**limites réelles (Facebook, CAPTCHA, conditions d'utilisation)** : [docs/LIMITES.md](docs/LIMITES.md).

## Arborescence

```
bin/            worker.php (analyse), install.php, check.php, purge.php, monitor_config.php
config/         config.example.php (config.php est généré, jamais versionné)
database/       schema.sql (21 tables)
docs/           documentation
monitor/        moteur de surveillance Node/Playwright (+ test de bout en bout)
public/         interface web et API (seul dossier exposé par Apache)
scripts/windows install.bat, start.bat, stop.bat, check.bat, run_*.bat
src/            code PHP (analyse, file, sessions, alertes, rapports, sécurité)
storage/        images, miniatures, rapports, profils de navigateur, journaux (hors web)
tests/          PHPUnit (unitaires + intégration) et test de charge
```

## Tests

```bash
composer install && vendor/bin/phpunit                         # 49 tests (base verifia_test effacée)
cd monitor && npm install && npm test                          # 13 scénarios de bout en bout (base verifia_e2e)
php tests/load/loadtest.php --config=config/config_load.php    # charge (base « *load* »)
```
