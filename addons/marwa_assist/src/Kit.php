<?php
declare(strict_types=1);

namespace Addons\MarwaAssist;

/**
 * Scripts Windows des kits (texte ASCII, fins de ligne CRLF) :
 * - poste de boutique : installe RustDesk, le règle sur le serveur HTN (clé imposée), acceptation
 *   par clic du caissier, clavier/souris coupés par défaut, fenêtre de connexion jamais masquée,
 *   puis transmet son identifiant à POS Marwa ;
 * - technicien : RustDesk sans installation, réglé sur le même serveur.
 */
final class Kit
{
    public static function script(string $kind, string $host, string $key, string $baseUrl, string $token, bool $unattended): string
    {
        $lines = $kind === 'boutique' ? self::boutique($host, $key, $baseUrl, $token, $unattended) : self::technicien($host, $key);
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function boutique(string $host, string $key, string $baseUrl, string $token, bool $unattended): array
    {
        $opts = [
            ['custom-rendezvous-server', $host], ['relay-server', $host], ['key', $key],
            // Consentement : chaque connexion doit être acceptée par un clic sur ce poste
            ['approve-mode', $unattended ? 'password' : 'click'],
            ['verification-method', $unattended ? 'use-permanent-password' : 'use-temporary-password'],
            // Observation par défaut : clavier, souris, presse-papiers, fichiers coupés (à cocher pendant la session)
            ['enable-keyboard', 'N'], ['enable-clipboard', 'N'], ['enable-file-transfer', 'N'], ['enable-audio', 'N'],
            ['enable-tunnel', 'N'], ['enable-remote-restart', 'N'],
            // Fenêtre de connexion toujours visible, pas de découverte sur le réseau local
            ['allow-hide-cm', 'N'], ['enable-lan-discovery', 'N'],
        ];
        $l = [
            '@echo off',
            'REM POS Marwa - MARWA REMOTE ASSIST : installation de l\'assistance a distance sur ce poste',
            'REM RustDesk (licence AGPL-3.0, non modifie) regle sur le serveur High Tech Niger ' . $host,
            'setlocal EnableExtensions',
            'title POS Marwa - assistance a distance',
            'net session >nul 2>&1',
            'if errorlevel 1 (',
            '  echo Droits administrateur necessaires : acceptez la fenetre de Windows.',
            '  powershell -NoProfile -Command "Start-Process -FilePath \'%~f0\' -Verb RunAs"',
            '  exit /b',
            ')',
            'set "RD=%ProgramFiles%\\RustDesk\\rustdesk.exe"',
            'if not exist "%RD%" (',
            '  echo Installation de RustDesk...',
            '  "%~dp0rustdesk.exe" --silent-install',
            '  for /l %%i in (1,1,60) do if not exist "%RD%" timeout /t 2 /nobreak >nul',
            ')',
            'if not exist "%RD%" (',
            '  echo ECHEC : RustDesk ne s\'est pas installe. Verifiez l\'antivirus puis relancez ce fichier.',
            '  pause',
            '  exit /b 1',
            ')',
            'timeout /t 5 /nobreak >nul',
            'echo Reglage de la securite...',
        ];
        foreach ($opts as [$k, $v]) {
            $l[] = '"%RD%" --option ' . $k . ' ' . $v;
        }
        if ($unattended) {
            $l[] = 'echo ACCES SANS SURVEILLANCE active par le responsable : choisissez un mot de passe solide (12 caracteres ou plus, lettres et chiffres seulement).';
            $l[] = 'set /p "PW=Mot de passe permanent : "';
            $l[] = '"%RD%" --password "%PW%"';
            $l[] = 'set "PW="';
        }
        $url = $baseUrl . '/assist/enregistrer?jeton=' . $token;
        $l = array_merge($l, [
            'set "RID="',
            'for /f "delims=" %%i in (\'"%RD%" --get-id ^| more\') do set "RID=%%i"',
            'echo Identifiant de ce poste : %RID%',
            'powershell -NoProfile -Command "try { (Invoke-WebRequest -UseBasicParsing -TimeoutSec 15 -Uri (\'' . $url . '&id=\' + $env:RID + \'&poste=\' + [uri]::EscapeDataString($env:COMPUTERNAME))).Content } catch { \'Envoi impossible : saisissez l identifiant dans POS Marwa (Assistance a distance, Postes).\' }"',
            'echo Installation de l\'agent automatique (tache planifiee, compte SYSTEM)...',
            'if not exist "%ProgramData%\\POSMarwa" mkdir "%ProgramData%\\POSMarwa"',
            'copy /y "%~dp0agent-assistance.ps1" "%ProgramData%\\POSMarwa\\agent-assistance.ps1" >nul',
            // Dossier réservé à SYSTEM et aux administrateurs : le jeton du poste n'est pas lisible par un simple utilisateur
            'icacls "%ProgramData%\\POSMarwa" /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" >nul',
            'schtasks /create /tn "POS Marwa assistance" /sc onstart /ru SYSTEM /rl HIGHEST /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \\"%ProgramData%\\POSMarwa\\agent-assistance.ps1\\"" /f >nul',
            'if errorlevel 1 (',
            '  echo ATTENTION : agent non installe. L\'assistance reste possible, avec un clic du caissier pour accepter.',
            ') else (',
            '  schtasks /run /tn "POS Marwa assistance" >nul',
            '  echo Agent installe : tout se regle automatiquement depuis POS Marwa.',
            ')',
            'echo.',
            'echo Termine. Pendant une assistance, une fenetre RustDesk indique que le technicien est connecte.',
            'echo Le clavier du technicien ne s\'active qu\'apres l\'accord d\'un responsable dans POS Marwa.',
            'pause',
            'endlocal',
        ]);
        return $l;
    }

    private static function technicien(string $host, string $key): array
    {
        return [
            '@echo off',
            'REM POS Marwa - MARWA REMOTE ASSIST : poste du technicien High Tech Niger (sans installation)',
            'setlocal EnableExtensions',
            'title POS Marwa - technicien',
            'set "CFG=%APPDATA%\\RustDesk\\config"',
            'if not exist "%CFG%" mkdir "%CFG%"',
            'if exist "%CFG%\\RustDesk2.toml" copy /y "%CFG%\\RustDesk2.toml" "%CFG%\\RustDesk2.toml.avant-marwa" >nul',
            '(',
            "  echo rendezvous_server = '" . $host . ":21116'",
            '  echo nat_type = 1',
            '  echo serial = 0',
            '  echo.',
            '  echo [options]',
            "  echo custom-rendezvous-server = '" . $host . "'",
            "  echo relay-server = '" . $host . "'",
            "  echo key = '" . $key . "'",
            ') > "%CFG%\\RustDesk2.toml"',
            'start "" "%~dp0rustdesk.exe"',
            'endlocal',
        ];
    }

    /**
     * Agent du poste (PowerShell 5.1, livré avec Windows 10/11) : interroge POS Marwa toutes les
     * 3 secondes et applique l'état à RustDesk (code d'accès, clavier). Fin d'assistance ou fin du
     * contrôle : redémarre le service RustDesk, ce qui coupe immédiatement la session ouverte.
     * Sans réponse de POS Marwa pendant 2 minutes : retour à « aucune assistance » (sécurité).
     */
    public static function agent(string $baseUrl, string $token): string
    {
        $l = [
            '# POS Marwa - MARWA REMOTE ASSIST : agent automatique de ce poste (version 1.1)',
            '# Applique a RustDesk l\'etat decide dans POS Marwa : aucune assistance / observation / controle.',
            '# Installe par le kit (tache planifiee "POS Marwa assistance", compte SYSTEM, au demarrage).',
            '$ErrorActionPreference = \'Stop\'',
            '$Version = \'1.1\'',
            '$Base = \'' . str_replace("'", '', $baseUrl) . '\'',
            '$Jeton = \'' . preg_replace('/[^a-f0-9]/', '', $token) . '\'',
            '$Dir = Join-Path $env:ProgramData \'POSMarwa\'',
            '$Log = Join-Path $Dir \'agent-assistance.log\'',
            '$RD = Join-Path $env:ProgramFiles \'RustDesk\\rustdesk.exe\'',
            'function Write-Log([string]$m) {',
            '  try {',
            '    if ((Test-Path $Log) -and (Get-Item $Log).Length -gt 1MB) { Move-Item $Log ($Log + \'.1\') -Force }',
            '    Add-Content -Path $Log -Value ((Get-Date -Format \'yyyy-MM-dd HH:mm:ss\') + \' \' + $m)',
            '  } catch { }',
            '}',
            'function Set-RD([string[]]$a) {',
            '  $p = Start-Process -FilePath $RD -ArgumentList $a -PassThru -WindowStyle Hidden',
            '  if (-not $p.WaitForExit(15000)) { try { $p.Kill() } catch { } }',
            '}',
            'function Set-Aucune([bool]$unattended) {',
            '  Set-RD @(\'--option\', \'enable-keyboard\', \'N\')',
            '  if (-not $unattended) {',
            '    Set-RD @(\'--option\', \'approve-mode\', \'click\')',
            '    Set-RD @(\'--option\', \'verification-method\', \'use-temporary-password\')',
            '    Set-RD @(\'--password\', [guid]::NewGuid().ToString(\'N\'))',
            '  }',
            '}',
            'function Stop-Sessions {',
            '  Restart-Service -Name \'RustDesk\' -Force -ErrorAction SilentlyContinue',
            '  Write-Log \'Sessions RustDesk coupees (service redemarre)\'',
            '}',
            '$last = \'\'; $prevState = \'\'; $prevKb = $false; $failSince = $null',
            'Write-Log (\'Agent demarre, version \' + $Version)',
            'while ($true) {',
            '  try {',
            '    if (-not (Test-Path $RD)) { throw \'RustDesk absent de ce poste\' }',
            '    $uri = $Base + \'/assist/agent?jeton=\' + $Jeton + \'&version=\' + $Version + \'&applique=\' + [uri]::EscapeDataString($last)',
            '    $r = Invoke-RestMethod -UseBasicParsing -TimeoutSec 10 -Uri $uri',
            '    $failSince = $null',
            '    $state = [string]$r.state',
            '    $kb = [bool]$r.keyboard',
            '    $key = $state + \'|\' + $(if ($kb) { \'clavier\' } else { \'vue\' }) + \'|\' + [string]$r.intervention',
            '    if ($key -ne $last) {',
            '      if ($state -eq \'aucune\') {',
            '        Set-Aucune ([bool]$r.unattended)',
            '      } else {',
            '        Set-RD @(\'--option\', \'enable-keyboard\', $(if ($kb) { \'Y\' } else { \'N\' }))',
            '        Set-RD @(\'--password\', [string]$r.code)',
            '        Set-RD @(\'--option\', \'verification-method\', \'use-permanent-password\')',
            '        Set-RD @(\'--option\', \'approve-mode\', \'password\')',
            '      }',
            '      # Fin de l\'assistance ou fin du controle : coupure immediate de la session ouverte',
            '      if (($prevState -eq \'observation\' -or $prevState -eq \'controle\') -and ($state -eq \'aucune\' -or ($prevKb -and -not $kb))) { Stop-Sessions }',
            '      Write-Log (\'Etat applique : \' + $key)',
            '      $last = $key; $prevState = $state; $prevKb = $kb',
            '    }',
            '  } catch {',
            '    $code = 0',
            '    try { $code = [int]$_.Exception.Response.StatusCode } catch { }',
            '    if ($code -eq 403) {',
            '      if ($prevState -ne \'retire\') { Set-Aucune $false; if ($prevState -eq \'observation\' -or $prevState -eq \'controle\') { Stop-Sessions }; Write-Log \'Poste retire dans POS Marwa : acces par code desactive\'; $prevState = \'retire\'; $last = \'\' }',
            '    } elseif (-not ($_.Exception -is [System.Net.WebException] -or $_.Exception.GetType().Name -like \'*Http*\')) {',
            '      Write-Log (\'Erreur : \' + $_.Exception.Message)',
            '    } else {',
            '      if (-not $failSince) { $failSince = Get-Date; Write-Log (\'POS Marwa injoignable : \' + $_.Exception.Message) }',
            '      if ((($prevState -eq \'observation\') -or ($prevState -eq \'controle\')) -and ((Get-Date) - $failSince).TotalSeconds -gt 120) {',
            '        Set-Aucune $false; Stop-Sessions; Write-Log \'Sans nouvelles de POS Marwa depuis 2 minutes : assistance coupee par securite\'; $prevState = \'aucune\'; $last = \'\'',
            '      }',
            '    }',
            '  }',
            '  Start-Sleep -Seconds 3',
            '}',
        ];
        return implode("\r\n", $l) . "\r\n";
    }

    public static function readme(string $kind, string $label, string $host): string
    {
        $t = $kind === 'boutique'
            ? "ASSISTANCE A DISTANCE - poste « $label »\r\n\r\n"
                . "1. Decompressez ce dossier sur le poste a equiper.\r\n"
                . "2. Double-cliquez installer-assistance.bat et acceptez la fenetre Windows (droits administrateur).\r\n"
                . "3. Le script installe RustDesk, le regle sur le serveur High Tech Niger ($host) et transmet\r\n"
                . "   l'identifiant du poste a POS Marwa. Ce kit expire apres quelques jours (voir POS Marwa).\r\n\r\n"
                . "Securite : chaque connexion doit etre acceptee par un clic sur ce poste ; le technicien voit\r\n"
                . "l'ecran mais ne peut pas utiliser le clavier ni la souris tant que la case Clavier n'est pas\r\n"
                . "cochee dans la fenetre RustDesk. La caisse continue de fonctionner pendant l'assistance.\r\n"
            : "POSTE DU TECHNICIEN HIGH TECH NIGER\r\n\r\n"
                . "Double-cliquez demarrer-technicien.bat : RustDesk demarre, regle sur le serveur $host.\r\n"
                . "Saisissez l'identifiant du poste de la boutique (affiche dans POS Marwa > Assistance a distance).\r\n"
                . "Le caissier doit accepter la connexion ; le controle clavier/souris doit etre autorise sur place.\r\n";
        return $t . "\r\nRustDesk : logiciel libre (AGPL-3.0), https://github.com/rustdesk/rustdesk - licence jointe.\r\n";
    }
}
