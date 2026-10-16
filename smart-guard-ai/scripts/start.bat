@echo off
REM SMART GUARD AI - Demarre le service IA. WampServer (Apache + MySQL) se demarre SEPAREMENT.
setlocal
call "%~dp0_env.bat" || (pause & exit /b 1)
cd /d "%ROOT%"
tasklist /v /fi "WINDOWTITLE eq SMART GUARD AI - Service IA*" | find /i "cmd.exe" >nul && (
  echo Le service IA est deja demarre.
  goto :open
)
sc query wampapache64 | find "RUNNING" >nul || echo [ATTENTION] Apache (WampServer) ne semble pas demarre : lancez WampServer.
start "SMART GUARD AI - Service IA" /min cmd /c "%ROOT%\ai-service\run_ai.bat"
echo Service IA en cours de demarrage (fenetre reduite "SMART GUARD AI - Service IA")...
timeout /t 6 /nobreak >nul
:open
start "" "http://localhost/smart-guard-ai/"
