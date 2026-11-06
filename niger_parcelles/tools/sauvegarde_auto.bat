@echo off
REM Niger Parcelles - sauvegarde automatique (a planifier avec le Planificateur de taches Windows)
REM Conserve 30 jours de sauvegardes dans storage\backups. Copiez-les aussi sur un support externe.
setlocal
set APP=%~dp0..
set PHP=
for /d %%D in (C:\wamp64\bin\php\php8*) do set PHP=%%D\php.exe
if not exist "%PHP%" (
  echo PHP introuvable dans C:\wamp64\bin\php : modifiez la variable PHP de ce fichier.
  exit /b 1
)
"%PHP%" "%APP%\tools\sauvegarde_auto.php" 30
exit /b %ERRORLEVEL%
