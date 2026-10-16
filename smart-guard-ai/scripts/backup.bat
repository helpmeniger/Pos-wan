@echo off
call "%~dp0_env.bat" || exit /b 1
cd /d "%ROOT%"
"%SG_PHP%" scripts\backup.php
echo Pensez a copier regulierement storage\backups\ et le fichier .env sur un support externe.
if "%1"=="" pause
