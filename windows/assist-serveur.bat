@echo off
REM ==========================================================================
REM  POS Marwa - MARWA REMOTE ASSIST : serveur d'assistance High Tech Niger
REM  (RustDesk Server : presence + relais). A lancer sur le PC ou le serveur HTN
REM  joignable depuis Internet (adresse IP publique ; aucun nom de domaine).
REM  Ouvre le pare-feu Windows pour TCP 21115-21117 et UDP 21116, puis demarre.
REM  La fenetre doit rester ouverte (fermer = arreter le serveur).
REM ==========================================================================
setlocal EnableExtensions
title POS Marwa - serveur d'assistance
net session >nul 2>&1
if errorlevel 1 (
  echo Droits administrateur necessaires pour ouvrir le pare-feu : acceptez la fenetre de Windows.
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
call "%~dp0trouver-php.bat"
if not defined PHP (
  echo PHP introuvable. Verifiez que WampServer est installe.
  pause
  exit /b 1
)
netsh advfirewall firewall delete rule name="POS Marwa assistance TCP" >nul 2>&1
netsh advfirewall firewall delete rule name="POS Marwa assistance UDP" >nul 2>&1
netsh advfirewall firewall add rule name="POS Marwa assistance TCP" dir=in action=allow protocol=TCP localport=21115-21117 >nul
netsh advfirewall firewall add rule name="POS Marwa assistance UDP" dir=in action=allow protocol=UDP localport=21116 >nul
echo Pare-feu Windows : TCP 21115-21117 et UDP 21116 autorises.
echo Pensez a rediriger ces memes ports de la box vers ce PC.
"%PHP%" "%~dp0..\bin\assist.php" deployer
"%PHP%" "%~dp0..\bin\assist.php" serveur
pause
endlocal
