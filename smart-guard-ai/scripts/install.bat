@echo off
setlocal EnableExtensions
title SMART GUARD AI - Installation
call "%~dp0_env.bat" || goto :fail
cd /d "%ROOT%"
echo ============================================================
echo   SMART GUARD AI - Installation
echo   PHP : %SG_PHP%
echo ============================================================
echo.
echo [1/6] Verification de PHP et des extensions
"%SG_PHP%" -r "exit(version_compare(PHP_VERSION,'8.1','>=')?0:1);" || (echo PHP 8.1 minimum requis & goto :fail)
for %%E in (pdo_mysql gd sodium curl fileinfo mbstring zip) do (
  "%SG_PHP%" -r "exit(extension_loaded('%%E')?0:1);" || echo   [ATTENTION] Extension %%E inactive : WampServer ^> PHP ^> Extensions PHP ^> %%E
)
echo.
echo [2/6] Generation des secrets et des fichiers .env
set /p DBPASS="Mot de passe a attribuer a l'utilisateur MySQL 'smartguard' (12 caracteres min.) : "
"%SG_PHP%" scripts\generate_keys.php --db-pass="%DBPASS%" --base-path=/smart-guard-ai || goto :fail
echo.
echo [3/6] Creation de la base de donnees (compte administrateur MySQL de WampServer)
set /p ROOTUSER="Utilisateur administrateur MySQL [root] : "
if "%ROOTUSER%"=="" set ROOTUSER=root
set /p ROOTPASS="Mot de passe de %ROOTUSER% (vide par defaut sous WampServer) : "
"%SG_PHP%" scripts\install_db.php --root-user="%ROOTUSER%" --root-pass="%ROOTPASS%" --create-user || goto :fail
echo.
echo [4/6] Compte administrateur de l'application
"%SG_PHP%" scripts\create_admin.php || goto :fail
echo.
echo [5/6] Environnement Python du service IA
if not defined SG_PY (
  echo [ERREUR] Python 3.10+ introuvable. Installez-le depuis python.org (cocher "Add to PATH") puis relancez.
  goto :fail
)
if not exist "ai-service\.venv\Scripts\python.exe" %SG_PY% -m venv "ai-service\.venv" || goto :fail
"ai-service\.venv\Scripts\python.exe" -m pip install --upgrade pip
"ai-service\.venv\Scripts\python.exe" -m pip install -r "ai-service\requirements.txt" || goto :fail
choice /M "Installer onnxruntime (recommande pour une meilleure reconnaissance, environ 15 Mo)"
if %errorlevel%==1 "ai-service\.venv\Scripts\python.exe" -m pip install onnxruntime
echo.
echo [6/6] Diagnostic
"%SG_PHP%" scripts\check_system.php
"ai-service\.venv\Scripts\python.exe" "ai-service\scripts\check_env.py"
echo.
echo Installation terminee. Etapes suivantes :
echo   1. Demarrez WampServer (icone verte).
echo   2. Lancez scripts\start.bat pour demarrer le service IA.
echo   3. Ouvrez http://localhost/smart-guard-ai/
echo   4. Programmez la maintenance : scripts\register_tasks.bat (en administrateur).
pause
exit /b 0
:fail
echo.
echo [ECHEC] L'installation s'est arretee. Consultez docs\INSTALLATION.md (section Depannage).
pause
exit /b 1
