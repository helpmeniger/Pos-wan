@echo off
REM POS Marwa - service vocal Piper (garde la voix en memoire : annonces plus rapides)
REM Lance automatiquement par demarrer-pos-marwa.bat. Met d'abord en place la voix livree.
title POS Marwa - service vocal
call "%~dp0trouver-php.bat"
if not defined PHP exit /b 1
"%PHP%" "%~dp0..\bin\voix.php" deployer
"%PHP%" "%~dp0..\bin\voix.php" service
