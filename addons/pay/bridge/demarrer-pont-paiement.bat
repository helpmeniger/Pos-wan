@echo off
REM Marwa Pay Bridge : pont local entre POS Marwa et le terminal de paiement branche sur ce poste.
REM Pour un demarrage automatique : Planificateur de taches > a l ouverture de session > ce fichier.
cd /d "%~dp0"
set PHPEXE=php
for /d %%D in ("C:\wamp64\bin\php\php8*") do set PHPEXE=%%D\php.exe
if not exist bridge.json (
  echo Copiez bridge.example.json en bridge.json et renseignez le secret et le port COM.
  pause
  exit /b 1
)
"%PHPEXE%" marwa-pay-bridge.php bridge.json
pause
