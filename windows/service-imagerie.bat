@echo off
REM POS Marwa - recepteur d images de la clinique (lance automatiquement, fenetre reduite).
REM S arrete seul si un recepteur tourne deja (port occupe) ou si le module Clinique est inactif.
title POS Marwa - recepteur d images
call "%~dp0trouver-php.bat"
if not defined PHP exit /b 1
:boucle
"%PHP%" "%~dp0..\bin\imagerie.php" serveur
if errorlevel 1 exit /b 1
timeout /t 10 /nobreak >nul
goto boucle
