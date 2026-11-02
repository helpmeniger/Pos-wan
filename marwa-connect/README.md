# MARWA CONNECT

**Smart Wi-Fi Management & Ticketing System** : gestion des routeurs MikroTik, des offres Internet et des tickets Wi-Fi, avec la caisse, les revendeurs et les statistiques.

> État : **tous les modules du menu sont opérationnels** — voir la section *Modules* ci-dessous et [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

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

### 5. Planificateur (indispensable)

Le planificateur surveille les routeurs, relève les sessions Wi-Fi et l'usage des tickets, expire et désactive les tickets échus, re-crée les comptes manquants sur les routeurs, déclenche les alertes et fait la sauvegarde quotidienne.

Planificateur de tâches Windows → *Créer une tâche* :
* **Programme** : `C:\wamp64\bin\php\php8.x.x\php.exe`
* **Arguments** : `C:\wamp64\www\marwa-connect\cli\scheduler.php`
* **Déclencheur** : quotidien, *répéter la tâche toutes les 1 minute* pendant 1 jour ; cocher « Exécuter même si l'utilisateur n'est pas connecté ».

Chaque routeur est synchronisé selon son propre intervalle (5 min par défaut). `cli\scheduler.php --force` synchronise tout immédiatement.

### 6. Préparer chaque routeur MikroTik

Dans le terminal du routeur (Winbox → New Terminal), en remplaçant `IP_DU_SERVEUR` par l'adresse du PC WampServer :

```
/user group add name=marwa-api policy=read,write,api,reboot,test,!local,!telnet,!ssh,!ftp,!winbox,!web,!sniff,!sensitive,!password,!policy,!romon
/user add name=marwa password=MOT_DE_PASSE_SOLIDE group=marwa-api address=IP_DU_SERVEUR
/ip service set api address=IP_DU_SERVEUR disabled=no
```

Le Hotspot doit déjà être configuré sur le routeur (`/ip hotspot setup`). Puis, dans MARWA CONNECT : **Routeurs MikroTik → Ajouter**, puis **Tester la connexion**. Pour un routeur distant, utilisez un VPN : n'exposez jamais l'API sur Internet.

Pour accélérer les sauvegardes, renseignez `MYSQLDUMP_PATH` dans `.env`, par exemple `C:\wamp64\bin\mysql\mysql8.x.x\bin\mysqldump.exe`. Sinon, un export PHP natif est utilisé.

### 7. Mises à jour

Après avoir remplacé les fichiers (sans écraser `.env` ni `storage/`) :

```bat
C:\wamp64\bin\php\php8.x.x\php.exe cli\migrate.php
```

Seules les nouvelles migrations sont appliquées ; **aucune donnée n'est supprimée**. `cli\migrate.php --status` liste les migrations en attente.

---

## Modules

| Module | Ce qu'il fait réellement |
|---|---|
| **Tableau de bord** | CA du jour et du mois, solde en caisse, tickets vendus/disponibles/utilisés/expirés, ventes physiques/en ligne/revendeurs, revenus par routeur et par revendeur, routeurs en ligne, clients connectés, sessions, téléchargement/envoi, débit, CPU/RAM, interfaces. Graphiques journaliers, hebdomadaires et mensuels, répartition par routeur et par forfait, connexions et consommation. Filtres : aujourd'hui, hier, 7 jours, 30 jours, période personnalisée, par routeur. |
| **Routeurs MikroTik** | Ajout, modification, retrait ; test de connexion ; fiche technique en direct ; interfaces, clients connectés (avec déconnexion), utilisateurs Hotspot, profils, journaux ; synchronisation manuelle ou automatique ; redémarrage confirmé par saisie du nom. Les mots de passe API sont chiffrés. |
| **Offres** | Prix, validité, temps cumulé, quota de données, débits, appareils simultanés, liaison MAC, modes d'activation et de validité, dates de vente, vente en ligne. Le profil Hotspot correspondant est créé ou mis à jour sur chaque routeur associé. |
| **Tickets** | Génération de 1 à 5 000 tickets (préfixe, longueurs, jeu de caractères, identifiant = mot de passe, expiration fixe). Les comptes sont créés en rafale sur le routeur ; si le routeur est injoignable, les tickets restent « non créés », non vendables et sont re-poussés automatiquement. Impression : individuel, planches 10/20/40, thermique 58/80 mm, A4 ; modèles personnalisables ; export PDF et CSV. |
| **Caisse** | Ouverture avec fond de caisse, vente rapide, espèces ou mobile money (référence obligatoire), remise plafonnée, reçu avec identifiants, clôture avec rapprochement et alerte en cas d'écart. Anti double-vente (verrou SQL et clé d'idempotence). |
| **Ventes** | Historique filtrable, détail, annulation (si aucun ticket utilisé), remboursement (désactive les tickets sur le routeur), export CSV, commandes en ligne. |
| **Revendeurs** | Comptes revendeur, routeurs affectés, modèle commission (dépôt-vente) ou gros (achat avec remise, débit du solde, plafond de crédit), réapprovisionnement, versements, grand livre, performances. **Espace revendeur** : stock, remise d'un ticket à un client, impression, historique. |
| **Clients Wi-Fi** | Sessions en direct sur tous les routeurs, détection des connexions simultanées, déconnexion, suspension, historique et plus gros consommateurs. Les sessions de tickets expirés, suspendus ou épuisés sont coupées automatiquement. |
| **Monitoring** | Disponibilité sur 24 h, CPU, mémoire, clients et débit en direct (rafraîchis toutes les 5 s sans recharger la page), historiques. |
| **Notifications** | Alertes routeur hors ligne ou de retour, CPU, mémoire, débit anormal, connexions, stock faible, tickets expirés, paiements non confirmés, écart de caisse, synchronisation. Seuils et anti-répétition réglables ; envoi facultatif par e-mail (SMTP) et WhatsApp Business. |
| **Optimus IA** | Répond hors ligne, à partir des données réelles uniquement, aux questions courantes (clients connectés, CA, meilleur routeur, tickets vendus, routeurs déconnectés, forfaits les plus vendus, gros consommateurs, stock, caisse, revendeurs). Prépare des opérations (« Génère 100 tickets de 500 FCFA sur le routeur principal ») qui ne s'exécutent qu'après confirmation. Modèle Claude externe facultatif (voir ci-dessous). |
| **Paiements en ligne** | Page publique `/buy` : choix du forfait, du lieu et du moyen de paiement, puis remise des identifiants. Un paiement n'est confirmé que par **webhook signé** (HMAC-SHA256, anti-rejeu) ou par vérification manuelle de la référence par un opérateur habilité. |
| **Paramètres, Sauvegardes** | Entreprise, logo, couleurs, région, tickets, ventes, moyens de paiement, MikroTik, sécurité, IA ; sauvegardes manuelles et quotidiennes, téléchargement. |

### Optimus IA avec un modèle externe (facultatif)

Le moteur local suffit pour les questions prédéfinies. Pour les questions libres, installez le SDK officiel dans le dossier du projet (Internet requis) :

```bat
cd C:\wamp64\www\marwa-connect
composer require anthropic-ai/sdk
```

Puis **Paramètres → Optimus IA** : activez l'option et saisissez la clé API (stockée chiffrée). Le modèle par défaut est `claude-opus-5-5`. Seuls des indicateurs chiffrés lui sont envoyés, jamais d'identifiants ni de mots de passe, et il ne peut exécuter aucune action.

### Webhook de paiement (intégration d'un prestataire)

`POST /webhooks/payments/{code}` (ex. `nita`), en-tête `X-Signature: hex(HMAC-SHA256(corps, secret))`, corps JSON :
`{"event_id": "...", "order_reference": "WEB…", "provider_reference": "...", "status": "success", "amount": 500}`.
Le secret se règle dans **Paramètres → Paiements**. Chaque événement n'est traité qu'une fois.

## Tests

```bash
php -S 127.0.0.1:8099 -t public server.php     # serveur de test
python3 tests/mock_routeros.py 18728            # simulateur RouterOS (TEST UNIQUEMENT)
python3 tests/e2e_flow.py                       # parcours complet (application non installée)
```

Le parcours couvre l'installation, toutes les pages du menu, la connexion au routeur, la génération et la création réelle des tickets, l'impression et le PDF, la caisse (double-clic, stock, annulation, remboursement, clôture), la synchronisation de l'usage, les revendeurs, Optimus IA, les paramètres, les sauvegardes et la commande en ligne avec webhook signé.

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

## Limites connues

* Les connecteurs **directs** des API Nita, Airtel Money, Moov Money et Amana (initiation du paiement depuis l'application) ne sont pas inclus, faute de documentation publique : la caisse enregistre la référence de transaction, et la vente en ligne s'appuie sur le webhook signé générique ou sur la vérification manuelle.
* Le paiement par carte nécessite une passerelle compatible (désactivé par défaut).
* Le connecteur RouterOS a été validé contre un simulateur du protocole. Testez-le sur vos routeurs (v6 ≥ 6.43 et v7) avant la mise en production.
