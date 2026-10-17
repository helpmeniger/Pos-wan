@echo off
REM SMART GUARD AI - Détection de PHP (WampServer) et de Python. Appelé par les autres scripts.
set "ROOT=%~dp0.."
if defined SG_PHP goto :php_ok
for /f "delims=" %%D in ('dir /b /ad /o-n "C:\wamp64\bin\php\php8.*" 2^>nul') do (
  if not defined SG_PHP if exist "C:\wamp64\bin\php\%%D\php.exe" set "SG_PHP=C:\wamp64\bin\php\%%D\php.exe"
)
if not defined SG_PHP for /f "delims=" %%P in ('where php 2^>nul') do if not defined SG_PHP set "SG_PHP=%%P"
:php_ok
if not defined SG_PHP (
  echo [ERREUR] PHP introuvable. Installez WampServer ou definissez la variable SG_PHP vers php.exe
  exit /b 1
)
REM Python : recherche fiable dans ai-service\find_python.bat (appelee seulement si besoin)
exit /b 0
