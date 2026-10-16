@echo off
call "%~dp0_env.bat" || exit /b 1
cd /d "%ROOT%"
if "%~1"=="" (
  "%SG_PHP%" scripts\restore.php
  echo Usage : scripts\restore.bat nom_du_fichier.sql.gz
  pause
  exit /b 1
)
"%SG_PHP%" scripts\restore.php "%~1"
pause
