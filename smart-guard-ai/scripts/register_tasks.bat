@echo off
REM A executer en tant qu'administrateur : cree les taches planifiees Windows.
set "S=%~dp0"
schtasks /create /f /tn "SMART GUARD AI\Maintenance" /sc hourly /tr "\"%S%maintenance.bat\"" || goto :err
schtasks /create /f /tn "SMART GUARD AI\Sauvegarde" /sc daily /st 23:30 /tr "\"%S%backup.bat\" auto" || goto :err
schtasks /create /f /tn "SMART GUARD AI\Service IA" /sc onlogon /tr "\"%S%start.bat\"" || goto :err
echo Taches planifiees creees (maintenance horaire, sauvegarde quotidienne a 23h30, service IA a l'ouverture de session).
pause
exit /b 0
:err
echo [ECHEC] Lancez ce script en tant qu'administrateur (clic droit ^> Executer en tant qu'administrateur).
pause
