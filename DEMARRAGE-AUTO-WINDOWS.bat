@echo off
setlocal EnableExtensions
rem Lance MARWA (et WampServer) automatiquement a chaque ouverture de session Windows.
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
if /I "%~1"=="/retirer" (
  del "%STARTUP%\MARWA.bat" >nul 2>nul
  echo Demarrage automatique de MARWA desactive.
  pause
  exit /b 0
)
> "%STARTUP%\MARWA.bat" echo @call "%~dp0DEMARRER-MARWA.bat"
echo Demarrage automatique active : MARWA se lancera a chaque ouverture de session Windows.
echo Pour le desactiver : DEMARRAGE-AUTO-WINDOWS.bat /retirer
pause
