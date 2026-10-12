@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion
title VERIFIA - installation
echo ============================================================
echo   VERIFIA - installation
echo ============================================================
call "%~dp0env.bat" || goto fail
echo PHP : %VERIFIA_PHP%
"%VERIFIA_PHP%" -v | findstr /b "PHP"

rem --- 1. Dependances PHP (Composer) -----------------------------------------
cd /d "%VERIFIA_ROOT%"
if exist "vendor\autoload.php" (
    echo [OK] Dependances PHP deja installees.
) else (
    where composer >nul 2>nul
    if !errorlevel! EQU 0 (
        call composer install --no-dev --no-interaction --optimize-autoloader || goto fail
    ) else if exist "composer.phar" (
        "%VERIFIA_PHP%" composer.phar install --no-dev --no-interaction --optimize-autoloader || goto fail
    ) else (
        echo [ERREUR] Composer introuvable. Installez-le depuis https://getcomposer.org/download/
        echo          ou placez composer.phar dans %VERIFIA_ROOT%, puis relancez install.bat.
        goto fail
    )
)

rem --- 2. Node.js et moteur de surveillance ----------------------------------
where node >nul 2>nul || (
    echo [ERREUR] Node.js introuvable. Installez Node.js 20 LTS ou plus recent : https://nodejs.org/
    goto fail
)
node -e "process.exit(+process.versions.node.split('.')[0]>=18?0:1)" || (
    echo [ERREUR] Node.js 18 minimum est requis. Version actuelle :
    node -v
    goto fail
)
cd /d "%VERIFIA_ROOT%\monitor"
call npm install --omit=dev --no-audit --no-fund || goto fail
echo.
echo Installation du navigateur Chromium de Playwright (environ 150 Mo)...
call npx playwright install chromium
if errorlevel 1 (
    echo [ATTENTION] Telechargement de Chromium impossible.
    echo             Solution : dans config\config.php, mettez 'browser_channel' =^> 'msedge'
    echo             pour utiliser Microsoft Edge deja installe sur Windows.
)

rem --- 3. Base de donnees, configuration, administrateur ---------------------
cd /d "%VERIFIA_ROOT%"
echo.
echo Parametres MySQL de WampServer (Entree = valeur par defaut)
set "DBUSER=root"
set /p "DBUSER=  Utilisateur MySQL [root] : "
set "DBPASS="
set /p "DBPASS=  Mot de passe MySQL [vide] : "
set "ADMINUSER=admin"
set /p "ADMINUSER=  Identifiant de l'administrateur VERIFIA [admin] : "
set "ADMINPASS="
set /p "ADMINPASS=  Mot de passe administrateur VERIFIA (10 caracteres min., vide = creer plus tard dans le navigateur) : "
if "%ADMINPASS%"=="" (
    "%VERIFIA_PHP%" bin\install.php --db-user="%DBUSER%" --db-pass="%DBPASS%" || goto fail
) else (
    "%VERIFIA_PHP%" bin\install.php --db-user="%DBUSER%" --db-pass="%DBPASS%" --admin-user="%ADMINUSER%" --admin-pass="%ADMINPASS%" || goto fail
)
echo.
echo ============================================================
echo   Installation terminee. Lancez scripts\windows\start.bat
echo ============================================================
pause
exit /b 0

:fail
echo.
echo [ECHEC] L'installation n'a pas abouti. Consultez docs\DEPANNAGE.md
pause
exit /b 1
