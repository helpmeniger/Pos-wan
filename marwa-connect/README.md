# MARWA CONNECT

**Smart Wi-Fi Management & Ticketing System** : gestion des routeurs MikroTik, des offres Internet et des tickets Wi-Fi, avec la caisse, les revendeurs et les statistiques.

> État : **Phase 1 livrée** (architecture, installation, base de données, authentification, rôles et permissions, audit, interface générale).
> Voir [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) pour l'architecture complète et la feuille de route.

---

## Installation sous WampServer (Windows)

### 1. Prérequis

* WampServer 3.3+ avec **PHP 8.2 ou supérieur** (icône Wamp → PHP → Version).
* Extensions PHP actives : `pdo_mysql`, `mbstring`, `openssl`, `sodium` (recommandée), `curl`, `gd`.
* Module Apache **`rewrite_module`** activé (icône Wamp → Apache → Modules Apache). Il est recommandé ; sans lui, l'application reste utilisable via `…/public/index.php/`.

### 2. Copier le projet

Copiez le dossier `marwa-connect` dans `C:\wamp64\www\`, ce qui donne `C:\wamp64\www\marwa-connect\`.

### 3. (Recommandé) Créer un utilisateur MySQL dédié

Dans phpMyAdmin (`http://localhost/phpmyadmin`) → onglet SQL :

```sql
CREATE DATABASE marwa_connect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'marwa'@'localhost' IDENTIFIED BY 'un-mot-de-passe-solide';
GRANT ALL PRIVILEGES ON marwa_connect.* TO 'marwa'@'localhost';
FLUSH PRIVILEGES;
```

(Vous pouvez aussi utiliser `root` sans mot de passe en local : l'assistant crée alors la base lui-même.)

### 4. Lancer l'assistant d'installation

Ouvrez **http://localhost/marwa-connect/**. L'assistant :

1. vérifie le serveur (version PHP, extensions, droits d'écriture) ;
2. teste la connexion MySQL et crée la base si besoin ;
3. génère le fichier `.env` avec une **clé de chiffrement unique** (`APP_KEY`) ;
4. crée les tables, les rôles, les permissions et les paramètres par défaut ;
5. crée le compte **super administrateur**.

Connectez-vous ensuite avec ce compte.

> ⚠️ **Sauvegardez le fichier `.env`** en lieu sûr. Sans la clé `APP_KEY`, les mots de passe des routeurs et des tickets enregistrés deviennent illisibles.

### 5. Tâches planifiées (Planificateur de tâches Windows)

| Tâche | Commande | Fréquence |
|---|---|---|
| Sauvegarde de la base | `C:\wamp64\bin\php\php8.x.x\php.exe C:\wamp64\www\marwa-connect\cli\backup.php --scheduled` | Quotidienne |
| Synchronisation des routeurs *(phase 3)* | `…\php.exe …\cli\scheduler.php` | Chaque minute |

Pour accélérer les sauvegardes, renseignez `MYSQLDUMP_PATH` dans `.env`, par exemple `C:\wamp64\bin\mysql\mysql8.x.x\bin\mysqldump.exe`. Sinon, un export PHP natif est utilisé.

### 6. Mises à jour

Après avoir remplacé les fichiers (sans écraser `.env` ni `storage/`) :

```bat
C:\wamp64\bin\php\php8.x.x\php.exe cli\migrate.php
```

Seules les nouvelles migrations sont appliquées ; **aucune donnée n'est supprimée**. `cli\migrate.php --status` liste les migrations en attente.

---

## Tester sans Apache

```bash
php -S localhost:8000 -t public server.php
# puis http://localhost:8000/
```

## Rôles fournis

Super administrateur · Administrateur · Responsable réseau · Gestionnaire commercial · Caissier · Revendeur · Technicien · Comptable.
Les permissions de chaque rôle se modifient dans **Administration → Rôles & permissions**. Elles sont toujours contrôlées côté serveur.

## Sécurité en place (phase 1)

* Requêtes préparées PDO uniquement (pas d'injection SQL).
* Échappement systématique des sorties et en-tête **Content-Security-Policy** strict, sans script inline (protection XSS).
* Jeton **CSRF** sur chaque formulaire et chaque appel API en écriture.
* Mots de passe hachés en **Argon2id** (bcrypt en repli).
* Sessions sécurisées : HttpOnly, SameSite, mode strict, régénération de l'identifiant, expiration après inactivité, empreinte du navigateur.
* Limitation des tentatives de connexion par identifiant (5) et par adresse IP (20) sur 15 minutes, et journal des connexions.
* Limitation du débit de l'API interne.
* Chiffrement authentifié des secrets (libsodium ou AES-256-GCM).
* Journal d'audit des actions sensibles (valeurs avant/après, secrets masqués).
* Garde-fous : impossible de supprimer, suspendre ou rétrograder le dernier super administrateur, ni de modifier son propre rôle.
* Dossiers `app/`, `config/`, `storage/`… inaccessibles depuis le web (`.htaccess`).

## Feuille de route

| Phase | Contenu | État |
|---|---|---|
| 1 | Architecture, installation, base de données, authentification | ✅ |
| 2 | Tableau de bord complet, paramètres, design | ⏳ |
| 3 | Connecteur RouterOS API, routeurs, synchronisation | ⏳ |
| 4 | Offres et profils Hotspot | ⏳ |
| 5 | Génération et impression des tickets | ⏳ |
| 6 | Caisse, ventes, paiements | ⏳ |
| 7 | Revendeurs, commissions, stocks | ⏳ |
| 8 | Clients connectés, monitoring | ⏳ |
| 9 | Notifications, Optimus IA | ⏳ |
| 10 | Sécurité, tests, sauvegardes, documentation | ⏳ |
