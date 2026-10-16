@echo off
REM Mise a jour : a lancer APRES avoir remplace les fichiers du projet par la nouvelle version
REM (en conservant .env, ai-service\.env, storage\ et ai-service\models\).
call "%~dp0_env.bat" || (pause & exit /b 1)
cd /d "%ROOT%"
echo [1/4] Sauvegarde avant mise a jour
"%SG_PHP%" scripts\backup.php || goto :fail
echo [2/4] Migrations de la base
"%SG_PHP%" scripts\install_db.php || goto :fail
echo [3/4] Dependances Python
call "%~dp0stop.bat"
"ai-service\.venv\Scripts\python.exe" -m pip install -r "ai-service\requirements.txt" || goto :fail
echo [4/4] Redemarrage du service IA
call "%~dp0start.bat"
echo Mise a jour terminee. Si l'extracteur d'empreintes a change, relancez la preparation IA (Apprentissage IA).
pause
exit /b 0
:fail
echo [ECHEC] Mise a jour interrompue. Restaurez si besoin avec scripts\restore.bat
pause
