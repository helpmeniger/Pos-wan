@echo off
REM SMART GUARD AI - Arrete le service IA (n'arrete pas WampServer).
taskkill /fi "WINDOWTITLE eq SMART GUARD AI - Service IA*" /t /f >nul 2>nul && echo Service IA arrete. || echo Le service IA n'etait pas demarre.
timeout /t 2 >nul
