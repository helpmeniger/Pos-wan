@echo off
setlocal EnableExtensions EnableDelayedExpansion
title MARWA - demarrage
cd /d "%~dp0"

echo ============================================
echo   MARWA - demarrage automatique
echo ============================================

rem --- 1. WampServer : le lancer s'il n'est pas deja demarre -----------------
set "WAMPDIR="
for %%W in ("C:\wamp64" "C:\wamp" "D:\wamp64" "D:\wamp") do if not defined WAMPDIR if exist "%%~W\wampmanager.exe" set "WAMPDIR=%%~W"
tasklist /FI "IMAGENAME eq wampmanager.exe" 2>nul | find /I "wampmanager.exe" >nul
if errorlevel 1 (
  if defined WAMPDIR (
    echo Lancement de WampServer...
    start "" "!WAMPDIR!\wampmanager.exe"
    echo Attente du demarrage d'Apache et de MySQL ^(20 s^)...
    timeout /t 20 /nobreak >nul
  ) else (
    echo [!] WampServer introuvable : demarrez-le manuellement.
  )
) else (
  echo WampServer est deja demarre.
)

rem --- 2. PHP de WampServer ---------------------------------------------------
set "PHP="
if defined WAMPDIR for /d %%D in ("!WAMPDIR!\bin\php\php8*") do if exist "%%~D\php.exe" set "PHP=%%~D\php.exe"
if not defined PHP (
  where php >nul 2>nul && set "PHP=php"
)
if not defined PHP (
  echo [X] PHP introuvable. Installez WampServer ^(PHP 8.1 ou plus^).
  pause
  exit /b 1
)
echo PHP : !PHP!

rem --- 3. Adresse de l'application (dossier sous www) -------------------------
set "P=%~dp0"
set "REL=!P:*\www\=!"
if "!REL!"=="!P!" (
  echo [!] Ce dossier n'est pas dans le dossier www de WampServer.
  echo     Deplacez-le dans C:\wamp64\www\ puis relancez ce fichier.
  pause
  exit /b 1
)
set "REL=!REL:\=/!"
if "!REL:~-1!"=="/" set "REL=!REL:~0,-1!"
set "URL=http://localhost/!REL!"

rem --- 4. Installation / mise a jour de la base --------------------------------
set "TRY=0"
:install
"!PHP!" bin\install.php --url=!URL!
if errorlevel 1 (
  set /a TRY+=1
  if !TRY! LSS 4 (
    echo Nouvel essai dans 10 s...
    timeout /t 10 /nobreak >nul
    goto install
  )
  echo [X] Installation impossible. Verifiez que l'icone WampServer est VERTE.
  pause
  exit /b 1
)

rem --- 5. Taches de fond (attribution des courses, messages) ------------------
tasklist /V /FI "IMAGENAME eq php.exe" 2>nul | find /I "MARWA-TACHES" >nul
if errorlevel 1 start "MARWA-TACHES (ne pas fermer)" /MIN "!PHP!" bin\worker.php

rem --- 6. Ouverture du navigateur ---------------------------------------------
echo.
echo MARWA est pret : !URL!
start "" "!URL!/"
timeout /t 5 >nul
exit /b 0
