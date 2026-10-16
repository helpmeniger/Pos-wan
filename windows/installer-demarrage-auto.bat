@echo off
REM ==========================================================================
REM  POS Marwa - demarrage automatique a l'ouverture de session Windows
REM  Cree la tache planifiee "POS Marwa" (Planificateur de taches) :
REM   - declencheur : ouverture de session de l'utilisateur actuel (+30 s)
REM   - action : demarrer-pos-marwa.bat --auto (WampServer puis POS Marwa)
REM   - privileges les plus eleves : les services de WampServer peuvent etre
REM     demarres sans confirmation a chaque fois.
REM  La creation demande UNE fois l'autorisation administrateur (fenetre UAC de
REM  Windows) : aucun mecanisme de securite n'est contourne.
REM ==========================================================================
setlocal
net session >nul 2>&1
if errorlevel 1 (
  echo Autorisation administrateur demandee par Windows...
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b 0
)
for %%I in ("%~dp0..") do set "APPDIR=%%~fI"
schtasks /Create /F /TN "POS Marwa" /SC ONLOGON /DELAY 0000:30 /RL HIGHEST /TR "\"%APPDIR%\demarrer-pos-marwa.bat\" --auto"
if errorlevel 1 (
  echo.
  echo La tache planifiee n'a pas pu etre creee. Voir docs\DEMARRAGE_WINDOWS.md
  pause
  exit /b 1
)
echo.
echo Demarrage automatique active : a chaque ouverture de session, WampServer
echo puis POS Marwa s'ouvriront automatiquement.
echo Pour le retirer : windows\desinstaller-demarrage-auto.bat
pause
exit /b 0
