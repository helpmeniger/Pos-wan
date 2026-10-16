@echo off
REM SMART GUARD AI - Démarrage du service IA (fenêtre dédiée, redémarrage automatique en cas d'arrêt)
setlocal
cd /d "%~dp0"
if not exist ".venv\Scripts\python.exe" (
  echo [ERREUR] Environnement Python absent. Lancez d'abord scripts\install.bat
  pause
  exit /b 1
)
if not exist ".env" (
  echo [ERREUR] ai-service\.env absent. Lancez scripts\install.bat ou copiez .env.example
  pause
  exit /b 1
)
:loop
echo [%date% %time%] Demarrage du service IA...
".venv\Scripts\python.exe" -m app
echo [%date% %time%] Le service IA s'est arrete (code %errorlevel%). Redemarrage dans 5 secondes... (Ctrl+C pour quitter)
timeout /t 5 /nobreak >nul
goto loop
