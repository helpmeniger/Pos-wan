@echo off
REM ==========================================================================
REM  POS Marwa - MARWA VOICE AI : met en place la voix locale Piper.
REM  La voix est livree dans le dossier POS Marwa (addons\marwa_voice\piper) :
REM  aucune connexion Internet n'est necessaire. Ce script n'est utile que pour
REM  verifier l'installation : elle se fait aussi toute seule au premier usage.
REM ==========================================================================
setlocal EnableExtensions
title POS Marwa - voix
call "%~dp0trouver-php.bat"
if not defined PHP (
  echo PHP introuvable. Verifiez que WampServer est installe.
  pause
  exit /b 1
)
echo PHP : %PHP%
"%PHP%" "%~dp0..\bin\voix.php" deployer
if errorlevel 1 (
  echo.
  echo Voix livree absente ou abimee : telechargement depuis Internet...
  "%PHP%" "%~dp0..\bin\voix.php" installer %1
  if errorlevel 1 (
    echo L'installation a echoue : voir le message ci-dessus.
    pause
    exit /b 1
  )
)
"%PHP%" "%~dp0..\bin\voix.php" test "Bonjour, je suis la voix de POS Marwa."
echo.
echo Le service vocal (voix plus rapide) demarre avec POS Marwa (demarrer-pos-marwa.bat).
pause
endlocal
