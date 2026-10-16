@echo off
REM POS Marwa - retire la tache planifiee de demarrage automatique
setlocal
net session >nul 2>&1
if errorlevel 1 (
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b 0
)
schtasks /Delete /F /TN "POS Marwa"
echo Demarrage automatique de POS Marwa retire.
pause
exit /b 0
