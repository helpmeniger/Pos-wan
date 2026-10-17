@echo off
REM SMART GUARD AI - Demarrage du service IA (fenetre dediee, redemarrage automatique en cas d'arret)
setlocal
title SMART GUARD AI - Service IA
cd /d "%~dp0"
if not exist ".venv\Scripts\python.exe" goto :setup
if not exist ".env" goto :setup
".venv\Scripts\python.exe" -c "import cv2, fastapi, uvicorn" >nul 2>nul || goto :setup
goto :loop
:setup
echo Environnement du service IA absent ou incomplet : preparation automatique...
call "%~dp0setup_ai.bat" auto
if errorlevel 1 (
  echo [ERREUR] Le service IA ne peut pas demarrer. Corrigez le probleme ci-dessus puis relancez.
  pause
  exit /b 1
)
:loop
echo [%date% %time%] Demarrage du service IA sur http://127.0.0.1:8765 ...
".venv\Scripts\python.exe" -m app
echo [%date% %time%] Le service IA s'est arrete (code %errorlevel%). Redemarrage dans 5 secondes... (Ctrl+C pour quitter)
timeout /t 5 /nobreak >nul
goto loop
