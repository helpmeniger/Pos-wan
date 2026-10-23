@echo off
REM ==========================================================================
REM  POS Marwa - Clinique : recepteur des images des appareils (DICOM) + dossier
REM  d arrivee. A lancer UNE fois en administrateur : ouvre le port DICOM dans le
REM  pare-feu Windows (reseau prive seulement), puis le recepteur demarre ensuite
REM  automatiquement avec demarrer-pos-marwa.bat.
REM ==========================================================================
setlocal EnableExtensions
title POS Marwa - recepteur d images (installation)
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
set "PORT=11112"
for /f "delims=" %%p in ('"%PHP%" "%~dp0..\bin\imagerie.php" port') do set "PORT=%%p"
netsh advfirewall firewall delete rule name="POS Marwa imagerie DICOM" >nul 2>&1
netsh advfirewall firewall add rule name="POS Marwa imagerie DICOM" dir=in action=allow protocol=TCP localport=%PORT% profile=private,domain >nul
echo Pare-feu Windows : port %PORT% ouvert pour le reseau prive de la clinique (jamais pour Internet).
if not exist "%~dp0..\storage\imagerie" mkdir "%~dp0..\storage\imagerie"
if not exist "%~dp0..\storage\imagerie\arrivee" mkdir "%~dp0..\storage\imagerie\arrivee"
echo auto> "%~dp0..\storage\imagerie\demarrage-auto"
echo Le recepteur demarrera desormais automatiquement avec POS Marwa.
echo Dossier d arrivee (a partager pour les appareils sans reseau DICOM) : %~dp0..\storage\imagerie\arrivee
"%PHP%" "%~dp0..\bin\imagerie.php" serveur
pause
endlocal
