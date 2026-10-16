@echo off
REM ==========================================================================
REM  POS Marwa - cree le raccourci "POS Marwa" sur le Bureau et dans le menu
REM  Demarrer (icone POS Marwa). Le raccourci lance demarrer-pos-marwa.bat :
REM  WampServer est demarre si besoin puis l'application s'ouvre.
REM ==========================================================================
setlocal
for %%I in ("%~dp0..") do set "APPDIR=%%~fI"
if not exist "%APPDIR%\demarrer-pos-marwa.bat" (
  echo demarrer-pos-marwa.bat introuvable dans %APPDIR%
  pause
  exit /b 1
)
powershell -NoProfile -Command "$w=New-Object -ComObject WScript.Shell; foreach($d in @([Environment]::GetFolderPath('Desktop'), [Environment]::GetFolderPath('Programs'))){ $s=$w.CreateShortcut((Join-Path $d 'POS Marwa.lnk')); $s.TargetPath=$env:APPDIR + '\demarrer-pos-marwa.bat'; $s.WorkingDirectory=$env:APPDIR; $s.IconLocation=$env:APPDIR + '\windows\pos-marwa.ico,0'; $s.WindowStyle=7; $s.Description='POS Marwa - caisse et gestion'; $s.Save() }"
if errorlevel 1 (
  echo Le raccourci n'a pas pu etre cree.
  pause
  exit /b 1
)
echo Raccourci "POS Marwa" cree sur le Bureau et dans le menu Demarrer.
echo Double-cliquez dessus pour demarrer WampServer et ouvrir POS Marwa.
pause
exit /b 0
