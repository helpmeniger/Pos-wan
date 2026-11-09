@echo off
setlocal EnableExtensions
title Installateur POS Marwa
rem Installateur POS Marwa - Windows 7 SP1, 8.1, 10, 11 (32 ou 64 bits).
rem Demande les droits administrateur, debloque ses fichiers, controle PowerShell puis ouvre l'assistant.
rem Ne desactive jamais l'antivirus, SmartScreen, l'UAC ni le pare-feu.
cd /d "%~dp0"

set "POSM_DIR=%~dp0"
set "POSM_PS1=%~dp0installateur\installateur.ps1"
if not exist "%POSM_PS1%" set "POSM_PS1=%~dp0installateur.ps1"
if not exist "%POSM_PS1%" goto :introuvable

rem PowerShell 64 bits meme si ce fichier est lance par un programme 32 bits
set "POSM_PS=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
if defined PROCESSOR_ARCHITEW6432 set "POSM_PS=%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe"
if not exist "%POSM_PS%" goto :sanspowershell

rem Droits administrateur : sinon, relance de ce fichier avec la demande d'autorisation de Windows
"%SystemRoot%\System32\fltmc.exe" >nul 2>&1
if errorlevel 1 goto :elever

echo.
echo  Installateur POS Marwa : verification de Windows...

rem Retire la marque "telecharge depuis Internet" des fichiers de l'installateur et des zip a cote
"%POSM_PS%" -NoProfile -ExecutionPolicy Bypass -Command "Get-ChildItem -LiteralPath $env:POSM_DIR -File -ErrorAction SilentlyContinue | Unblock-File -ErrorAction SilentlyContinue; Get-ChildItem -LiteralPath (Join-Path $env:POSM_DIR 'installateur') -File -ErrorAction SilentlyContinue | Unblock-File -ErrorAction SilentlyContinue" >nul 2>&1

rem Controle : version de PowerShell, mode restreint, strategie de groupe
set "POSM_CHECK="
set "POSM_TMP=%TEMP%\posmarwa-verification.txt"
"%POSM_PS%" -NoProfile -ExecutionPolicy Bypass -Command "$v=$PSVersionTable.PSVersion.Major; if($v -lt 3){'PS'+$v; exit}; if($ExecutionContext.SessionState.LanguageMode -ne 'FullLanguage'){'CLM'; exit}; $b=@(); foreach($s in 'MachinePolicy','UserPolicy'){$p=[string](Get-ExecutionPolicy -Scope $s); if(@('AllSigned','Restricted','Default') -contains $p){$b+=$s+'='+$p}}; if($b.Count){'GPO:'+($b -join ',')}else{'OK'}" > "%POSM_TMP%" 2>nul
set /p POSM_CHECK=<"%POSM_TMP%"
del "%POSM_TMP%" >nul 2>&1
if not defined POSM_CHECK goto :ancien
if /i "%POSM_CHECK:~0,2%"=="PS" goto :ancien
if /i "%POSM_CHECK%"=="CLM" goto :restreint
if /i "%POSM_CHECK:~0,4%"=="GPO:" goto :strategie

"%POSM_PS%" -NoProfile -ExecutionPolicy Bypass -STA -File "%POSM_PS1%" -Depuis "%~dp0."
set "POSM_CODE=%errorlevel%"
if "%POSM_CODE%"=="0" exit /b 0
echo.
echo  L'installateur s'est arrete avec le code %POSM_CODE%.
echo  Journal : C:\POS-Marwa-Installation\installation.log
pause
exit /b %POSM_CODE%

:elever
set "POSM_BAT=%~f0"
"%POSM_PS%" -NoProfile -ExecutionPolicy Bypass -Command "try { Start-Process -FilePath $env:POSM_BAT -Verb RunAs -ErrorAction Stop } catch { exit 1 }"
if errorlevel 1 goto :refus
exit /b 0

:refus
echo.
echo  L'autorisation administrateur a ete refusee.
echo  Relancez "INSTALLER POS MARWA.bat" et repondez "Oui" a la demande de Windows
echo  (sur un compte standard, saisissez le mot de passe d'un administrateur).
pause
exit /b 5

:introuvable
echo.
echo  Fichier installateur.ps1 introuvable a cote de ce fichier.
echo %~dp0 | find /i "\Temp\" >nul && echo  Vous avez ouvert ce fichier directement dans le zip : faites d'abord un clic droit sur le zip, "Extraire tout...", puis lancez INSTALLER POS MARWA.bat depuis le dossier extrait.
pause
exit /b 1

:sanspowershell
echo.
echo  Windows PowerShell est introuvable sur ce PC (%POSM_PS%).
echo  Il est indispensable a l'installateur. Sur Windows 7, installez "Windows Management Framework 5.1" (Microsoft).
pause
exit /b 2

:ancien
echo.
echo  Windows PowerShell est trop ancien (%POSM_CHECK%) : la version 3.0 au minimum est necessaire.
echo  Windows 7 : installez .NET Framework 4.8 puis "Windows Management Framework 5.1" (gratuits, site de Microsoft),
echo  redemarrez, puis relancez l'installateur.
pause
exit /b 3

:restreint
echo.
echo  PowerShell fonctionne en mode restreint sur ce PC (AppLocker, Device Guard / WDAC
echo  ou Controle intelligent des applications). Cette protection empeche l'installateur de fonctionner :
echo  elle doit etre levee par l'administrateur du PC. L'installateur ne la contourne pas.
pause
exit /b 4

:strategie
echo.
echo  Une strategie de groupe interdit les scripts PowerShell (%POSM_CHECK:~4%).
echo  Pour l'autoriser (administrateur) : gpedit.msc, Configuration ordinateur, Modeles d'administration,
echo  Composants Windows, Windows PowerShell, "Activer l'execution des scripts" : Non configure
echo  (ou "Autoriser les scripts locaux et les scripts signes distants"). Sur un PC d'entreprise,
echo  demandez-le au service informatique. L'installateur ne contourne pas cette strategie.
pause
exit /b 6
