@echo off
REM ==========================================================================
REM  POS Marwa - MARWA VOICE AI : installe la voix locale Piper (une seule fois)
REM  Internet necessaire pendant l'installation seulement (environ 80 Mo).
REM  Option : installer-voix.bat siwis-low   (voix plus legere pour PC modestes)
REM ==========================================================================
setlocal EnableExtensions
title POS Marwa - installation de la voix
call "%~dp0trouver-php.bat"
if not defined PHP (
  echo PHP introuvable. Verifiez que WampServer est installe.
  pause
  exit /b 1
)
echo PHP : %PHP%
"%PHP%" "%~dp0..\bin\voix.php" installer %1
if errorlevel 1 (
  echo.
  echo L'installation a echoue : voir le message ci-dessus.
  pause
  exit /b 1
)
"%PHP%" "%~dp0..\bin\voix.php" test "Bonjour, je suis la voix de POS Marwa."
echo.
echo Pour une voix plus rapide, le service vocal demarre avec POS Marwa (demarrer-pos-marwa.bat).
pause
endlocal
