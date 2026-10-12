@echo off
rem ============================================================================
rem  VERIFIA - variables communes (appelé par les autres scripts)
rem  Détecte le php.exe de WampServer ; surcharge possible : set VERIFIA_PHP=...
rem ============================================================================
for %%I in ("%~dp0..\..") do set "VERIFIA_ROOT=%%~fI"
if not defined WAMP_DIR set "WAMP_DIR=C:\wamp64"

if defined VERIFIA_PHP goto php_found
for /d %%D in ("%WAMP_DIR%\bin\php\php8*") do (
    if exist "%%D\php.exe" set "VERIFIA_PHP=%%D\php.exe"
)
if defined VERIFIA_PHP goto php_found
where php >nul 2>nul && for /f "delims=" %%P in ('where php') do if not defined VERIFIA_PHP set "VERIFIA_PHP=%%P"
if defined VERIFIA_PHP goto php_found
echo [ERREUR] php.exe introuvable. Installez WampServer dans %WAMP_DIR% ou definissez VERIFIA_PHP.
exit /b 1

:php_found
for %%F in ("%VERIFIA_PHP%") do set "VERIFIA_PHP_DIR=%%~dpF"
set "PATH=%VERIFIA_PHP_DIR%;%PATH%"
set "VERIFIA_RUN=%VERIFIA_ROOT%\storage\run"
if not exist "%VERIFIA_RUN%" mkdir "%VERIFIA_RUN%"
exit /b 0
