# Déploiement en production sur un VPS Linux

Exemple : Ubuntu 24.04 LTS, Nginx, PHP 8.2/8.3-FPM, MariaDB 10.11, HTTPS Let's Encrypt.
(Apache 2.4 + PHP-FPM est également fourni : `deploy/apache-vhost-production.conf`.)

## 1. Serveur

- 2 vCPU / 4 Go RAM / 40 Go SSD suffisent pour démarrer ; augmenter avec le volume de commandes.
- Choisir un hébergeur avec une bonne latence vers le Niger (Afrique de l'Ouest ou Europe).
- Nom de domaine + enregistrement DNS `A` vers l'IP du VPS.

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install nginx mariadb-server php-fpm php-mysql php-mbstring php-intl php-curl php-gd php-zip php-xml unzip git certbot python3-certbot-nginx
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
sudo mysql_secure_installation
```

## 2. Base de données (utilisateur dédié, jamais root)

```sql
CREATE DATABASE marwa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'marwa'@'localhost' IDENTIFIED BY '<mot-de-passe-long-et-aléatoire>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE VIEW, TRIGGER, LOCK TABLES ON marwa.* TO 'marwa'@'localhost';
FLUSH PRIVILEGES;
```

Le droit `DROP` sert aux migrations ; il peut être retiré après installation.

## 3. Application

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <dépôt> marwa && cd marwa
sudo cp .env.example .env && sudo nano .env
```

`.env` de production :

```ini
APP_ENV=production
APP_DEBUG=false
APP_DEMO=false
APP_URL=https://marwa.example.ne
DB_USERNAME=marwa
DB_PASSWORD=<mot de passe>
SESSION_SECURE_COOKIE=true
PAYMENT_DEMO_ENABLED=false
MAIL_DRIVER=smtp
GEOCODING_USER_AGENT="MARWA/1.0 (contact: tech@example.ne)"
ROUTING_DRIVER=osrm
OSRM_URL=http://127.0.0.1:5000        # serveur OSRM auto-hébergé (voir SERVICES_EXTERNES.md)
```

```bash
sudo -u www-data php bin/migrate.php
sudo -u www-data php bin/create-admin.php --email=admin@example.ne --name="Prénom Nom"
sudo -u www-data php bin/import-landmarks.php --city=Niamey     # repères réels OpenStreetMap
sudo chown -R root:www-data /var/www/marwa && sudo chmod -R o-rwx /var/www/marwa
sudo chown -R www-data:www-data /var/www/marwa/storage && sudo chmod 640 /var/www/marwa/.env
```

**Ne jamais** charger `bin/seed.php` (données de démonstration) en production (le script refuse
lorsque `APP_ENV=production`).

## 4. Serveur web et HTTPS

```bash
sudo cp deploy/nginx-marwa.conf /etc/nginx/sites-available/marwa   # adapter server_name
sudo ln -s /etc/nginx/sites-available/marwa /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d marwa.example.ne
```

PHP-FPM (`/etc/php/8.x/fpm/php.ini`) : `expose_php = Off`, `upload_max_filesize = 5M`,
`post_max_size = 6M`, `session.cookie_secure = 1`, `opcache.enable = 1`, `date.timezone = Africa/Niamey`.

Derrière un répartiteur de charge ou Cloudflare : renseigner `TRUSTED_PROXIES` (IP du proxy)
pour que la limitation de débit utilise la vraie IP du client.

## 5. Worker et tâches planifiées

```bash
sudo cp deploy/marwa-worker.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now marwa-worker
sudo crontab -u www-data deploy/crontab.txt     # sauvegarde quotidienne (et worker si pas de systemd)
```

## 6. Sauvegardes

Voir [SAUVEGARDE.md](SAUVEGARDE.md). Copier **chaque jour** `storage/backups/` hors du serveur
(autre hébergeur / stockage objet chiffré) et **tester la restauration** régulièrement.

## 7. Mises à jour

```bash
cd /var/www/marwa
sudo -u www-data php bin/backup.php
sudo git pull
sudo -u www-data php bin/migrate.php       # n'applique que les nouvelles migrations
sudo systemctl reload php8.*-fpm && sudo systemctl restart marwa-worker
```

## 8. Supervision

- `storage/logs/app-*.log` (erreurs applicatives avec référence affichée à l'utilisateur),
  `/var/log/nginx/`, `journalctl -u marwa-worker`.
- `GET /api/health` pour la surveillance de disponibilité.
- Console d'administration → *Paramètres* : état réel des fournisseurs, balance du grand livre,
  messages en échec. → *Paiements* : rapprochement, doublons, écarts de caisse.

## 9. Sécurisation du serveur (recommandé, 5 minutes)

```bash
cd /var/www/marwa
sudo bash deploy/securiser-serveur.sh          # pare-feu, fail2ban, mises à jour auto., SSH, PHP, droits
sudo -u www-data php bin/security-check.php --url=https://marwa.example.ne
```

Gardez votre session SSH ouverte pendant l'exécution et testez une nouvelle connexion avant de
la fermer. Ensuite, dans l'administration : activez la double authentification sur votre compte,
puis *Paramètres* → « Double authentification obligatoire pour le personnel ». Facultatif :
`ADMIN_ALLOWED_IPS=ip.du.bureau,autre.ip` dans `.env` limite l'administration à ces adresses.

Pour des consignes de navigation routières (« Tournez à droite sur… »), un serveur **OSRM** est
nécessaire (voir SERVICES_EXTERNES.md) ; sans lui, la navigation indique la direction à vol d'oiseau.

## 10. Liste de contrôle avant ouverture au public

Voir la section « Mise en production » de [SECURITE.md](SECURITE.md) et les points juridiques de
[CONFORMITE.md](CONFORMITE.md).
