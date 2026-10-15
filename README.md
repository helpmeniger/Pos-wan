# MARWA — taxi, livraison et repas au Niger

Application web **client-serveur** complète : PHP 8.2 (MVC sans framework), MySQL/MariaDB, API REST
sécurisée, interfaces responsives en JavaScript natif, cartographie **Leaflet + OpenStreetMap**,
suivi GPS réel, négociation de prix, attribution atomique des courses, moteur de commissions
paramétrable, **grand livre en partie double**, rapports CSV / Excel / PDF.

- Marché de lancement : **Niger** (Niamey) — devise **FCFA (XOF)** — fuseau **Africa/Niamey** — dates **JJ/MM/AAAA**
- Fonctionne sur **WampServer** (Windows 10/11) sans Docker ni Composer obligatoire, et sur un **VPS Linux**
- Six espaces : client · chauffeur/taximan · livreur · restaurant/commerce · entreprise · administration

> Les intégrations payantes (SMS, Mobile Money, carte bancaire, WhatsApp) **ne sont pas simulées
> comme réelles** : elles sont prévues derrière des interfaces interchangeables. Le paiement en
> espèces et le compte entreprise fonctionnent dès maintenant ; une passerelle de **démonstration**
> clairement signalée permet de tester tout le cycle d'un paiement électronique (webhook signé HMAC).

## Démarrage rapide (WampServer) — installation automatique

1. WampServer démarré (icône **verte**).
2. Décompresser le projet dans `C:\wamp64\www\` → `C:\wamp64\www\marwa\index.php`.
3. Double-cliquer sur **`DEMARRER-MARWA.bat`**, ou ouvrir **http://localhost/marwa/**.

Au premier lancement, la base de données, les tables, le fichier `.env` et les comptes de
démonstration sont créés automatiquement (MySQL/MariaDB de Wamp détecté tout seul). L'adresse
de l'application est détectée automatiquement (nom de dossier libre, `rewrite_module` facultatif).
`DEMARRAGE-AUTO-WINDOWS.bat` lance MARWA à chaque ouverture de session Windows.

Installation en ligne de commande (équivalente) : `php bin\install.php`.
Guide détaillé et dépannage : [docs/INSTALLATION_WAMP.md](docs/INSTALLATION_WAMP.md).

### Comptes de démonstration (créés à l'installation locale)

Mot de passe commun : `Demo2026!` — données **fictives**, numéros `+227 90 00 00 xx` fictifs.

| Profil | Identifiant |
|---|---|
| Client | client@marwa.demo |
| Chauffeur (taxi) | chauffeur@marwa.demo |
| Livreur (moto) | livreur@marwa.demo |
| Restaurant | restaurant@marwa.demo |
| Entreprise (paiement différé) | entreprise@marwa.demo |
| Administrateur / assistance / finance | admin@ · support@ · finance@marwa.demo |

## Tests

```bash
php tests/run.php                         # 61 tests automatisés (base marwa_test recréée)
npm install && npx playwright install chromium   # une seule fois (facultatif)
node tests/e2e/ui-smoke.mjs               # 55 écrans, 6 rôles, sans erreur JavaScript
node tests/e2e/full-journey.mjs           # parcours complet client + chauffeur dans 2 navigateurs
```

Résultats et procédure manuelle : [docs/PROCEDURE_DE_TEST.md](docs/PROCEDURE_DE_TEST.md).

## Documentation

| Document | Contenu |
|---|---|
| [INSTALLATION_WAMP.md](docs/INSTALLATION_WAMP.md) | Prérequis, VirtualHost, base, admin, tâches planifiées, dépannage, réseau local |
| [DEPLOIEMENT_VPS.md](docs/DEPLOIEMENT_VPS.md) | Ubuntu + Nginx/Apache + PHP-FPM + MariaDB + HTTPS + worker + sauvegardes |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Structure, schéma de données, machines à états, attribution, modèle comptable |
| [API.md](docs/API.md) | Référence de l'API REST (authentification, commandes, prestataires, admin…) |
| [SECURITE.md](docs/SECURITE.md) | Mesures de sécurité et liste de contrôle de mise en production |
| [SERVICES_EXTERNES.md](docs/SERVICES_EXTERNES.md) | Fournisseurs nécessaires, configuration, coûts à prévoir |
| [FONCTIONNALITES.md](docs/FONCTIONNALITES.md) | Ce qui fonctionne réellement / ce qui nécessite un fournisseur / limites |
| [PROCEDURE_DE_TEST.md](docs/PROCEDURE_DE_TEST.md) | Parcours complet d'une commande, de la création au règlement |
| [SAUVEGARDE.md](docs/SAUVEGARDE.md) | Sauvegarde et restauration |
| [CONFORMITE.md](docs/CONFORMITE.md) | Points juridiques et réglementaires à valider avant le lancement |

## Structure

```
app/            Core (noyau HTTP, sécurité), Controllers (API, admin, pages), Services (métier), Middleware, Views
bin/            install, migrate, seed, create-admin, worker, backup, import-landmarks, dev-router
config/         configuration (lit .env) — inaccessible depuis le Web
database/       migrations SQL, marwa_install.sql (import phpMyAdmin), seeds de démonstration
public/         SEUL dossier exposé : index.php, assets (CSS/JS/Leaflet), sw.js, manifest
routes/         api.php, web.php
storage/        logs, uploads (privés), cache, sessions, backups — inaccessibles depuis le Web
tests/          Unit, Feature (PHP), e2e (Playwright)
docs/  deploy/  documentation et fichiers de configuration serveur
```
