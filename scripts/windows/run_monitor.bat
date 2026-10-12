@echo off
chcp 65001 >nul
setlocal
call "%~dp0env.bat" || exit /b 1
title VERIFIA - moteur de surveillance
cd /d "%VERIFIA_ROOT%\monitor"
set /a RESTARTS=0
:loop
if exist "%VERIFIA_RUN%\stopping" goto end
node src\index.js >> "%VERIFIA_ROOT%\storage\logs\monitor.log" 2>&1
if exist "%VERIFIA_RUN%\stopping" goto end
set /a RESTARTS+=1
echo [VERIFIA] Le moteur de surveillance s'est arrete (redemarrage %RESTARTS%/20 dans 10 s). Voir storage\logs\monitor.log
if %RESTARTS% GEQ 20 (
    echo [VERIFIA] Trop de redemarrages : intervention necessaire.
    pause
    goto end
)
timeout /t 10 /nobreak >nul
goto loop
:end
exit /b 0
