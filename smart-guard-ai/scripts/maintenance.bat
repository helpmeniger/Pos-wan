@echo off
REM Tache planifiee : retention des medias, espace disque, sante du service IA, rapport de la veille.
call "%~dp0_env.bat" || exit /b 1
cd /d "%ROOT%"
"%SG_PHP%" scripts\maintenance.php %* >> storage\logs\maintenance.log 2>&1
