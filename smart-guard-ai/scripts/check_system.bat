@echo off
title SMART GUARD AI - Diagnostic
call "%~dp0_env.bat" || (pause & exit /b 1)
cd /d "%ROOT%"
echo === Application PHP ===
"%SG_PHP%" scripts\check_system.php
echo.
echo === Service IA (Python) ===
if exist "ai-service\.venv\Scripts\python.exe" (
  "ai-service\.venv\Scripts\python.exe" "ai-service\scripts\check_env.py"
) else (
  echo Environnement Python absent : lancez ai-service\setup_ai.bat
)
echo.
echo Journaux : storage\logs\  et  ai-service\runtime\ai-service.log
pause
