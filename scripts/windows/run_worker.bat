@echo off
chcp 65001 >nul
setlocal
call "%~dp0env.bat" || exit /b 1
title VERIFIA - moteur d'analyse
cd /d "%VERIFIA_ROOT%"
set /a RESTARTS=0
:loop
if exist "%VERIFIA_RUN%\stopping" goto end
"%VERIFIA_PHP%" bin\worker.php >> "storage\logs\worker.log" 2>&1
if exist "%VERIFIA_RUN%\stopping" goto end
set /a RESTARTS+=1
echo [VERIFIA] Le moteur d'analyse s'est arrete de facon inattendue (redemarrage %RESTARTS%/20 dans 5 s). Voir storage\logs\worker.log
if %RESTARTS% GEQ 20 (
    echo [VERIFIA] Trop de redemarrages : intervention necessaire.
    pause
    goto end
)
timeout /t 5 /nobreak >nul
goto loop
:end
exit /b 0
