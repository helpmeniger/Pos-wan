#!/usr/bin/env bash
# POS Marwa Bureau — essai automatique du lanceur sous Linux (mêmes étapes que sous Windows, avec le PHP et le
# MariaDB du système) : premier démarrage (base créée, .env écrit), assistant d'installation, requêtes simultanées,
# adresse du poste non falsifiable, arrêt propre, second démarrage.
#   bureau/essai-linux.sh [dossier d'essai]     (le dossier est créé ; il ne doit pas exister)
set -euo pipefail
SRC=$(cd "$(dirname "$0")/.." && pwd)
T=${1:-$(mktemp -d)/essai}
[ -e "$T/donnees" ] && { echo "Le dossier $T contient déjà des données : choisissez un dossier neuf." >&2; exit 1; }
MODULES="banques comptabilite doublons fiscal livraisons marwa_ia pay"
mkdir -p "$T/app"
( cd "$SRC" && git ls-files -co --exclude-standard -z ) | while IFS= read -r -d '' f; do
  case "$f" in tests/*|windows/*|bureau/*|outils-auteur/*|.env|.env.*|.git*|storage/*|public/uploads/*|public/assets/addons/*) continue ;;
    addons/*) m=${f#addons/}; m=${m%%/*}; case " $MODULES " in *" $m "*) ;; *) continue ;; esac ;; esac
  [ -f "$SRC/$f" ] || continue; mkdir -p "$T/app/$(dirname "$f")"; cp -p "$SRC/$f" "$T/app/$f"
done
cp "$SRC/bureau/routeur.php" "$T/app/"
for d in storage/logs storage/backups storage/sessions storage/cache storage/tmp public/uploads/products; do mkdir -p "$T/app/$d"; done
( cd "$SRC/bureau/lanceur" && go build -o "$T/lanceur" . )
# php.ini LIVRÉ (comme sous Windows) et dossier php/ext : extensions partagées du système, quand elles existent
mkdir -p "$T/php/ext"; cp "$SRC/bureau/php.ini" "$T/php/php.ini"
SYSEXT=$(php -n -r 'echo ini_get("extension_dir");')
for e in curl exif fileinfo gd intl mbstring openssl pdo_mysql mysqli sodium zip opcache; do [ -f "$SYSEXT/$e.so" ] && ln -sf "$SYSEXT/$e.so" "$T/php/ext/$e.so"; done
export POS_BUREAU_PHP=$(command -v php) POS_BUREAU_PHP_INI="$T/php/php.ini" POS_BUREAU_MARIADBD=$(command -v mariadbd || echo /usr/sbin/mariadbd) \
       POS_BUREAU_INSTALLDB=$(command -v mariadb-install-db) POS_BUREAU_CLIENT=$(command -v mariadb) POS_BUREAU_ADMIN=$(command -v mariadb-admin)
ok=0; ko=0
verif() { if eval "$2"; then echo "  ✔ $1"; ok=$((ok+1)); else echo "  ✘ $1"; ko=$((ko+1)); fi; }
demarrer() { ( "$T/lanceur" --dossier="$T" --sans-fenetre >> "$T/essai.log" 2>&1 & ); for i in $(seq 1 90); do curl -s http://127.0.0.1:8740/__bureau/sante | grep -q "pret=true" && return 0; sleep 1; done; return 1; }
arreter() { "$T/lanceur" --dossier="$T" --arreter; for i in $(seq 1 40); do curl -s -m 1 http://127.0.0.1:8740/__bureau/sante >/dev/null || break; sleep 1; done; for i in $(seq 1 40); do (exec 3<>/dev/tcp/127.0.0.1/3407) 2>/dev/null || return 0; sleep 1; done; return 1; }

echo "== Premier démarrage"
verif "prêt (base créée, serveurs PHP démarrés)" demarrer
verif "app/.env écrit (port 3407, édition Bureau, menu en tuiles)" "grep -q '^DB_PORT=3407' '$T/app/.env' && grep -q '^POS_EDITION=bureau' '$T/app/.env' && grep -q '^MENU_STYLE=tuiles' '$T/app/.env'"
verif "mots de passe tirés au hasard (32 caractères)" "grep -Eq '^DB_PASSWORD=\"[0-9a-f]{32}\"' '$T/app/.env'"
verif "PHP lancé avec le php.ini livré et le chemin complet de ses extensions" "pgrep -af 'php.*-S 127.0.0.1:188' | grep -q -- '-d extension_dir=$T/php/ext'"
verif "base écoutant seulement sur 127.0.0.1" "grep -q '^bind-address=127.0.0.1' '$T/donnees/mariadb.ini'"
verif "accueil → assistant d'installation" "[[ \"\$(curl -s -o /dev/null -w '%{redirect_url}' http://127.0.0.1:8740/)\" == */install ]]"
verif "assistant : base « préparée automatiquement »" "page=\$(curl -s http://127.0.0.1:8740/install); [[ \"\$page\" == *'préparée automatiquement'* ]]"
verif "fichier PHP autre que l'application jamais exécuté" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8740/../app/bootstrap.php)\" != 200 ] && [ \"\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8740/index.php/../../.env)\" != 200 ]"
debut=$(date +%s%N); for i in $(seq 1 8); do curl -s -o /dev/null http://127.0.0.1:8740/install & done; wait; fin=$(date +%s%N)
echo "  (8 pages de l'assistant demandées en même temps : $(( (fin-debut)/1000000 )) ms)"
echo "== Arrêt puis second démarrage"
verif "arrêt propre (serveurs et base arrêtés)" arreter
verif "MariaDB arrêtée proprement" "for i in \$(seq 1 20); do grep -q 'Shutdown complete' '$T/donnees/journaux/mariadb.err' && break; sleep 0.5; done; grep -q 'Shutdown complete' '$T/donnees/journaux/mariadb.err'"
verif "second démarrage (base existante, mots de passe réutilisés)" demarrer
verif "même mot de passe d'application" "grep -q \"\$(grep '^app=' '$T/donnees/secret/bd.ini' | cut -d= -f2)\" '$T/app/.env'"
verif "arrêt final" arreter
echo "Résultat : $ok réussi(s), $ko échec(s) — journal : $T/essai.log"
[ "$ko" -eq 0 ]
