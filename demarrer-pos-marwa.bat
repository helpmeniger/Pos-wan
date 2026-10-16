@echo off
REM ==========================================================================
REM  POS Marwa - demarrage : WampServer (Apache + MySQL/MariaDB) puis application
REM  (c) Mahamadou Hamidou (Mohamed) - assistance +227 92 08 12 82 / 87 22 52 02
REM
REM  Usage : double-clic (ou raccourci du bureau).  Option : --auto (demarrage
REM  de Windows : pas de pause, messages d'erreur en fenetre).
REM  Reglages facultatifs : windows\pos-marwa.ini (voir pos-marwa.ini.exemple)
REM ==========================================================================
setlocal EnableExtensions EnableDelayedExpansion
title POS Marwa - demarrage
set "APPDIR=%~dp0"
set "APPDIR=%APPDIR:~0,-1%"
set "AUTO=0"
if /I "%~1"=="--auto" set "AUTO=1"
if not exist "%APPDIR%\storage\logs" mkdir "%APPDIR%\storage\logs" >nul 2>&1
set "LOG=%APPDIR%\storage\logs\demarrage.log"
set "PF86=%ProgramFiles(x86)%"

REM ---------- Reglages (windows\pos-marwa.ini)
set "URL="
set "WAMP="
set "MODE=app"
set "WAIT=120"
if exist "%APPDIR%\windows\pos-marwa.ini" (
  for /f "usebackq eol=; tokens=1,* delims==" %%A in ("%APPDIR%\windows\pos-marwa.ini") do (
    if /I "%%A"=="URL" set "URL=%%B"
    if /I "%%A"=="WAMP" set "WAMP=%%B"
    if /I "%%A"=="NAVIGATEUR" set "MODE=%%B"
    if /I "%%A"=="ATTENTE" set "WAIT=%%B"
  )
)
for %%I in ("%APPDIR%") do set "FOLDER=%%~nxI"
if not defined URL set "URL=http://localhost/!FOLDER!/"
if not "!URL:~-1!"=="/" set "URL=!URL!/"
call :log "=== Demarrage (auto=%AUTO%) - adresse !URL!"

REM ---------- 1. Application deja disponible ?
call :check
if "!READY!"=="1" goto :open

REM ---------- 2. Trouver WampServer (chemins reels de cet ordinateur)
if defined WAMP if exist "!WAMP!\wampmanager.exe" goto :wampfound
REM a) projet place dans <WampServer>\www\<dossier>
for %%I in ("%APPDIR%\..\..") do set "CAND=%%~fI"
if exist "!CAND!\wampmanager.exe" (
  set "WAMP=!CAND!"
  goto :wampfound
)
REM b) registre Windows (programme d'installation de WampServer)
for %%K in (Wampserver64_is1 WampServer_is1 Wampserver_is1) do (
  for %%R in ("HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\%%K" "HKLM\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\%%K") do (
    for /f "tokens=2,*" %%A in ('reg query %%R /v InstallLocation 2^>nul ^| find /I "InstallLocation"') do (
      set "CAND=%%B"
      if "!CAND:~-1!"=="\" set "CAND=!CAND:~0,-1!"
      if exist "!CAND!\wampmanager.exe" (
        set "WAMP=!CAND!"
        goto :wampfound
      )
    )
  )
)
REM c) emplacements habituels
for %%D in (C D E F) do (
  for %%W in (wamp64 wamp) do (
    if exist "%%D:\%%W\wampmanager.exe" (
      set "WAMP=%%D:\%%W"
      goto :wampfound
    )
  )
)
call :fail "WampServer est introuvable sur cet ordinateur. Indiquez son dossier dans windows\pos-marwa.ini (ligne WAMP=C:\wamp64)."
exit /b 1

:wampfound
call :log "WampServer : !WAMP!"

REM ---------- 3. Lancer WampServer une seule fois
tasklist /FI "IMAGENAME eq wampmanager.exe" 2>nul | find /I "wampmanager.exe" >nul
if errorlevel 1 (
  echo Demarrage de WampServer...
  call :log "Lancement de wampmanager.exe"
  start "" "!WAMP!\wampmanager.exe"
) else (
  echo WampServer est deja lance : attente des services...
)
REM Services Windows de Wamp : demarres s'ils existent et sont arretes (droits administrateur requis)
for %%S in (wampapache64 wampapache wampmysqld64 wampmysqld wampmariadb64 wampmariadb) do (
  sc query %%S >nul 2>&1
  if not errorlevel 1 (
    sc query %%S | find "RUNNING" >nul
    if errorlevel 1 net start %%S >nul 2>&1
  )
)

REM ---------- 4. Attendre Apache + base de donnees + POS Marwa
echo Attente d'Apache et de MySQL/MariaDB (au plus %WAIT% secondes)...
set /a TRIES=%WAIT%/3
for /l %%N in (1,1,%TRIES%) do (
  call :check
  if "!READY!"=="1" goto :open
  timeout /t 3 /nobreak >nul
)
call :fail "POS Marwa ne repond pas a l adresse !URL! apres %WAIT% secondes. !DETAIL! Verifiez que l icone WampServer est VERTE (clic gauche puis Redemarrer les services). Journal : storage\logs\demarrage.log"
exit /b 1

REM ---------- 5. Ouvrir l'application (une seule fenetre)
:open
call :log "POS Marwa pret"
powershell -NoProfile -Command "$f=Join-Path $env:TEMP 'pos-marwa-ouvert.flag'; if((Test-Path $f) -and (((Get-Date)-(Get-Item $f).LastWriteTime).TotalSeconds -lt 60)){exit 1}; Set-Content -Path $f -Value (Get-Date); exit 0" >nul 2>&1
if errorlevel 1 (
  call :log "Fenetre deja ouverte il y a moins d'une minute : pas de nouvelle ouverture"
  goto :end
)
set "BR="
if /I "!MODE!"=="app" (
  for %%P in ("!PF86!\Microsoft\Edge\Application\msedge.exe" "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" "%ProgramFiles%\Google\Chrome\Application\chrome.exe" "!PF86!\Google\Chrome\Application\chrome.exe" "%LocalAppData%\Google\Chrome\Application\chrome.exe") do (
    if not defined BR if exist %%P set "BR=%%~P"
  )
)
if defined BR (
  start "" "!BR!" --app=!URL! --start-maximized
) else (
  start "" "!URL!"
)
echo POS Marwa est ouvert.
:end
endlocal
exit /b 0

REM ======================== sous-programmes ========================
:check
REM READY=1 si /health repond (application + base de donnees)
set "READY=0"
set "DETAIL="
powershell -NoProfile -Command "try{$r=Invoke-WebRequest -UseBasicParsing -TimeoutSec 4 -Uri '!URL!health'; if(($r.Content | ConvertFrom-Json).ok){exit 0}else{exit 2}}catch{$c=0; if($_.Exception.Response){$c=[int]$_.Exception.Response.StatusCode}; if($c -eq 503){exit 2}elseif($c -gt 0){exit 3}else{exit 1}}" >nul 2>&1
set "RC=%errorlevel%"
if "%RC%"=="0" set "READY=1"
if "%RC%"=="2" set "DETAIL=Apache repond mais la base de donnees MySQL/MariaDB est arretee."
if "%RC%"=="3" set "DETAIL=Apache repond mais l adresse ne correspond pas a POS Marwa (reglage URL= dans windows\pos-marwa.ini)."
if "%RC%"=="1" set "DETAIL=Apache ne repond pas."
exit /b 0

:log
>>"%LOG%" echo [%date% %time%] %~1
exit /b 0

:fail
call :log "ERREUR : %~1"
echo.
echo  *** POS Marwa : %~1
echo.
if "%AUTO%"=="1" (
  powershell -NoProfile -Command "Add-Type -AssemblyName PresentationFramework; [void][System.Windows.MessageBox]::Show('%~1','POS Marwa - demarrage','OK','Error')" >nul 2>&1
) else (
  pause
)
exit /b 1
