@echo off
chcp 65001 >nul
setlocal
call "%~dp0env.bat" || exit /b 1
echo Arret de VERIFIA : fermeture des navigateurs et des moteurs...
echo stop> "%VERIFIA_RUN%\stopping"
echo stop> "%VERIFIA_RUN%\worker.stop"
echo stop> "%VERIFIA_RUN%\monitor.stop"
rem Laisse aux moteurs le temps de s'arreter proprement (fermeture des navigateurs)
timeout /t 12 /nobreak >nul
taskkill /fi "WINDOWTITLE eq VERIFIA - moteur de surveillance*" /t /f >nul 2>nul
taskkill /fi "WINDOWTITLE eq VERIFIA - moteur d'analyse*" /t /f >nul 2>nul
echo VERIFIA est arrete. (WampServer reste actif ; les alertes restent consultables.)
timeout /t 5
