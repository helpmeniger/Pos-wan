#!/usr/bin/env bash
# =====================================================================
# MARWA — durcissement d'un VPS Ubuntu 22.04 / 24.04 (à lancer en root, une fois)
#   sudo bash deploy/securiser-serveur.sh
# Ce script : pare-feu, fail2ban, mises à jour de sécurité automatiques,
# SSH durci, réglages réseau du noyau, MariaDB local uniquement, PHP durci,
# droits des fichiers MARWA. Il affiche chaque étape et peut être relancé sans risque.
# Lisez-le avant de l'exécuter, et gardez une session SSH ouverte pendant l'opération.
# =====================================================================
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/marwa}"
SSH_PORT="${SSH_PORT:-22}"
step() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[!] %s\033[0m\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "Lancez ce script avec sudo."; exit 1; }

step "Paquets de sécurité"
apt-get update -q
DEBIAN_FRONTEND=noninteractive apt-get install -y -q ufw fail2ban unattended-upgrades apt-listchanges needrestart

step "Mises à jour de sécurité automatiques"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'CONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
CONF

step "Pare-feu : seuls SSH, HTTP et HTTPS sont ouverts"
ufw default deny incoming
ufw default allow outgoing
ufw allow "${SSH_PORT}/tcp"
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

step "SSH : pas de connexion root par mot de passe, tentatives limitées"
mkdir -p /etc/ssh/sshd_config.d
cat > /etc/ssh/sshd_config.d/90-marwa.conf <<CONF
PermitRootLogin prohibit-password
MaxAuthTries 4
LoginGraceTime 30
X11Forwarding no
AllowAgentForwarding no
ClientAliveInterval 300
ClientAliveCountMax 2
CONF
if ls /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys >/dev/null 2>&1; then
  echo "PasswordAuthentication no" >> /etc/ssh/sshd_config.d/90-marwa.conf
  echo "Clé SSH détectée : connexion par mot de passe désactivée."
else
  warn "Aucune clé SSH trouvée : la connexion par mot de passe reste active. Ajoutez une clé (ssh-copy-id) puis relancez ce script."
fi
if sshd -t; then systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true; else warn "Configuration SSH invalide : /etc/ssh/sshd_config.d/90-marwa.conf retiré."; rm -f /etc/ssh/sshd_config.d/90-marwa.conf; fi

step "Noyau : protections réseau"
cat > /etc/sysctl.d/90-marwa.conf <<'CONF'
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.icmp_echo_ignore_broadcasts = 1
kernel.kptr_restrict = 2
kernel.dmesg_restrict = 1
fs.protected_hardlinks = 1
fs.protected_symlinks = 1
CONF
sysctl --system >/dev/null

step "fail2ban : bannissement après échecs de connexion (SSH, MARWA, limites Nginx)"
cp "$APP_DIR/deploy/fail2ban/filter.d/marwa-auth.conf" /etc/fail2ban/filter.d/
sed "s#/var/www/marwa#${APP_DIR}#" "$APP_DIR/deploy/fail2ban/jail.d/marwa.conf" > /etc/fail2ban/jail.d/marwa.conf
[ -f /var/log/nginx/error.log ] || sed -i '/\[nginx-limit-req\]/,/^$/ s/enabled  = true/enabled  = false/' /etc/fail2ban/jail.d/marwa.conf
systemctl enable --now fail2ban
systemctl restart fail2ban

step "MariaDB / MySQL : écoute locale uniquement"
for f in /etc/mysql/mariadb.conf.d/50-server.cnf /etc/mysql/mysql.conf.d/mysqld.cnf; do
  if [ -f "$f" ]; then
    sed -i 's/^\s*bind-address\s*=.*/bind-address = 127.0.0.1/' "$f"
    grep -q '^bind-address' "$f" || printf '\n[mysqld]\nbind-address = 127.0.0.1\n' >> "$f"
    grep -q '^local-infile' "$f" || printf 'local-infile = 0\n' >> "$f"
  fi
done
systemctl restart mariadb 2>/dev/null || systemctl restart mysql 2>/dev/null || true

step "PHP : réglages de production"
for ini in /etc/php/*/fpm/conf.d /etc/php/*/apache2/conf.d; do
  [ -d "$ini" ] || continue
  cat > "$ini/90-marwa.ini" <<'CONF'
expose_php = Off
display_errors = Off
log_errors = On
allow_url_include = Off
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1
session.cookie_samesite = Lax
upload_max_filesize = 5M
post_max_size = 6M
max_execution_time = 30
date.timezone = Africa/Niamey
opcache.enable = 1
opcache.validate_timestamps = 1
CONF
done
systemctl reload 'php*-fpm' 2>/dev/null || true

step "Nginx : limites de débit et version masquée"
if [ -d /etc/nginx/conf.d ]; then
  cp "$APP_DIR/deploy/nginx-marwa-limits.conf" /etc/nginx/conf.d/marwa-limits.conf
  nginx -t && systemctl reload nginx || warn "Configuration Nginx à vérifier (nginx -t)."
fi

step "Droits des fichiers MARWA"
if [ -d "$APP_DIR" ]; then
  chown -R root:www-data "$APP_DIR"
  find "$APP_DIR" -type d -exec chmod 750 {} \;
  find "$APP_DIR" -type f -exec chmod 640 {} \;
  chmod 750 "$APP_DIR"/bin/*.php 2>/dev/null || true
  chown -R www-data:www-data "$APP_DIR/storage"
  find "$APP_DIR/storage" -type d -exec chmod 770 {} \;
  [ -f "$APP_DIR/.env" ] && chown root:www-data "$APP_DIR/.env" && chmod 640 "$APP_DIR/.env"
  for f in app.key vapid.json; do [ -f "$APP_DIR/storage/$f" ] && chmod 600 "$APP_DIR/storage/$f" || true; done
fi

step "Contrôle de sécurité de l'application"
if [ -f "$APP_DIR/bin/security-check.php" ]; then
  sudo -u www-data php "$APP_DIR/bin/security-check.php" || warn "Des points sont à corriger (voir ci-dessus)."
fi

printf '\n\033[1;32mTerminé.\033[0m Vérifiez : ufw status, fail2ban-client status, et gardez la session SSH actuelle ouverte\n'
printf 'jusqu\x27à ce qu\x27une nouvelle connexion SSH ait réussi.\n'
