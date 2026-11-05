# POS Marwa en ligne — mode SaaS (multi-clients)

Le même logiciel fonctionne de deux façons :

| | Installation classique (par défaut) | Mode SaaS (`SAAS_MODE=true`) |
|---|---|---|
| Clients | un seul commerce | autant de commerces que voulu |
| Adresse | `http://localhost/pos-marwa/public` ou un domaine | `posmarwa.ne` (site public + console), `<espace>.posmarwa.ne` (chaque client) |
| Base de données | une base | **une base par client** + une base centrale |
| Licence | clé d'activation PWL1 | abonnement payé (essai gratuit, puis lecture seule sans paiement) |
| Mises à jour | « Mise à jour du logiciel » | faites par l'exploitant pour tous les clients |

Sans `SAAS_MODE=true`, rien ne change : une installation WampServer ou o2switch existante continue
exactement comme avant.

---

## 1. Fonctionnement

### Ce que voit le public — `https://posmarwa.ne`
- **Page d'accueil** avec les offres (prix, nombre d'utilisateurs, de boutiques, de caisses, modules inclus).
- **Inscription** : nom du commerce, activité, adresse de l'espace (`boutique-awa`), contact, identifiant et
  mot de passe de l'administrateur, offre. Protégée par : jeton CSRF, champ piège contre les robots,
  5 inscriptions par heure et par adresse IP, politique de mot de passe, liste d'adresses réservées
  (`www`, `admin`, `console`, `api`…).
- **Se connecter à mon espace** : retrouve l'adresse d'un espace par son nom exact (pas de liste des clients).
- **Conditions du service et données personnelles** (loi n° 2017-28).

### Création d'un espace
1. À l'inscription, une base de données est attribuée au client :
   - mode **`create`** (serveur VPS ou dédié) : `CREATE DATABASE pmt_<n°>` et un **utilisateur MySQL dédié**
     qui n'a de droits que sur cette base ;
   - mode **`pool`** (hébergement mutualisé comme o2switch, où PHP ne peut pas créer de base) : la plus
     ancienne base vide déclarée dans la console. S'il n'en reste aucune, l'inscription est enregistrée
     « en attente d'une base » et apparaît dans le tableau de bord de la console.
2. À la **première visite** du sous-domaine, POS Marwa s'installe dans cette base (schéma, rôles,
   boutique, caisse, compte administrateur avec le mot de passe choisi — seule son empreinte
   irréversible a été conservée, chiffrée, le temps de l'installation, puis effacée).

### Isolement entre clients
| Élément | Isolement |
|---|---|
| Données | base MySQL distincte ; en mode `create`, utilisateur MySQL distinct sans accès aux autres bases ni à la base centrale (vérifié par les tests) |
| Sessions | dossier `storage/tenants/<espace>/sessions` ; cookie limité au sous-domaine |
| Journaux, sauvegardes, fichiers temporaires | `storage/tenants/<espace>/` |
| Photos | `public/uploads/t/<espace>/` (noms aléatoires, aucune exécution de script) |
| Signatures (QR des documents, badges) | clé secrète dérivée propre à l'espace |
| Mots de passe des bases | chiffrés (XSalsa20-Poly1305) avec `SAAS_KEY` |

En mode `pool`, l'isolement MySQL dépend de vous : créez **un utilisateur par base** dans cPanel.

### Abonnement
- **Essai** gratuit (14 jours par défaut, réglable), puis **lecture seule** sans paiement : consultation,
  rapports et sauvegardes restent possibles, ventes et modifications sont refusées. Aucune donnée n'est
  bloquée ni effacée.
- **Paiement manuel** : le client paie par mobile money, virement, espèces… puis le **déclare** dans
  « Mon abonnement » avec la référence de la transaction ; l'exploitant vérifie sur son relevé et
  **valide** (ou refuse avec un motif). Il peut aussi saisir directement un paiement reçu.
- La validation prolonge l'abonnement (au lendemain de l'essai ou de la période déjà payée : payer en
  avance ne fait perdre aucun jour), applique l'offre payée et émet une **facture PDF** numérotée
  (`AB2026-00001`), téléchargeable par le client et par l'exploitant.
- **Suspension** (impayé prolongé, abus) : l'espace affiche un message avec le motif ; les données sont conservées.
- **Limites de l'offre** : utilisateurs actifs, boutiques, caisses, modules complémentaires. Elles
  s'appliquent aux ajouts (rien n'est supprimé si un client change d'offre).
- Modules **jamais proposés en ligne** (`SAAS_EXCLUDED_ADDONS`) : MARWA REMOTE ASSIST (poste Windows
  local) et vision/caméras IP (le serveur irait chercher des adresses réseau choisies par le client).

### Console de l'exploitant — `https://posmarwa.ne/console`
Tableau de bord (clients actifs, en essai, expirés, suspendus, revenu mensuel récurrent, encaissé du
mois, échéances dans les 7 jours, dernières inscriptions), clients (recherche, fiche, paiements,
changement d'offre, prolongation gratuite motivée, suspension, notes), paiements à vérifier, offres,
réserve de bases, paramètres (identité de l'exploitant pour les factures, instructions de paiement,
durée d'essai), journal de toutes les opérations.

Les comptes de la console sont distincts des comptes clients. **Aucun compte par défaut** : le premier
est créé en ligne de commande. 5 échecs de connexion verrouillent le compte 15 minutes ; déconnexion
après 30 minutes d'inactivité.

---

## 2. Mise en service

### 2.1 Pré-requis
- PHP 8.1+ (pdo_mysql, mbstring, zip, gd ; sodium conseillé), MySQL 5.7+/MariaDB 10.3+.
- Un nom de domaine et un **DNS joker** : enregistrement `A` pour `posmarwa.ne` **et** pour `*.posmarwa.ne`.
- Un **certificat HTTPS joker** (`*.posmarwa.ne`), par exemple Let's Encrypt avec validation DNS.
- Le site web (domaine + sous-domaines) pointe sur le dossier `public/`.

### 2.2 Fichier `.env`
```ini
APP_ENV=production
APP_DEBUG=false
APP_KEY=<64 caractères hexadécimaux aléatoires>
SESSION_SECURE=true

SAAS_MODE=true
SAAS_DOMAIN=posmarwa.ne
SAAS_DB_HOST=localhost
SAAS_DB_DATABASE=posmarwa_saas
SAAS_DB_USERNAME=posmarwa_saas
SAAS_DB_PASSWORD=<mot de passe fort>
SAAS_PROVISION=create        # ou pool (o2switch)
SAAS_DB_PREFIX=pmt_
SAAS_KEY=<résultat de : php bin/saas.php key>
SAAS_TRIAL_DAYS=14
```
Aucun mot de passe n'est écrit dans le code : tout est dans `.env` (hors de `public/`, jamais versionné).
**Conservez `SAAS_KEY`** : sans elle, les mots de passe des bases clients sont illisibles.

### 2.3 Serveur VPS / dédié (mode `create`)
```sql
CREATE DATABASE posmarwa_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'posmarwa_saas'@'localhost' IDENTIFIED BY '<mot de passe fort>';
GRANT ALL PRIVILEGES ON *.* TO 'posmarwa_saas'@'localhost' WITH GRANT OPTION;
```
Ce compte central crée les bases et les utilisateurs des clients : il doit avoir `CREATE`, `CREATE USER`
et `GRANT OPTION`. Il n'est utilisé que par le site central ; chaque espace client se connecte avec son
propre utilisateur limité.

Apache (exemple) :
```apache
<VirtualHost *:443>
    ServerName posmarwa.ne
    ServerAlias *.posmarwa.ne
    DocumentRoot /var/www/pos-marwa/public
    <Directory /var/www/pos-marwa/public>
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/posmarwa.ne/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/posmarwa.ne/privkey.pem
</VirtualHost>
```

### 2.4 Hébergement mutualisé o2switch (mode `pool`)
1. cPanel → **Domaines** : ajoutez le domaine, racine du document = `…/pos-marwa/public` ; ajoutez le
   sous-domaine joker `*` sur la même racine (o2switch le permet ; vérifiez dans votre offre) ;
   **SSL/TLS** : certificat AutoSSL couvrant le joker si disponible.
2. cPanel → **Bases de données MySQL** : créez la base centrale et son utilisateur.
3. Préparez à l'avance quelques bases clients : pour chacune, **une base + un utilisateur dédié** avec
   tous les privilèges **sur cette base seulement**.
4. Déclarez-les : console → **Bases de données** (connexion testée, base vide exigée), ou
   `php bin/saas.php pool-add moncompte_pmt01 moncompte_pmt01`.
5. Gardez toujours 2 ou 3 bases libres d'avance ; une inscription sans base libre attend et s'affiche
   dans la console.

### 2.5 Initialisation
```bash
php bin/saas.php key       # copier la ligne SAAS_KEY=… dans .env
php bin/saas.php init      # tables de la base centrale et offres d'exemple
php bin/saas.php admin     # premier compte de la console (mot de passe demandé, jamais affiché)
```
Puis, dans la console : **Paramètres** (nom de l'exploitant, téléphone, NIF, RCCM, instructions de
paiement mobile money) et **Offres** (les prix fournis — 7 500, 15 000 et 30 000 FCFA — ne sont que des
exemples à adapter).

### 2.6 Tâches planifiées (cron)
```cron
# Sauvegarde de chaque espace, chaque nuit
15 2 * * *  cd /var/www/pos-marwa && php bin/saas.php backup  >> storage/saas/logs/cron.log 2>&1
# Liste des échéances proches, à appeler ou relancer
0 8 * * 1   cd /var/www/pos-marwa && php bin/saas.php remind 7 >> storage/saas/logs/relances.log 2>&1
```
Copiez aussi régulièrement `storage/tenants/*/backups` et une sauvegarde de la base centrale **hors du
serveur**.

### 2.7 Mettre à jour le logiciel
Remplacez les fichiers (sauf `.env`, `storage/`, `public/uploads/`), puis :
```bash
php bin/saas.php migrate   # sauvegarde + mise à jour de la base de chaque espace
```
Sans cette commande, chaque espace se met à jour tout seul à sa prochaine visite (sauvegarde préalable).

### 2.8 Commandes
| Commande | Rôle |
|---|---|
| `php bin/saas.php key` | génère une clé `SAAS_KEY` |
| `php bin/saas.php init` | crée / met à jour la base centrale |
| `php bin/saas.php admin [identifiant]` | crée un compte de console |
| `php bin/saas.php tenants` | liste des espaces et de leur état |
| `php bin/saas.php migrate` | met à jour la base de chaque espace |
| `php bin/saas.php backup` | sauvegarde chaque espace |
| `php bin/saas.php remind [jours]` | échéances proches et espaces expirés (avec téléphone) |
| `php bin/saas.php pool-add <base> <utilisateur>` | ajoute une base vide à la réserve |
| `POSWAN_TENANT=<espace> php bin/<outil>.php` | lance un outil habituel (migrate, backup…) sur un espace |

En mode SaaS, un outil lancé sans `POSWAN_TENANT` est refusé (il ne doit pas écrire dans la base centrale).

---

## 3. Limites — à lire avant de vendre le service

- **Pas de paiement automatique.** Orange Money, Airtel Money, Moov, Wave ou les cartes bancaires
  demandent un contrat avec l'opérateur (ou un agrégateur) et des clés d'API. Le logiciel ne simule
  aucune passerelle : l'encaissement est vérifié et validé à la main dans la console.
- **Pas d'envoi automatique de SMS ni d'e-mails** de relance : `bin/saas.php remind` liste les clients
  à contacter. Un fournisseur SMS/e-mail pourra être branché plus tard.
- **Suppression d'un espace** : volontairement absente de la console (aucune donnée effacée par
  erreur). Sur demande écrite du client : faites une dernière sauvegarde, remettez-la-lui, puis
  supprimez la base, l'utilisateur MySQL et les dossiers `storage/tenants/<espace>` et
  `public/uploads/t/<espace>`.
- **Photos** : servies directement par le serveur web ; leurs noms sont aléatoires (impossibles à
  deviner) mais une adresse de photo communiquée reste lisible sans connexion.
- **Capacité** : chaque espace a sa base ; au-delà de quelques centaines de clients actifs, prévoyez un
  serveur MySQL dimensionné (mémoire, nombre de connexions) et surveillez l'espace disque.
- **Caisse hors connexion** : elle tient les coupures courtes (ventes en attente puis envoyées), mais
  un service en ligne dépend du réseau ; pour une connexion très instable, l'installation locale
  classique reste préférable.
- **Sécurité** : mots de passe hachés, jetons CSRF, en-têtes de sécurité, isolement par base et par
  utilisateur MySQL, journal des opérations. Aucun système n'est inviolable : gardez le serveur à
  jour, utilisez HTTPS partout, des mots de passe forts pour la console et MySQL, et faites valider
  la configuration par un professionnel avant l'ouverture au public.

## 4. Données personnelles — loi n° 2017-28 du 3 mai 2017

En exploitant le service, **vous hébergez les données de vos clients** (leurs clients, fournisseurs,
employés, et selon les modules : patients, élèves…). Vous agissez comme sous-traitant :
- ne consultez pas ces données sans demande d'assistance du client ;
- protégez l'accès au serveur et aux sauvegardes ; conservez les sauvegardes dans un lieu sûr ;
- informez vos clients (page « Conditions ») et permettez-leur d'exporter et de faire supprimer leurs données ;
- renseignez-vous auprès de la **HAPDP** (Haute Autorité de Protection des Données à caractère
  Personnel) sur les formalités applicables à votre activité d'hébergeur ; les données de santé
  (module Clinique) sont des données sensibles soumises à des règles renforcées.

## 5. Tests
```bash
TEST_DB_USER=… TEST_DB_PASS=… php tests/saas.php   # 29 tests du mode SaaS
TEST_DB_USER=… TEST_DB_PASS=… php tests/run.php    # tests de l'installation classique
```
`tests/saas.php` a besoin d'un compte MySQL de test qui peut créer des bases et des utilisateurs ; il
recrée `poswan_saas_test` et les bases `pmtest_*`. Ne l'exécutez jamais sur un serveur de production.
