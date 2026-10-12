@echo off
chcp 65001 >nul
call "%~dp0env.bat" || ( pause & exit /b 1 )
cd /d "%VERIFIA_ROOT%"
"%VERIFIA_PHP%" bin\check.php
where node >nul 2>nul && ( echo [OK]     Node.js & node -v ) || echo [ERREUR] Node.js introuvable
if exist "monitor\node_modules\playwright" ( echo [OK]     Playwright installe ) else ( echo [ERREUR] Playwright absent : lancez install.bat )
pause
