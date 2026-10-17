# SMART GUARD AI

> « La vision intelligente au service de votre commerce. »

Application web de vidéosurveillance intelligente pour alimentations, boutiques, supérettes et
pharmacies : reconnaissance des articles à partir de photos, suivi des objets dans le flux des
caméras, comptage des franchissements d'une ligne virtuelle de sortie, rapprochement avec la
caisse, alertes à vérifier par un humain, stocks et rapports quotidiens.

**Principe fondamental :** le système distingue les **faits observés** (un objet a franchi la
ligne) des **hypothèses** (vente correspondante ou non). Une sortie observée n'est jamais
présentée comme un vol ; aucune personne n'est identifiée ; aucune reconnaissance faciale.

## Architecture

```
 Navigateur (PC, tablette, smartphone du réseau local)
        │  HTTPS/HTTP + session + CSRF
        ▼
 ┌─────────────────────────────┐      X-API-Key (127.0.0.1)     ┌──────────────────────────────┐
 │ Application PHP (WampServer)│ ─────────────────────────────▶ │ Service IA Python (FastAPI)  │
 │ public/index.php            │                                 │ OpenCV · suivi · ligne       │
 │ utilisateurs, produits,     │ ◀───────────────────────────── │ virtuelle · reconnaissance   │
 │ caméras, alertes, caisse,   │  événements (X-AI-Token)        │ par similarité · préparation │
 │ stocks, rapports            │                                 │ et validation                │
 └──────────────┬──────────────┘                                 └──────────────┬───────────────┘
                │ PDO (requêtes préparées)                                       │ USB / RTSP / HTTP
                ▼                                                                ▼
        MySQL / MariaDB                                     Webcams USB, caméras IP, NVR existants
                ▲
                └── storage/ (photos, images d'événements, extraits, sauvegardes) — hors du web
```

| Composant | Technologie | Rôle |
|---|---|---|
| Interface web | PHP 8.1+ (testé 8.3), Bootstrap 5.3, Chart.js 4 (fournis localement) | Gestion du magasin |
| Serveur web | Apache (WampServer) | Hébergement |
| Base de données | MySQL 8 / MariaDB 10.6+ (36 tables) | Produits, événements, stocks, ventes |
| Vision | Python 3.10–3.13, OpenCV, FastAPI | Détection, suivi, comptage, reconnaissance |
| Suivi | Implémentation intégrée inspirée de ByteTrack | Limiter les doubles comptages |
| Reconnaissance | Empreintes visuelles (classique ou ONNX pré-entraîné) | Similarité avec les photos de référence |

## Démarrage rapide (Windows + WampServer)

1. Copier le dossier dans `C:\wamp64\www\smart-guard-ai\`.
2. Installer Python 3.11 ou 3.12 (64 bits, « Add to PATH »).
3. Lancer `scripts\install.bat` (environnement Python, secrets, base, compte admin).
   Le service IA seul se prépare avec `ai-service\setup_ai.bat` (lancé automatiquement au besoin).
4. Démarrer WampServer, puis `scripts\start.bat` (service IA).
5. Ouvrir `http://localhost/smart-guard-ai/`.

Guide complet : [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Documentation

| Document | Contenu |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Conception technique, modules, base de données, flux |
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Installation pas à pas, réseau local, mise à jour, sauvegarde/restauration, dépannage |
| [docs/USER_GUIDE.md](docs/USER_GUIDE.md) | Guide utilisateur en français |
| [docs/CAMERAS.md](docs/CAMERAS.md) | Webcam USB, caméras IP (RTSP/ONVIF), NVR, placement, ligne virtuelle |
| [docs/API.md](docs/API.md) | API interne PHP ↔ IA et API de ventes |
| [docs/SECURITY.md](docs/SECURITY.md) | Mesures de sécurité, vie privée, déploiement |
| [docs/TESTS.md](docs/TESTS.md) | Procédures de test, validation des comptages, résultats réels |
| [docs/LIMITATIONS.md](docs/LIMITATIONS.md) | Limites connues et fonctionnalités prévues |

## Données de démonstration

`php scripts\seed_demo.php` crée des produits et des ventes **fictifs** (nom préfixé « DÉMO »,
colonne `is_demo = 1`). Aucune observation de caméra n'est inventée. Le jeu
**synthétique** (`ai-service\scripts\make_synthetic_dataset.py`) sert uniquement à tester le
logiciel (suivi, comptage) sans caméra ; il ne mesure pas la précision sur de vrais produits.

## Licences

Code de l'application : à définir par le propriétaire. Bibliothèques incluses : Bootstrap (MIT),
Bootstrap Icons (MIT), Chart.js (MIT). Dépendances Python obligatoires : licences BSD/MIT/Apache.
**Ultralytics YOLO (facultatif) est sous AGPL-3.0** : une utilisation commerciale fermée exige une
licence Ultralytics ; le détecteur par défaut n'en a pas besoin. Vérifiez les conditions des
poids pré-entraînés avant un usage commercial.
