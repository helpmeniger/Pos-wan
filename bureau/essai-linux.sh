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
# Node.js du système pour le pont MARWA SECEF (comme node\node.exe livré sous Windows)
command -v node >/dev/null && export POS_BUREAU_NODE=$(command -v node) POS_BUREAU_PONT_NAVIGATEUR=chromium
export POS_BUREAU_MACHINE=essai-machine-origine-0001 POS_BUREAU_MARQUEURS="$T/marqueurs" POS_BUREAU_SUPPORTS="$T/cle-usb"
mkdir -p "$T/cle-usb"
export POS_BUREAU_PHP=$(command -v php) POS_BUREAU_PHP_INI="$T/php/php.ini" POS_BUREAU_MARIADBD=$(command -v mariadbd || echo /usr/sbin/mariadbd) \
       POS_BUREAU_INSTALLDB=$(command -v mariadb-install-db) POS_BUREAU_CLIENT=$(command -v mariadb) POS_BUREAU_ADMIN=$(command -v mariadb-admin)
ok=0; ko=0
verif() { if eval "$2"; then echo "  ✔ $1"; ok=$((ok+1)); else echo "  ✘ $1"; ko=$((ko+1)); fi; }
demarrer() { ( "$T/lanceur" --dossier="$T" --sans-fenetre >> "$T/essai.log" 2>&1 & ); for i in $(seq 1 90); do curl -s http://127.0.0.1:8740/__bureau/sante | grep -q "pret=true" && return 0; sleep 1; done; return 1; }
arreter() { "$T/lanceur" --dossier="$T" --arreter; for i in $(seq 1 40); do curl -s -m 1 http://127.0.0.1:8740/__bureau/sante >/dev/null || break; sleep 1; done; for i in $(seq 1 40); do (exec 3<>/dev/tcp/127.0.0.1/3407) 2>/dev/null || return 0; sleep 1; done; return 1; }

echo "== Premier démarrage"
verif "prêt (base créée, serveurs PHP démarrés)" demarrer
verif "app/.env écrit (édition Bureau, menu en tuiles) sans aucun secret (ni APP_KEY ni accès à la base)" "grep -q '^POS_EDITION=bureau' '$T/app/.env' && grep -q '^MENU_STYLE=tuiles' '$T/app/.env' && ! grep -Eq '^(APP_KEY|DB_[A-Z]+)=' '$T/app/.env'"
verif "mots de passe chiffrés (bd.dat), aucun fichier de mots de passe en clair" "grep -q '^PMS1:' '$T/donnees/secret/bd.dat' && [ ! -e '$T/donnees/secret/bd.ini' ] && ! grep -Eq 'root=|app=' '$T/donnees/secret/bd.dat'"
verif "mots de passe transmis aux serveurs PHP par leur environnement seulement" "p=\$(pgrep -f 'php.*-S 127.0.0.1:18800' | head -1); tr '\\0' '\\n' < /proc/\$p/environ | grep -Eq '^DB_PASSWORD=[0-9a-f]{32}\$' && tr '\\0' '\\n' < /proc/\$p/environ | grep -Eq '^APP_KEY=[0-9a-f]{64}\$' && tr '\\0' '\\n' < /proc/\$p/environ | grep -q '^POS_MACHINE=essai-machine-origine-0001\$'"
verif "date du premier démarrage gardée hors du dossier (signée)" "grep -Eq '^[0-9]+\\.[0-9a-f]{24}\$' '$T/marqueurs/a' && cmp -s '$T/marqueurs/a' '$T/marqueurs/b'"
verif "supports externes : liste refusée sans le jeton de l'application" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8740/__bureau/supports)\" = 403 ]"
verif "PHP lancé avec le php.ini livré et le chemin complet de ses extensions" "pgrep -af 'php.*-S 127.0.0.1:188' | grep -q -- '-d extension_dir=$T/php/ext'"
verif "base écoutant seulement sur 127.0.0.1" "grep -q '^bind-address=127.0.0.1' '$T/donnees/mariadb.ini'"
verif "contrôle de démarrage : tout OK (base, PHP, application, fichiers)" "for i in \$(seq 1 60); do grep -q 'Contrôle' '$T/donnees/journaux/diagnostic.txt' 2>/dev/null && break; sleep 1; done; grep -q '^Tout fonctionne' '$T/donnees/journaux/diagnostic.txt' && ! grep -q '^ÉCHEC' '$T/donnees/journaux/diagnostic.txt'"
verif "page de diagnostic (ce PC), sans mot de passe" "d=\$(curl -s http://127.0.0.1:8740/__bureau/diagnostic); [[ \"\$d\" == *'Contrôle de démarrage'* ]] && p=\$(pgrep -f 'php.*-S 127.0.0.1:18800' | head -1); pw=\$(tr '\\0' '\\n' < /proc/\$p/environ | grep '^DB_PASSWORD=' | cut -d= -f2); ak=\$(tr '\\0' '\\n' < /proc/\$p/environ | grep '^APP_KEY=' | cut -d= -f2); [ -n \"\$pw\" ] && ! grep -qF \"\$pw\" <<< \"\$d\" && ! grep -qF \"\$ak\" <<< \"\$d\""
verif "requêtes inscrites au journal" "grep -q 'Requête 127.0.0.1 GET / → 302' '$T/donnees/journaux/lanceur.log'"
verif "accueil → assistant d'installation" "[[ \"\$(curl -s -o /dev/null -w '%{redirect_url}' http://127.0.0.1:8740/)\" == */install ]]"
verif "assistant : base « préparée automatiquement »" "page=\$(curl -s http://127.0.0.1:8740/install); [[ \"\$page\" == *'préparée automatiquement'* ]]"
verif "fichier PHP autre que l'application jamais exécuté" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8740/../app/bootstrap.php)\" != 200 ] && [ \"\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8740/index.php/../../.env)\" != 200 ]"
debut=$(date +%s%N); for i in $(seq 1 8); do curl -s -o /dev/null http://127.0.0.1:8740/install & done; wait; fin=$(date +%s%N)
echo "  (8 pages de l'assistant demandées en même temps : $(( (fin-debut)/1000000 )) ms)"
echo "== Arrêt puis second démarrage"
verif "arrêt propre (serveurs et base arrêtés)" arreter
verif "MariaDB arrêtée proprement" "for i in \$(seq 1 20); do grep -q 'Shutdown complete' '$T/donnees/journaux/mariadb.err' && break; sleep 0.5; done; grep -q 'Shutdown complete' '$T/donnees/journaux/mariadb.err'"
printf 'DB_PASSWORD="ancien-mot-de-passe"\nAPP_KEY=ancienne\n' >> "$T/app/.env"   # .env d'une version précédente
verif "second démarrage (base existante, mots de passe réutilisés)" demarrer
verif "secrets d'une version précédente retirés d'app/.env, application toujours connectée à sa base" "! grep -Eq '^(APP_KEY|DB_PASSWORD)=' '$T/app/.env' && for i in \$(seq 1 30); do grep -q '^Tout fonctionne' '$T/donnees/journaux/diagnostic.txt' && break; sleep 1; done; grep -q '^Tout fonctionne' '$T/donnees/journaux/diagnostic.txt'"
# Impression sans boîte de dialogue : réglage transmis par l'application (jeton de ce démarrage), gardé pour la fenêtre
JET=$(tr '\0' '\n' < /proc/$(pgrep -f 'php.*-S 127.0.0.1:18800' | head -1)/environ | grep '^POS_BUREAU_JETON=' | cut -d= -f2)
verif "impression : réglage refusé sans le jeton de l'application" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' -X POST -d auto=0 http://127.0.0.1:8740/__bureau/impression)\" = 403 ]"
verif "impression : désactivée puis réactivée par l'application, choix gardé pour la prochaine fenêtre" "curl -s -X POST -H 'X-Bureau-Jeton: $JET' -d auto=0 http://127.0.0.1:8740/__bureau/impression | grep -q '\"sans_fenetre\":false' && grep -qx 0 '$T/donnees/impression-auto.txt' && curl -s -X POST -H 'X-Bureau-Jeton: $JET' -d auto=1 http://127.0.0.1:8740/__bureau/impression | grep -q '\"sans_fenetre\":true' && grep -qx 1 '$T/donnees/impression-auto.txt'"
verif "réglages de la base : attente de verrou courte, connexions suffisantes" "grep -q '^innodb_lock_wait_timeout=10' '$T/donnees/mariadb.ini' && grep -q '^max_connections=200' '$T/donnees/mariadb.ini'"
# Arrêt brutal de MariaDB en pleine journée (manque de mémoire, antivirus…) : relancée seule, l'application continue
PIDBD=$(cat "$T/donnees/mariadb.pid" 2>/dev/null || pgrep -f "mariadbd --defaults-file=$T/donnees/mariadb.ini" | head -1)
kill -9 "$PIDBD"
verif "MariaDB arrêtée brutalement : relancée automatiquement" "for i in \$(seq 1 60); do grep -q 'MariaDB redémarrée' '$T/donnees/journaux/lanceur.log' && break; sleep 1; done; grep -q 'MariaDB redémarrée' '$T/donnees/journaux/lanceur.log' && [ \"\$(cat '$T/donnees/mariadb.pid')\" != '$PIDBD' ]"
verif "… et l'application répond de nouveau avec sa base" "page=\$(curl -s http://127.0.0.1:8740/install); [[ \"\$page\" == *'préparée automatiquement'* ]]"
verif "… redémarrage inscrit au rapport de diagnostic" "curl -s http://127.0.0.1:8740/__bureau/diagnostic | grep -q 'Base relancée : 1 fois'"
# Pont MARWA SECEF CONNECT : démarré par le lanceur dès que POS Marwa a créé son jeton, redémarré sur nouveau jeton
if command -v node >/dev/null && [ -d "$T/app/addons/fiscal/pont-secef" ]; then
  J1=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n'); echo -n "$J1" > "$T/app/addons/fiscal/pont-secef/jeton.txt"
  pont() { curl -s --noproxy '*' -m 3 -H "X-Pont-Jeton: $1" http://127.0.0.1:8760/etat; }
  verif "pont e-SECeF démarré par le lanceur (jeton créé par POS Marwa)" "for i in \$(seq 1 30); do pont $J1 | grep -q '\"ok\":true' && break; sleep 1; done; pont $J1 | grep -q '\"ok\":true' && grep -q 'Pont MARWA SECEF démarré' '$T/donnees/journaux/lanceur.log'"
  J2=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n'); echo -n "$J2" > "$T/app/addons/fiscal/pont-secef/jeton.txt"
  verif "nouveau jeton : pont redémarré, ancien jeton refusé" "for i in \$(seq 1 40); do pont $J2 | grep -q '\"ok\":true' && break; sleep 1; done; pont $J2 | grep -q '\"ok\":true' && ! pont $J1 | grep -q '\"ok\":true'"
  verif "données du pont dans donnees/pont-secef (hors du dossier du logiciel)" "[ -d '$T/donnees/pont-secef/travaux' ]"
  verif "diagnostic : état du pont" "grep -q 'Pont e-SECeF' '$T/donnees/journaux/diagnostic.txt'"
fi
verif "arrêt final" arreter
verif "pont arrêté avec POS Marwa" "! curl -s --noproxy '*' -m 2 http://127.0.0.1:8760/etat >/dev/null"
# Dossier copié sur un autre ordinateur : mots de passe illisibles, POS Marwa refuse de démarrer, rien n'est effacé
avant=$(find "$T/donnees/mysql" -type f | wc -l)
verif "dossier copié sur un autre ordinateur : démarrage refusé" "! POS_BUREAU_MACHINE=autre-ordinateur-0002 timeout 60 '$T/lanceur' --dossier='$T' --sans-fenetre > '$T/copie.log' 2>&1 && grep -q \"provient d'un autre ordinateur\" '$T/copie.log'"
verif "… sans rien modifier ni effacer (base, mots de passe)" "[ \"\$(find '$T/donnees/mysql' -type f | wc -l)\" = $avant ] && grep -q '^PMS1:' '$T/donnees/secret/bd.dat'"
verif "date d'essai modifiée à la main : essai considéré comme terminé" "echo 1700000000.000000000000000000000000 > '$T/marqueurs/a'; echo 1700000000.000000000000000000000000 > '$T/marqueurs/b'; demarrer && p=\$(pgrep -f 'php.*-S 127.0.0.1:18800' | head -1) && tr '\\0' '\\n' < /proc/\$p/environ | grep -q '^POS_ESSAI_DEBUT=1\$'"
verif "arrêt" arreter

# Mise à jour d'une installation 3.13 (mots de passe en clair dans bd.ini et app/.env) : conversion sans perte
echo "== Mise à jour depuis la 3.13.5"
T2="$T-maj"; mkdir -p "$T2"; cp -a "$T/app" "$T2/app"; rm -f "$T2/app/.env"; cp -a "$T/php" "$T2/php"
SRC313=$(mktemp -d); git -C "$SRC" archive 0af7110 bureau/lanceur | tar -x -C "$SRC313"
( cd "$SRC313/bureau/lanceur" && go build -o "$T2/lanceur-3.13" . )
pret() { for i in $(seq 1 90); do curl -s http://127.0.0.1:8740/__bureau/sante | grep -q "pret=true" && return 0; sleep 1; done; return 1; }
fini() { for i in $(seq 1 40); do curl -s -m 1 http://127.0.0.1:8740/__bureau/sante >/dev/null || break; sleep 1; done; for i in $(seq 1 40); do (exec 3<>/dev/tcp/127.0.0.1/3407) 2>/dev/null || return 0; sleep 1; done; return 1; }
( "$T2/lanceur-3.13" --dossier="$T2" --sans-fenetre >> "$T/essai.log" 2>&1 & ); pret
CLE313=$(grep '^APP_KEY=' "$T2/app/.env" | cut -d= -f2)
verif "installation 3.13 : mots de passe en clair (situation de départ)" "[ -f '$T2/donnees/secret/bd.ini' ] && grep -q '^DB_PASSWORD=' '$T2/app/.env' && [ -n '$CLE313' ]"
"$T2/lanceur-3.13" --dossier="$T2" --arreter; fini
( "$T/lanceur" --dossier="$T2" --sans-fenetre >> "$T/essai.log" 2>&1 & ); pret
verif "3.14 : bd.ini en clair converti en bd.dat chiffré puis retiré" "[ ! -e '$T2/donnees/secret/bd.ini' ] && grep -q '^PMS1:' '$T2/donnees/secret/bd.dat'"
verif "3.14 : secrets retirés d'app/.env, même clé APP_KEY transmise (données chiffrées toujours lisibles)" "! grep -Eq '^(APP_KEY|DB_PASSWORD)=' '$T2/app/.env' && p=\$(pgrep -f 'php.*-S 127.0.0.1:18800' | head -1) && tr '\\0' '\\n' < /proc/\$p/environ | grep -qx 'APP_KEY=$CLE313'"
verif "3.14 : contrôle de démarrage tout OK après conversion" "for i in \$(seq 1 30); do grep -q 'Contrôle de démarrage' '$T2/donnees/journaux/diagnostic.txt' 2>/dev/null && grep -q '^\\(Tout\\|ÉCHEC\\)' '$T2/donnees/journaux/diagnostic.txt' && break; sleep 1; done; grep -q '^Tout fonctionne' '$T2/donnees/journaux/diagnostic.txt'"
"$T/lanceur" --dossier="$T2" --arreter; fini
rm -rf "$SRC313"

# Fenêtre comme sous Windows (Edge confie la fenêtre à un autre de ses processus) : POS Marwa doit rester en marche tant
# que la fenêtre envoie ses signes de vie, puis s'arrêter seul après sa fermeture. Nécessite Chromium et Xvfb.
CHROME=${POS_BUREAU_CHROMIUM:-$(ls /opt/pw-browsers/chromium-*/chrome-linux64/chrome 2>/dev/null | head -1)}
if [ -n "$CHROME" ] && command -v Xvfb >/dev/null; then
  echo "== Fenêtre (signes de vie, fermeture)"
  Xvfb :93 -screen 0 1366x800x24 >/dev/null 2>&1 & XV=$!
  printf '#!/bin/sh\nnohup "%s" --no-sandbox "$@" >/dev/null 2>&1 &\nexit 0\n' "$CHROME" > "$T/faux-edge.sh"; chmod +x "$T/faux-edge.sh"
  ( DISPLAY=:93 POS_BUREAU_NAVIGATEUR="$T/faux-edge.sh" POS_BUREAU_INACTIF=20 "$T/lanceur" --dossier="$T" >> "$T/essai.log" 2>&1 & )
  sleep 45
  verif "fenêtre ouverte 45 s : toujours en marche, signes de vie reçus" "curl -s http://127.0.0.1:8740/__bureau/sante | grep -Eq 'pret=true inactif=([0-9]|1[0-9])s'"
  # Page figée (navigateur qui la met en veille, boîte d'impression ouverte) : plus aucun signe de vie, mais la fenêtre
  # existe encore → POS Marwa doit rester en marche
  pkill -STOP -f "$T/donnees/fenetre" || true
  sleep 40
  verif "page figée 40 s (aucun signe de vie) mais fenêtre ouverte : serveurs et base maintenus" "curl -s -m 3 http://127.0.0.1:8740/__bureau/sante | grep -q 'pret=true' && grep -q 'fenêtre POS Marwa est toujours ouverte' '$T/donnees/journaux/lanceur.log'"
  pkill -CONT -f "$T/donnees/fenetre" || true
  pkill -f "$T/donnees/fenetre" || true
  verif "fenêtre fermée : arrêt automatique (délai d'essai 20 s)" "( for i in \$(seq 1 20); do curl -s -m 1 http://127.0.0.1:8740/__bureau/sante >/dev/null || exit 0; sleep 3; done; exit 1 )"
  kill $XV 2>/dev/null || true
else
  echo "(Chromium ou Xvfb absent : essai de la fenêtre non fait)"
fi
echo "Résultat : $ok réussi(s), $ko échec(s) — journal : $T/essai.log"
[ "$ko" -eq 0 ]
