@echo off
REM SMART GUARD AI - Prepare le service IA : environnement Python (.venv), dependances, fichier .env.
REM Peut etre relance sans risque. Necessite Internet la premiere fois (telechargement des dependances).
setlocal
cd /d "%~dp0"
call "%~dp0find_python.bat" || goto :fail
%SG_PY% "%~dp0scripts\setup_env.py" || goto :fail
if "%~1"=="" pause
exit /b 0
:fail
echo.
echo [ECHEC] Preparation du service IA impossible. Voir les messages ci-dessus.
if "%~1"=="" pause
exit /b 1
