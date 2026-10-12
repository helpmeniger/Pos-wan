@echo off
chcp 65001 >nul
setlocal
title VERIFIA - demarrage
call "%~dp0env.bat" || goto fail
cd /d "%VERIFIA_ROOT%"
echo ============================================================
echo   VERIFIA - verification des prerequis
echo ============================================================
"%VERIFIA_PHP%" bin\check.php
if errorlevel 1 (
    echo.
    echo [ERREUR] Prerequis manquants : corrigez les points ci-dessus ^(voir docs\DEPANNAGE.md^).
    goto fail
)
where node >nul 2>nul || ( echo [ERREUR] Node.js introuvable dans le PATH. & goto fail )
if not exist "monitor\node_modules\playwright" ( echo [ERREUR] Moteur de surveillance non installe : lancez install.bat. & goto fail )
echo [OK]     Node.js
for /f "usebackq delims=" %%U in (`"%VERIFIA_PHP%" bin\base_url.php`) do set "VERIFIA_URL=%%U"
curl -s -o nul -w "%%{http_code}" "%VERIFIA_URL%/login.php" > "%VERIFIA_RUN%\http_check.txt" 2>nul
set /p HTTPCODE=<"%VERIFIA_RUN%\http_check.txt"
if not "%HTTPCODE%"=="200" if not "%HTTPCODE%"=="303" (
    echo [ERREUR] L'interface %VERIFIA_URL% ne repond pas ^(code "%HTTPCODE%"^).
    echo          Demarrez WampServer et attendez que son icone soit verte.
    goto fail
)
echo [OK]     Serveur web ^(%VERIFIA_URL%^)

rem Nettoyage des demandes d'arret precedentes
del /q "%VERIFIA_RUN%\stopping" "%VERIFIA_RUN%\worker.stop" "%VERIFIA_RUN%\monitor.stop" 2>nul

echo.
echo Demarrage du moteur d'analyse et du moteur de surveillance (fenetres reduites)...
start "VERIFIA - moteur d'analyse" /min cmd /c ""%~dp0run_worker.bat""
start "VERIFIA - moteur de surveillance" /min cmd /c ""%~dp0run_monitor.bat""
timeout /t 3 /nobreak >nul
start "" "%VERIFIA_URL%/"
echo.
echo VERIFIA est demarre. Pour tout arreter : scripts\windows\stop.bat
echo (ou bouton ARRET IMMEDIAT de l'interface pour arreter seulement la surveillance)
timeout /t 8
exit /b 0

:fail
pause
exit /b 1
