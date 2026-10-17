@echo off
REM SMART GUARD AI - Recherche d'un Python 3.10+ 64 bits REELLEMENT executable.
REM Ignore le faux "python.exe" du Microsoft Store (WindowsApps). Resultat : variable SG_PY.
set "SG_PY="
set "SG_PYTEST=import sys,struct; sys.exit(0 if sys.version_info>=(3,10) and struct.calcsize('P')==8 else 1)"
for %%V in (3.12 3.11 3.13 3.10 3) do (
  if not defined SG_PY (
    py -%%V -c "%SG_PYTEST%" >nul 2>nul && set "SG_PY=py -%%V"
  )
)
if defined SG_PY goto :found
for /d %%D in ("%LOCALAPPDATA%\Programs\Python\Python3*" "%ProgramFiles%\Python3*" "C:\Python3*") do (
  if not defined SG_PY if exist "%%~D\python.exe" (
    "%%~D\python.exe" -c "%SG_PYTEST%" >nul 2>nul && set "SG_PY="%%~D\python.exe""
  )
)
if defined SG_PY goto :found
for /f "delims=" %%P in ('where python 2^>nul') do (
  if not defined SG_PY (
    echo %%P | find /i "WindowsApps" >nul || (
      "%%P" -c "%SG_PYTEST%" >nul 2>nul && set "SG_PY="%%P""
    )
  )
)
if defined SG_PY goto :found
echo [ERREUR] Aucun Python 3.10+ 64 bits utilisable n'a ete trouve.
echo   - Installez Python 3.12 (64 bits) depuis https://www.python.org/downloads/windows/
echo     en cochant "Add python.exe to PATH" et "py launcher".
echo   - Si "python" ouvre le Microsoft Store : Parametres ^> Applications ^> Parametres avances
echo     des applications ^> Alias d'execution d'application : desactivez python.exe et python3.exe.
exit /b 1
:found
echo Python utilise : %SG_PY%
exit /b 0
