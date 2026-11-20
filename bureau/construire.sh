#!/usr/bin/env bash
# POS Marwa Bureau — construction de l'installateur Windows « POS-Marwa-Bureau-<version>-Setup.exe »
#
#   bureau/construire.sh <dossier des composants> <dossier de sortie>
#
# Le dossier des composants contient les fichiers officiels téléchargés (empreintes vérifiées par ce script) :
#   php-8.3.37-nts-Win32-vs16-x64.zip    (windows.php.net)
#   mariadb-10.11.20-winx64.zip          (archive.mariadb.org)
#   vc_redist.x64.exe                    (Microsoft, Visual C++ 2015-2022)
# Outils : makensis (NSIS 3), go (1.22 ou plus), x86_64-w64-mingw32-windres (icône du programme), unzip, php.
set -euo pipefail

SRC=$(cd "$(dirname "$0")/.." && pwd)
COMP=$(cd "${1:?dossier des composants}" && pwd)
OUT=$(mkdir -p "${2:?dossier de sortie}" && cd "$2" && pwd)
VERSION=$(php -r 'echo (require "'"$SRC"'/config/app.php")["version"] ?? "0";' 2>/dev/null || true)
[ -n "$VERSION" ] || VERSION=$(grep -oP "'version' => '\K[^']+" "$SRC/config/app.php")
PHPZIP=php-8.3.37-nts-Win32-vs16-x64.zip
PHPSHA=38808a798485e1c0938dfcce7e942131415fc6c7834f83a5a85a4c4edb5539d6
MDBZIP=mariadb-10.11.20-winx64.zip
MDBSHA=$(grep -h "  .*${MDBZIP}\$" "$COMP"/mariadb*.sha 2>/dev/null | awk '{print $1}' | head -1 || true)
VCSHA=cc0ff0eb1dc3f5188ae6300faef32bf5beeba4bdd6e8e445a9184072096b713b

# Modules de l'édition commerciale (les autres ne sont pas livrés)
MODULES="banques comptabilite doublons fiscal livraisons marwa_ia pay"

echo "== POS Marwa Bureau $VERSION"
check() { echo "$2  $COMP/$1" | sha256sum -c --quiet || { echo "Empreinte incorrecte : $1" >&2; exit 1; }; }
check "$PHPZIP" "$PHPSHA"
check vc_redist.x64.exe "$VCSHA"
if [ -n "$MDBSHA" ]; then check "$MDBZIP" "$MDBSHA"; else echo "(empreinte MariaDB : fichier .sha absent, vérification sautée)"; fi

B="$OUT/construction"
rm -rf "$B"; mkdir -p "$B/POS Marwa"
R="$B/POS Marwa"

# ---------- 1. Application (fichiers suivis par git, version du dossier de travail)
echo "-- application"
mkdir -p "$R/app"
( cd "$SRC" && git ls-files -co --exclude-standard -z ) | while IFS= read -r -d '' f; do
  case "$f" in
    tests/*|windows/*|bureau/*|outils-auteur/*|demarrer-pos-marwa.bat|INSTALLATION_WAMPSERVER.md|SAAS.md|.env|.env.*|.git*|storage/*|public/uploads/*|public/assets/addons/*) continue ;;
    addons/*) m=${f#addons/}; m=${m%%/*}; case " $MODULES " in *" $m "*) ;; *) continue ;; esac ;;
  esac
  [ -f "$SRC/$f" ] || continue
  mkdir -p "$R/app/$(dirname "$f")"; cp -p "$SRC/$f" "$R/app/$f"
done
cp "$SRC/.env.example" "$R/app/.env.example"
cp "$SRC/bureau/routeur.php" "$R/app/routeur.php"
for d in storage/logs storage/backups storage/sessions storage/cache storage/tmp public/uploads/products; do mkdir -p "$R/app/$d"; touch "$R/app/$d/.gitkeep"; done
[ -f "$SRC/public/uploads/.htaccess" ] && cp "$SRC/public/uploads/.htaccess" "$R/app/public/uploads/"
APP_ENV=production APP_KEY=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d " \n") php "$SRC/bin/release.php" "$R/app" >/dev/null
echo "   $(find "$R/app" -type f | wc -l) fichiers, modules : $MODULES"

# ---------- 2. PHP portable (extensions utiles seulement)
echo "-- PHP"
mkdir -p "$R/php"; unzip -q "$COMP/$PHPZIP" -d "$R/php"
rm -rf "$R/php/dev" "$R/php/extras" "$R/php/lib" "$R/php/php-cgi.exe" "$R/php/phpdbg.exe" "$R/php/php8phpdbg.dll" "$R/php/php8embed.lib" "$R/php/deplister.exe" \
       "$R/php/php.ini-development" "$R/php/php.ini-production" "$R/php/phar.phar.bat" "$R/php/pharcommand.phar" "$R/php/libpq.dll" "$R/php/libenchant2.dll" "$R/php/glib-2.dll" "$R/php/gmodule-2.dll" "$R/php/gobject-2.dll"
KEEP="php_curl php_exif php_fileinfo php_gd php_intl php_mbstring php_openssl php_pdo_mysql php_mysqli php_sodium php_zip php_opcache"
for f in "$R"/php/ext/*.dll; do n=$(basename "$f" .dll); case " $KEEP " in *" $n "*) ;; *) rm -f "$f" ;; esac; done
cp "$SRC/bureau/php.ini" "$R/php/php.ini"

# ---------- 3. MariaDB portable (serveur et outils d'administration seulement)
echo "-- MariaDB"
T="$B/mdb"; mkdir -p "$T"; unzip -q "$COMP/$MDBZIP" -d "$T"
M=$(ls -d "$T"/mariadb-*); mkdir -p "$R/mariadb/bin"
# mysqld.exe : appelé sous ce nom par mariadb-install-db.exe pour créer la base (copie de mariadbd.exe)
for f in mariadbd.exe mysqld.exe server.dll mariadb.exe mariadb-admin.exe mariadb-install-db.exe mariadb-dump.exe mariadb-upgrade.exe mariadb-check.exe \
         vcruntime140.dll vcruntime140_1.dll msvcp140.dll msvcp140_1.dll msvcp140_2.dll concrt140.dll; do cp "$M/bin/$f" "$R/mariadb/bin/"; done
cp -r "$M/share" "$R/mariadb/share"
cp "$M/COPYING" "$R/mariadb/COPYING"; cp "$M/README.md" "$R/mariadb/README.md"
# Bibliothèques Visual C++ à côté de PHP (déploiement local autorisé par Microsoft) : PHP démarre même si le
# runtime n'est pas installé ; l'installateur l'installe aussi s'il manque.
for f in vcruntime140.dll vcruntime140_1.dll msvcp140.dll; do cp "$M/bin/$f" "$R/php/"; done

# ---------- 4. Lanceur « POS Marwa.exe » (icône et informations de version)
echo "-- lanceur"
G="$B/lanceur"; cp -r "$SRC/bureau/lanceur" "$G"
cp "$SRC/windows/pos-marwa.ico" "$G/pos-marwa.ico"
V4=$(echo "$VERSION" | awk -F. '{printf "%d,%d,%d,0", $1, $2, $3}')
cat > "$G/ressources.rc" <<EOF
1 ICON "pos-marwa.ico"
1 24 "lanceur.manifest"
1 VERSIONINFO
FILEVERSION $V4
PRODUCTVERSION $V4
BEGIN
  BLOCK "StringFileInfo"
  BEGIN
    BLOCK "040C04B0"
    BEGIN
      VALUE "CompanyName", "Mahamadou Hamidou (Mohamed)"
      VALUE "FileDescription", "POS Marwa"
      VALUE "FileVersion", "$VERSION"
      VALUE "ProductName", "POS Marwa Bureau"
      VALUE "ProductVersion", "$VERSION"
      VALUE "LegalCopyright", "Mahamadou Hamidou (Mohamed)"
      VALUE "OriginalFilename", "POS Marwa.exe"
    END
  END
  BLOCK "VarFileInfo"
  BEGIN
    VALUE "Translation", 0x040C, 1200
  END
END
EOF
cat > "$G/lanceur.manifest" <<'EOF'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<assembly xmlns="urn:schemas-microsoft-com:asm.v1" manifestVersion="1.0">
  <assemblyIdentity type="win32" name="POSMarwa.Lanceur" version="1.0.0.0"/>
  <trustInfo xmlns="urn:schemas-microsoft-com:asm.v3"><security><requestedPrivileges><requestedExecutionLevel level="asInvoker" uiAccess="false"/></requestedPrivileges></security></trustInfo>
  <application xmlns="urn:schemas-microsoft-com:asm.v3"><windowsSettings><dpiAware xmlns="http://schemas.microsoft.com/SMI/2005/WindowsSettings">true</dpiAware></windowsSettings></application>
  <compatibility xmlns="urn:schemas-microsoft-com:compatibility.v1"><application><supportedOS Id="{8e0f7a12-bfb3-4fe8-b9a5-48fd50a15a9a}"/><supportedOS Id="{1f676c76-80e1-4239-95bb-83d0f6d0da78}"/></application></compatibility>
</assembly>
EOF
( cd "$G" && x86_64-w64-mingw32-windres --preprocessor=cpp -O coff -i ressources.rc -o ressources_windows_amd64.syso \
  && GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags "-H=windowsgui -s -w -X main.version=$VERSION" -o "$R/POS Marwa.exe" . )

# ---------- 5. Fichiers de l'installation
cp "$SRC/bureau/pos-marwa.ini" "$R/pos-marwa.ini"
cp "$SRC/bureau/LISEZ-MOI.txt" "$R/LISEZ-MOI.txt"
cp "$SRC/bureau/LICENCES-TIERS.txt" "$R/LICENCES-TIERS.txt"
cp "$SRC/windows/pos-marwa.ico" "$R/pos-marwa.ico"
mkdir -p "$B/redist"; cp "$COMP/vc_redist.x64.exe" "$B/redist/"

# ---------- 5 bis. Contrôle : fichiers indispensables au démarrage (sans eux, POS Marwa ne démarre pas sous Windows)
for f in "POS Marwa.exe" php/php.exe php/php.ini php/php8.dll php/vcruntime140.dll php/ext/php_pdo_mysql.dll php/ext/php_mbstring.dll php/ext/php_sodium.dll php/ext/php_intl.dll \
         mariadb/bin/mariadbd.exe mariadb/bin/mysqld.exe mariadb/bin/server.dll mariadb/bin/mariadb-install-db.exe mariadb/bin/mariadb.exe mariadb/bin/mariadb-admin.exe \
         mariadb/share/english/errmsg.sys app/routeur.php app/public/index.php app/.env.example pos-marwa.ini; do
  [ -f "$R/$f" ] || { echo "Fichier indispensable absent du paquet : $f" >&2; exit 1; }
done
grep -q '^extension_dir = "ext"' "$R/php/php.ini" && grep -q 'extension_dir=' "$SRC/bureau/lanceur/main.go" || { echo "extension_dir : le lanceur doit fournir le chemin complet" >&2; exit 1; }

# ---------- 6. Installateur
echo "-- installateur"
cp "$SRC/bureau/installateur.nsi" "$B/installateur.nsi"
cp "$SRC/windows/pos-marwa.ico" "$B/pos-marwa.ico"
printf '\xEF\xBB\xBF' > "$B/licence.txt"; cat "$R/app/LICENCE.md" >> "$B/licence.txt"   # page « Contrat de licence » (UTF-8 avec BOM)
( cd "$B" && makensis -V2 -DVERSION="$VERSION" -DSORTIE="$OUT/POS-Marwa-Bureau-$VERSION-Setup.exe" installateur.nsi )
ls -la "$OUT/POS-Marwa-Bureau-$VERSION-Setup.exe"
( cd "$OUT" && sha256sum "POS-Marwa-Bureau-$VERSION-Setup.exe" )
echo "   contenu installé : $(du -sh "$R" | cut -f1)"
