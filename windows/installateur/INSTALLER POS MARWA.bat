@echo off
rem Installateur POS Marwa : ouvre l'assistant (droits administrateur demandes une fois par Windows).
cd /d "%~dp0"
set "PS1=%~dp0installateur\installateur.ps1"
if not exist "%PS1%" set "PS1=%~dp0installateur.ps1"
if not exist "%PS1%" (
  echo Fichier installateur.ps1 introuvable a cote de ce fichier.
  pause
  exit /b 1
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -WindowStyle Minimized -File "%PS1%"
