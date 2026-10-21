@echo off
REM POS Marwa - cherche php.exe (WampServer) et le place dans la variable PHP
set "PHP="
for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP set "PHP=%%P"
if defined PHP exit /b 0
for %%W in ("%~dp0..\..\.." "C:\wamp64" "C:\wamp" "D:\wamp64" "D:\wamp") do (
  if exist "%%~W\bin\php" (
    for /f "delims=" %%D in ('dir /b /ad /o-n "%%~W\bin\php\php*" 2^>nul') do (
      if not defined PHP if exist "%%~W\bin\php\%%D\php.exe" set "PHP=%%~fW\bin\php\%%D\php.exe"
    )
  )
)
exit /b 0
