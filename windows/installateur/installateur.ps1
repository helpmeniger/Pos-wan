#Requires -Version 5.1
<#
  Installateur POS Marwa pour Windows 10 / 11 (WampServer).
  - Affiche la configuration de l'ordinateur et contrôle les prérequis.
  - Détecte les programmes qui occupent les ports de WampServer (80, 3306, 3307, 3308) et les libère.
  - Installe depuis le paquet fourni (zip ou dossier) : Visual C++ 2008 à 2022, WampServer,
    Google Chrome, puis POS Marwa (base de données, utilisateur MySQL dédié, fichier .env).
  - Démarre le serveur automatiquement avec Windows (services en démarrage automatique,
    tâche planifiée pour l'icône Wamp), crée le raccourci, ouvre les règles du pare-feu utiles.
  Interface : page HTML locale (http://127.0.0.1:47811, accessible uniquement depuis ce PC).
  Ne désactive jamais l'UAC, l'antivirus ni le pare-feu de Windows.
#>
param([switch]$SansFenetre)
$ErrorActionPreference = 'Stop'
$Ici = Split-Path -Parent $MyInvocation.MyCommand.Path

# ------------------------------------------------------------------ droits administrateur
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-STA', '-File', "`"$PSCommandPath`"")
    exit
}

# ------------------------------------------------------------------ bibliothèque (partagée avec la tâche d'installation)
$Lib = @'
$Racine = 'C:\POS-Marwa-Installation'
New-Item -ItemType Directory -Force -Path $Racine | Out-Null
$Journal = Join-Path $Racine 'installation.log'

function Log([string]$m) {
    $l = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $m
    Add-Content -Path $Journal -Value $l -Encoding UTF8
    if ($script:Etat) { [void]$script:Etat.log.Add($l) }
}

function Get-InfoSysteme {
    $os = Get-CimInstance Win32_OperatingSystem
    $cs = Get-CimInstance Win32_ComputerSystem
    $cpu = @(Get-CimInstance Win32_Processor)[0]
    $disk = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='C:'"
    $gpu = @(Get-CimInstance Win32_VideoController | Select-Object -ExpandProperty Name) -join ', '
    $ips = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | Select-Object -ExpandProperty IPAddress)
    $vc = @(Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*' -ErrorAction SilentlyContinue |
        Where-Object { $_.DisplayName -like 'Microsoft Visual C++*Redistributable*' } | Select-Object -ExpandProperty DisplayName | Sort-Object -Unique)
    $chrome = Find-Chrome
    $wamp = Find-Wamp
    $ramGo = [math]::Round($cs.TotalPhysicalMemory / 1GB, 1)
    $libreGo = [math]::Round($disk.FreeSpace / 1GB, 1)
    $x64 = [Environment]::Is64BitOperatingSystem
    $build = [int]$os.BuildNumber
    $fw = @(Get-NetFirewallProfile -ErrorAction SilentlyContinue | ForEach-Object { $_.Name + ' : ' + $(if ($_.Enabled) { 'actif' } else { 'désactivé' }) }) -join ', '
    $verdicts = @(
        @{ ok = $x64; t = 'Windows 64 bits (obligatoire pour WampServer 64 bits)' },
        @{ ok = ($build -ge 10240); t = 'Windows 10 ou 11' },
        @{ ok = ($ramGo -ge 3.5); t = "Mémoire : $ramGo Go (4 Go conseillés)" },
        @{ ok = ($libreGo -ge 5); t = "Espace libre sur C: : $libreGo Go (5 Go minimum)" }
    )
    return [ordered]@{
        ordinateur = $env:COMPUTERNAME; utilisateur = "$env:USERDOMAIN\$env:USERNAME"
        systeme = "$($os.Caption) ($($os.OSArchitecture)) – version $($os.Version)"; demarre = $os.LastBootUpTime.ToString('dd/MM/yyyy HH:mm')
        fabricant = "$($cs.Manufacturer) $($cs.Model)"; processeur = "$($cpu.Name.Trim()) – $($cpu.NumberOfCores) cœurs / $($cpu.NumberOfLogicalProcessors) threads"
        memoire = "$ramGo Go"; disque = "C: $libreGo Go libres sur $([math]::Round($disk.Size / 1GB, 0)) Go"; graphique = $gpu
        reseau = $(if ($ips) { $ips -join ', ' } else { 'aucune adresse réseau' }); parefeu = $fw; powershell = $PSVersionTable.PSVersion.ToString()
        vcredist = $vc; chrome = $chrome; wamp = $wamp; admin = $true; verdicts = $verdicts
    }
}

function Find-Chrome {
    foreach ($k in 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe', 'HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe') {
        $p = (Get-ItemProperty $k -ErrorAction SilentlyContinue).'(default)'
        if ($p -and (Test-Path $p)) { return $p }
    }
    foreach ($p in "$env:ProgramFiles\Google\Chrome\Application\chrome.exe", "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe", "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe") {
        if ($p -and (Test-Path $p)) { return $p }
    }
    return $null
}

function Find-Wamp {
    foreach ($k in Get-ChildItem 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall' -ErrorAction SilentlyContinue) {
        $p = Get-ItemProperty $k.PSPath -ErrorAction SilentlyContinue
        if ($p.DisplayName -like 'Wampserver*' -and $p.InstallLocation -and (Test-Path (Join-Path $p.InstallLocation 'wampmanager.exe'))) { return $p.InstallLocation.TrimEnd('\') }
    }
    foreach ($d in 'C:\wamp64', 'C:\wamp', 'D:\wamp64', 'D:\wamp') {
        if (Test-Path (Join-Path $d 'wampmanager.exe')) { return $d }
    }
    return $null
}

# Services Windows qui utilisent le port 80 à travers le pilote http.sys (processus « System », PID 4)
$ServicesHttp = @('W3SVC', 'WAS', 'MsDepSvc', 'ReportServer', 'SSRS', 'PeerDistSvc', 'WMSVC', 'iisadmin')

function Get-InfoPort([int]$port) {
    $c = @(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
    if (-not $c) { return [ordered]@{ port = $port; libre = $true } }
    $owner = $c[0].OwningProcess
    $p = Get-Process -Id $owner -ErrorAction SilentlyContinue
    $svc = @(Get-CimInstance Win32_Service -Filter "ProcessId=$owner" -ErrorAction SilentlyContinue | ForEach-Object { $_.Name + ' (' + $_.DisplayName + ')' })
    if ($owner -eq 4) {
        $svc = @($ServicesHttp | ForEach-Object { Get-Service -Name $_ -ErrorAction SilentlyContinue } | Where-Object { $_.Status -eq 'Running' } | ForEach-Object { $_.Name + ' (' + $_.DisplayName + ')' })
    }
    $path = $null; try { $path = $p.Path } catch {}
    $wamp = ($path -and ($path -match '\\wamp(64)?\\')) -or ($p.ProcessName -in @('httpd', 'mysqld', 'mariadbd') -and $path -match 'wamp')
    return [ordered]@{ port = $port; libre = $false; pid = $owner; processus = $(if ($owner -eq 4) { 'Système Windows (http.sys)' } else { $p.ProcessName }); chemin = $path; services = $svc; wamp = [bool]$wamp }
}

function Liberer-Port([int]$port) {
    $i = Get-InfoPort $port
    if ($i.libre) { return "Port $port déjà libre." }
    if ($i.wamp) { return "Port $port utilisé par WampServer lui-même : rien à faire." }
    $faits = @()
    if ($i.pid -eq 4) {
        foreach ($n in $ServicesHttp) {
            $s = Get-Service -Name $n -ErrorAction SilentlyContinue
            if ($s -and $s.Status -eq 'Running') {
                Stop-Service -Name $n -Force -ErrorAction SilentlyContinue
                Set-Service -Name $n -StartupType Disabled -ErrorAction SilentlyContinue
                $faits += "service $n arrêté et désactivé"
            }
        }
    } else {
        foreach ($s in @(Get-CimInstance Win32_Service -Filter "ProcessId=$($i.pid)" -ErrorAction SilentlyContinue)) {
            Stop-Service -Name $s.Name -Force -ErrorAction SilentlyContinue
            Set-Service -Name $s.Name -StartupType Disabled -ErrorAction SilentlyContinue
            $faits += "service $($s.Name) arrêté et désactivé"
        }
        if (Get-Process -Id $i.pid -ErrorAction SilentlyContinue) {
            Stop-Process -Id $i.pid -Force -ErrorAction SilentlyContinue
            $faits += "programme $($i.processus) (PID $($i.pid)) arrêté"
        }
    }
    Start-Sleep -Seconds 2
    $apres = Get-InfoPort $port
    $msg = "Port $port : " + ($faits -join ', ') + $(if ($apres.libre) { ' – port libéré.' } else { " – toujours occupé par $($apres.processus) (PID $($apres.pid))." })
    Log $msg
    return $msg
}

function New-Secret([int]$n = 24) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    $b = New-Object byte[] $n; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
    return -join ($b | ForEach-Object { $chars[$_ % $chars.Length] })
}

function New-Hex([int]$octets = 32) {
    $b = New-Object byte[] $octets; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
    return -join ($b | ForEach-Object { $_.ToString('x2') })
}

# Contenu du paquet : chaque programme est reconnu par son nom de fichier
function Analyser-Paquet([string]$chemin) {
    if (-not (Test-Path $chemin)) { throw "Introuvable : $chemin" }
    $dossier = $chemin
    if ((Get-Item $chemin).PSIsContainer -eq $false) {
        $dossier = Join-Path $Racine ('paquet\' + [IO.Path]::GetFileNameWithoutExtension($chemin))
        if (-not (Test-Path $dossier)) {
            Log "Extraction du paquet $chemin"
            Expand-Archive -Path $chemin -DestinationPath $dossier -Force
        }
    }
    $items = @()
    foreach ($f in Get-ChildItem -Path $dossier -Recurse -File) {
        $n = $f.Name.ToLower()
        $x64 = $n -match 'x64|amd64'
        if ($n -match '^vc_?redist|vcredist') {
            $an = 2015; if ($n -match '(2005|2008|2010|2012|2013|2015|2017|2019|2022)') { $an = [int]$Matches[1] }
            $items += [ordered]@{ type = 'vcredist'; fichier = $f.FullName; nom = $f.Name; annee = $an; x64 = $x64; ordre = $an * 10 + [int]$x64 }
        } elseif ($n -like 'wampserver*.exe') {
            $items += [ordered]@{ type = 'wamp'; fichier = $f.FullName; nom = $f.Name; ordre = 50000 }
        } elseif ($n -like '*chrome*.exe' -or $n -like 'googlechrome*.msi') {
            $items += [ordered]@{ type = 'chrome'; fichier = $f.FullName; nom = $f.Name; ordre = 60000 }
        } elseif ($n -match '^pos[-_ ]?(marwa|wan).*\.zip$') {
            $items += [ordered]@{ type = 'posmarwa'; fichier = $f.FullName; nom = $f.Name; ordre = 70000 }
        }
    }
    # POS Marwa déjà décompressé dans le paquet (dossier contenant public\index.php et app\)
    if (-not ($items | Where-Object { $_.type -eq 'posmarwa' })) {
        $idx = Get-ChildItem -Path $dossier -Recurse -Filter 'index.php' -ErrorAction SilentlyContinue | Where-Object { $_.Directory.Name -eq 'public' -and (Test-Path (Join-Path $_.Directory.Parent.FullName 'app\bootstrap.php')) } | Select-Object -First 1
        if ($idx) { $items += [ordered]@{ type = 'posmarwa'; fichier = $idx.Directory.Parent.FullName; nom = 'POS Marwa (dossier)'; ordre = 70000 } }
    }
    $items = @($items | Sort-Object { $_.ordre })
    return [ordered]@{ dossier = $dossier; elements = $items }
}

function Arguments-Silencieux($e) {
    switch ($e.type) {
        'vcredist' {
            if ($e.annee -le 2008) { return '/q' }
            if ($e.annee -eq 2010) { return '/q /norestart' }
            return '/install /quiet /norestart'
        }
        'wamp' { return '/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP- /DIR="C:\wamp64"' }
        'chrome' {
            if ($e.fichier -like '*.msi') { return '/qn /norestart' }
            # chrome_installer(_uncompressed).exe = installateur hors ligne « mini_installer » ; ChromeSetup / ChromeStandaloneSetup = /silent /install
            if ($e.nom -match 'installer') { return '--system-level --do-not-launch-chrome' }
            return '/silent /install'
        }
    }
}

function Lancer-Installateur($e) {
    $arguments = Arguments-Silencieux $e
    Log "Installation : $($e.nom) $arguments"
    if ($e.fichier -like '*.msi') {
        $p = Start-Process -FilePath 'msiexec.exe' -ArgumentList "/i `"$($e.fichier)`" $arguments" -Wait -PassThru
    } else {
        $p = Start-Process -FilePath $e.fichier -ArgumentList $arguments -Wait -PassThru
    }
    $c = $p.ExitCode
    # 0 réussi · 3010/1641 redémarrage demandé · 1638/5100 version plus récente déjà présente
    $ok = $c -in @(0, 3010, 1641, 1638, 5100, 1603) -or $null -eq $c
    $txt = switch ($c) { 0 { 'installé' } 3010 { 'installé (redémarrage conseillé)' } 1641 { 'installé (redémarrage lancé)' } 1638 { 'déjà installé (version plus récente présente)' } 5100 { 'déjà installé (version plus récente présente)' } 1603 { 'déjà présent ou refusé par Windows (code 1603)' } default { "code $c" } }
    Log "  → $($e.nom) : $txt"
    return @{ ok = $ok; texte = $txt; redemarrage = ($c -in @(3010, 1641)) }
}

function Services-Wamp { return @(Get-Service -Name 'wampapache*', 'wampmysqld*', 'wampmariadb*' -ErrorAction SilentlyContinue) }

function Trouver-MySql([string]$wamp) {
    $cands = @()
    foreach ($kind in 'mariadb', 'mysql') {
        foreach ($d in Get-ChildItem (Join-Path $wamp "bin\$kind") -Directory -ErrorAction SilentlyContinue) {
            $exe = Join-Path $d.FullName 'bin\mysql.exe'
            if (-not (Test-Path $exe)) { $exe = Join-Path $d.FullName 'bin\mariadb.exe' }
            if (Test-Path $exe) {
                $port = if ($kind -eq 'mariadb') { 3307 } else { 3306 }
                $ini = Join-Path $d.FullName 'my.ini'
                if (Test-Path $ini) {
                    $m = Select-String -Path $ini -Pattern '^\s*port\s*=\s*(\d+)' | Select-Object -First 1
                    if ($m) { $port = [int]$m.Matches[0].Groups[1].Value }
                }
                $svc = Get-Service -Name ("wamp$kind*") -ErrorAction SilentlyContinue | Select-Object -First 1
                if ($kind -eq 'mysql') { $svc = Get-Service -Name 'wampmysqld*' -ErrorAction SilentlyContinue | Select-Object -First 1 }
                $cands += [ordered]@{ type = $kind; exe = $exe; port = $port; actif = ($svc -and $svc.Status -eq 'Running'); version = $d.Name }
            }
        }
    }
    # Serveur en marche d'abord, puis MariaDB (défaut de WampServer 3.3), version la plus récente
    return @($cands | Sort-Object @{ e = { -not $_.actif } }, @{ e = { $_.type -ne 'mariadb' } }, @{ e = { $_.version }; Descending = $true }) | Select-Object -First 1
}

function Sql($db, [string]$motDePasse, [string]$requete) {
    $env:MYSQL_PWD = $motDePasse
    try {
        $out = & $db.exe -u root -h 127.0.0.1 -P $db.port --batch --skip-column-names -e $requete 2>&1
        if ($LASTEXITCODE -ne 0) { throw ($out | Out-String).Trim() }
        return $out
    } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }
}

function Proteger-Fichier([string]$f) {
    & icacls.exe $f /inheritance:r /grant:r '*S-1-5-32-544:F' '*S-1-5-18:F' | Out-Null
}
'@
. ([scriptblock]::Create($Lib))

# ------------------------------------------------------------------ état partagé avec la tâche d'installation
$Etat = [hashtable]::Synchronized(@{ enCours = $false; fini = $false; etapes = (New-Object System.Collections.ArrayList); log = (New-Object System.Collections.ArrayList); resultat = $null })
$script:Etat = $Etat

$Installation = {
    param($Etat, $Lib, $Paquet, $Options)
    $script:Etat = $Etat
    . ([scriptblock]::Create($Lib))
    function Etape([string]$id, [string]$titre, [string]$statut, [string]$detail = '') {
        $e = $null; foreach ($x in $Etat.etapes) { if ($x.id -eq $id) { $e = $x } }
        if (-not $e) { $e = [hashtable]::Synchronized(@{ id = $id; titre = $titre }); [void]$Etat.etapes.Add($e) }
        $e.statut = $statut; $e.detail = $detail
        Log "[$statut] $titre $detail"
    }
    $res = [ordered]@{ redemarrage = $false; url = $null; identifiants = $null; erreurs = @() }
    try {
        $p = Analyser-Paquet $Paquet
        $wampDir = Find-Wamp

        # 1. Ports
        if ($Options.liberer) {
            Etape 'ports' 'Libérer les ports de WampServer' 'encours'
            $m = @(); foreach ($port in 80, 3306, 3307, 3308) { $i = Get-InfoPort $port; if (-not $i.libre -and -not $i.wamp) { $m += Liberer-Port $port } }
            Etape 'ports' 'Libérer les ports de WampServer' 'ok' $(if ($m) { $m -join ' ' } else { 'ports 80, 3306, 3307, 3308 libres' })
        }

        # 2. Visual C++ (indispensables à Apache, PHP et MySQL)
        $vcs = @($p.elements | Where-Object { $_.type -eq 'vcredist' -and ($_.x64 -eq $false -or [Environment]::Is64BitOperatingSystem) })
        $n = 0
        foreach ($v in $vcs) {
            $n++
            Etape 'vc' 'Composants Visual C++ 2008 à 2022' 'encours' "$n / $($vcs.Count) : $($v.nom)"
            $r = Lancer-Installateur $v
            if ($r.redemarrage) { $res.redemarrage = $true }
        }
        Etape 'vc' 'Composants Visual C++ 2008 à 2022' $(if ($vcs) { 'ok' } else { 'absent' }) $(if ($vcs) { "$($vcs.Count) paquet(s) traité(s)" } else { 'aucun vcredist dans le paquet' })

        # 3. WampServer
        $w = $p.elements | Where-Object { $_.type -eq 'wamp' } | Select-Object -First 1
        if ($wampDir) {
            Etape 'wamp' 'WampServer' 'ok' "déjà installé dans $wampDir"
        } elseif ($w) {
            Etape 'wamp' 'WampServer' 'encours' "$($w.nom) (plusieurs minutes)"
            $r = Lancer-Installateur $w
            $wampDir = Find-Wamp
            if (-not $wampDir) { throw "WampServer ne s'est pas installé ($($r.texte)). Lancez $($w.nom) à la main puis relancez l'installateur." }
            Etape 'wamp' 'WampServer' 'ok' "installé dans $wampDir"
        } else { throw 'WampServer absent du paquet et non installé sur ce PC.' }

        # 4. Google Chrome
        $c = $p.elements | Where-Object { $_.type -eq 'chrome' } | Select-Object -First 1
        if (Find-Chrome) { Etape 'chrome' 'Google Chrome' 'ok' 'déjà installé' }
        elseif ($c -and $Options.chrome) { Etape 'chrome' 'Google Chrome' 'encours' $c.nom; $r = Lancer-Installateur $c; Etape 'chrome' 'Google Chrome' $(if (Find-Chrome) { 'ok' } else { 'erreur' }) $r.texte }
        else { Etape 'chrome' 'Google Chrome' 'absent' 'non fourni : Microsoft Edge sera utilisé' }

        # 5. Démarrage de WampServer, services en démarrage automatique
        Etape 'serveur' 'Serveur Apache et MySQL / MariaDB' 'encours' 'démarrage de WampServer'
        $httpd = Get-ChildItem (Join-Path $wampDir 'bin\apache') -Directory -ErrorAction SilentlyContinue | ForEach-Object { Join-Path $_.FullName 'conf\httpd.conf' } | Where-Object { Test-Path $_ }
        foreach ($conf in $httpd) {
            $t = Get-Content $conf -Raw
            if ($t -match '(?m)^\s*#\s*LoadModule rewrite_module') { ($t -replace '(?m)^\s*#\s*(LoadModule rewrite_module)', '$1') | Set-Content -Path $conf -Encoding ASCII; Log "mod_rewrite activé dans $conf" }
        }
        if (-not (Get-Process -Name 'wampmanager' -ErrorAction SilentlyContinue)) { Start-Process -FilePath (Join-Path $wampDir 'wampmanager.exe') -WorkingDirectory $wampDir }
        $t0 = Get-Date
        do { Start-Sleep -Seconds 3; $svcs = Services-Wamp } while ((@($svcs | Where-Object { $_.Name -like 'wampapache*' }).Count -eq 0) -and ((Get-Date) - $t0).TotalSeconds -lt 120)
        foreach ($s in Services-Wamp) {
            Set-Service -Name $s.Name -StartupType Automatic -ErrorAction SilentlyContinue
            if ($s.Status -ne 'Running') { Start-Service -Name $s.Name -ErrorAction SilentlyContinue }
        }
        Start-Sleep -Seconds 4
        $etatSvc = (Services-Wamp | ForEach-Object { "$($_.Name) : $($_.Status)" }) -join ', '
        if (-not $etatSvc) { throw 'Les services de WampServer ne sont pas apparus. Ouvrez WampServer (icône verte près de l''horloge) et vérifiez son message.' }
        Etape 'serveur' 'Serveur Apache et MySQL / MariaDB' 'ok' "$etatSvc – démarrage automatique avec Windows"

        # 6. Démarrage avec Windows : icône Wamp à l'ouverture de session (les services démarrent seuls)
        if ($Options.demarrage) {
            & schtasks.exe /Create /F /TN 'POS Marwa - WampServer' /SC ONLOGON /RL HIGHEST /DELAY 0000:20 /TR "`"$(Join-Path $wampDir 'wampmanager.exe')`"" | Out-Null
            Etape 'auto' 'Démarrage automatique' 'ok' 'services Apache/MySQL automatiques + tâche planifiée « POS Marwa - WampServer » à l''ouverture de session'
        }

        # 7. Pare-feu : Apache joignable depuis les téléphones et tablettes du réseau local (pas depuis Internet)
        if ($Options.parefeu) {
            Get-NetFirewallRule -DisplayName 'POS Marwa - Apache*' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
            foreach ($exe in Get-ChildItem (Join-Path $wampDir 'bin\apache') -Recurse -Filter 'httpd.exe' -ErrorAction SilentlyContinue) {
                New-NetFirewallRule -DisplayName "POS Marwa - Apache ($($exe.Directory.Parent.Name))" -Direction Inbound -Program $exe.FullName -Action Allow -Profile Private, Domain | Out-Null
            }
            Etape 'parefeu' 'Pare-feu Windows' 'ok' 'Apache autorisé sur le réseau privé (MySQL reste fermé au réseau)'
        }

        # 8. POS Marwa
        $app = $p.elements | Where-Object { $_.type -eq 'posmarwa' } | Select-Object -First 1
        $cible = Join-Path $wampDir 'www\pos-marwa'
        if (Test-Path (Join-Path $cible '.env')) {
            Etape 'app' 'POS Marwa' 'ok' "déjà installé dans $cible : données conservées, utilisez la mise à jour intégrée pour changer de version"
            $res.url = 'http://localhost/pos-marwa/'
        } elseif ($app) {
            Etape 'app' 'POS Marwa' 'encours' "copie dans $cible"
            $src = $app.fichier
            if ($src -like '*.zip') {
                $tmp = Join-Path $Racine 'pos-marwa-tmp'; Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
                Expand-Archive -Path $src -DestinationPath $tmp -Force
                $idx = Get-ChildItem -Path $tmp -Recurse -Filter 'index.php' | Where-Object { $_.Directory.Name -eq 'public' -and (Test-Path (Join-Path $_.Directory.Parent.FullName 'app\bootstrap.php')) } | Select-Object -First 1
                if (-not $idx) { throw "Le zip $($app.nom) ne contient pas POS Marwa (dossier public\ et app\ introuvables)." }
                $src = $idx.Directory.Parent.FullName
            }
            if (Test-Path $cible) { Rename-Item $cible ("pos-marwa-avant-" + (Get-Date -Format 'yyyyMMdd-HHmmss')) }
            Copy-Item -Path $src -Destination $cible -Recurse -Force
            Remove-Item (Join-Path $Racine 'pos-marwa-tmp') -Recurse -Force -ErrorAction SilentlyContinue

            # Base de données et utilisateur MySQL dédié (jamais root dans l'application)
            $db = Trouver-MySql $wampDir
            if (-not $db) { throw 'MySQL / MariaDB de WampServer introuvable.' }
            $root = ''
            try { Sql $db '' 'SELECT 1' | Out-Null } catch { throw "Connexion à $($db.type) impossible avec le compte root sans mot de passe (déjà sécurisé ?). Terminez l'installation par la page web de POS Marwa. Détail : $_" }
            $mdp = New-Secret 24
            Sql $db $root "CREATE DATABASE IF NOT EXISTS posmarwa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'posmarwa'@'localhost' IDENTIFIED BY '$mdp'; CREATE USER IF NOT EXISTS 'posmarwa'@'127.0.0.1' IDENTIFIED BY '$mdp'; ALTER USER 'posmarwa'@'localhost' IDENTIFIED BY '$mdp'; ALTER USER 'posmarwa'@'127.0.0.1' IDENTIFIED BY '$mdp'; GRANT ALL PRIVILEGES ON posmarwa.* TO 'posmarwa'@'localhost'; GRANT ALL PRIVILEGES ON posmarwa.* TO 'posmarwa'@'127.0.0.1'; FLUSH PRIVILEGES;" | Out-Null
            $envEx = Join-Path $cible '.env.example'
            $lignes = if (Test-Path $envEx) { Get-Content $envEx -Encoding UTF8 } else { @('APP_ENV=production', 'APP_DEBUG=false', 'APP_KEY=', 'APP_TIMEZONE=Africa/Niamey', 'DB_HOST=127.0.0.1', 'DB_PORT=3306', 'DB_DATABASE=poswan', 'DB_USERNAME=', 'DB_PASSWORD=') }
            $vals = @{ APP_KEY = (New-Hex 32); DB_HOST = '127.0.0.1'; DB_PORT = [string]$db.port; DB_DATABASE = 'posmarwa'; DB_USERNAME = 'posmarwa'; DB_PASSWORD = "`"$mdp`"" }
            $lignes = $lignes | ForEach-Object { $l = $_; foreach ($k in $vals.Keys) { if ($l -match "^\s*$k\s*=") { $l = "$k=$($vals[$k])" } }; $l }
            [IO.File]::WriteAllLines((Join-Path $cible '.env'), [string[]]$lignes, (New-Object Text.UTF8Encoding($false)))
            Proteger-Fichier (Join-Path $cible '.env')

            # Compte root de MySQL : mot de passe aléatoire (aucun mot de passe par défaut laissé actif)
            if ($Options.root) {
                $rootMdp = New-Secret 20
                Sql $db '' "ALTER USER 'root'@'localhost' IDENTIFIED BY '$rootMdp'; FLUSH PRIVILEGES;" | Out-Null
                $res.root = $rootMdp
            }
            $res.identifiants = @{ base = 'posmarwa'; utilisateur = 'posmarwa'; motdepasse = $mdp; serveur = "$($db.version) – port $($db.port)" }
            $f = Join-Path $Racine 'identifiants-POS-Marwa.txt'
            @("POS Marwa – identifiants techniques ($(Get-Date -Format 'dd/MM/yyyy HH:mm'))", "Gardez ce fichier en lieu sûr. Il n'est lisible que par les administrateurs de ce PC.", '',
              "Base de données : posmarwa ($($db.version), port $($db.port))", "Utilisateur MySQL de l'application : posmarwa", "Mot de passe : $mdp",
              $(if ($res.root) { "Mot de passe root MySQL (phpMyAdmin) : $($res.root)" } else { 'Compte root MySQL : inchangé (sans mot de passe, à sécuriser)' })) | Set-Content -Path $f -Encoding UTF8
            Proteger-Fichier $f
            $res.fichierIdentifiants = $f
            $res.url = 'http://localhost/pos-marwa/'
            Etape 'app' 'POS Marwa' 'ok' "installé dans $cible, base « posmarwa » créée avec un utilisateur dédié"
        } else {
            Etape 'app' 'POS Marwa' 'absent' 'zip POS-Marwa absent du paquet'
        }

        # 9. Raccourci « POS Marwa » (fenêtre dédiée Chrome ou Edge)
        if ($res.url -and $Options.raccourci) {
            $nav = Find-Chrome
            if (-not $nav) { $nav = "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"; if (-not (Test-Path $nav)) { $nav = "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe" } }
            $sh = New-Object -ComObject WScript.Shell
            foreach ($dir in [Environment]::GetFolderPath('CommonDesktopDirectory'), (Join-Path ([Environment]::GetFolderPath('CommonPrograms')) '')) {
                $lnk = $sh.CreateShortcut((Join-Path $dir 'POS Marwa.lnk'))
                $lnk.TargetPath = $nav; $lnk.Arguments = "--app=$($res.url) --autoplay-policy=no-user-gesture-required"
                $ico = Join-Path $cible 'windows\pos-marwa.ico'; if (Test-Path $ico) { $lnk.IconLocation = $ico }
                $lnk.Description = 'POS Marwa'; $lnk.Save()
            }
            Etape 'raccourci' 'Raccourci POS Marwa' 'ok' 'Bureau et menu Démarrer (tous les utilisateurs)'
        }

        # 10. Vérification finale
        if ($res.url) {
            Etape 'test' 'Vérification' 'encours' $res.url
            $ok = $false
            for ($k = 0; $k -lt 20 -and -not $ok; $k++) {
                try { $r = Invoke-WebRequest -Uri ($res.url + 'install') -UseBasicParsing -TimeoutSec 10 -MaximumRedirection 3; $ok = $r.StatusCode -eq 200 } catch { Start-Sleep -Seconds 3 }
            }
            Etape 'test' 'Vérification' $(if ($ok) { 'ok' } else { 'erreur' }) $(if ($ok) { 'POS Marwa répond : terminez la configuration dans la fenêtre qui s''ouvre' } else { "POS Marwa ne répond pas encore à $($res.url) : redémarrez l'ordinateur puis ouvrez le raccourci POS Marwa" })
        }
    } catch {
        $res.erreurs += $_.Exception.Message
        Log "ERREUR : $($_.Exception.Message)"
    } finally {
        $Etat.resultat = $res; $Etat.enCours = $false; $Etat.fini = $true
    }
}

# ------------------------------------------------------------------ serveur web local (interface)
$Port = 47811
$Url = "http://127.0.0.1:$Port/"
$http = New-Object System.Net.HttpListener
$http.Prefixes.Add($Url)
try { $http.Start() } catch { [void][System.Reflection.Assembly]::LoadWithPartialName('System.Windows.Forms'); [System.Windows.Forms.MessageBox]::Show("Le port $Port est déjà utilisé : l'installateur est peut-être déjà ouvert.", 'Installateur POS Marwa') | Out-Null; exit 1 }
$Jeton = New-Hex 16
Log "Installateur démarré ($Url)"

function Repondre($ctx, $obj, [int]$code = 200, [string]$type = 'application/json; charset=utf-8') {
    $bytes = if ($obj -is [byte[]]) { $obj } elseif ($obj -is [string]) { [Text.Encoding]::UTF8.GetBytes($obj) } else { [Text.Encoding]::UTF8.GetBytes(($obj | ConvertTo-Json -Depth 8 -Compress)) }
    $ctx.Response.StatusCode = $code; $ctx.Response.ContentType = $type
    $ctx.Response.Headers.Add('Cache-Control', 'no-store'); $ctx.Response.Headers.Add('X-Content-Type-Options', 'nosniff')
    $ctx.Response.OutputStream.Write($bytes, 0, $bytes.Length); $ctx.Response.Close()
}

function Lire-Json($ctx) {
    $r = New-Object IO.StreamReader($ctx.Request.InputStream, [Text.Encoding]::UTF8)
    $t = $r.ReadToEnd(); if (-not $t) { return @{} }
    return $t | ConvertFrom-Json
}

# Paquet trouvé automatiquement à côté de l'installateur
function Paquet-Proche {
    foreach ($d in $Ici, (Split-Path $Ici -Parent)) {
        $z = Get-ChildItem -Path $d -Filter '*.zip' -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -match 'install|paquet|pos' -and $_.Name -notmatch '^pos[-_ ]?(marwa|wan)-\d' } | Select-Object -First 1
        if ($z) { return $z.FullName }
        if (Get-ChildItem -Path $d -Filter 'wampserver*.exe' -File -ErrorAction SilentlyContinue) { return $d }
    }
    return $null
}

$paquet = Paquet-Proche
$rs = $null; $ps = $null

$edge = @("${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe", "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $SansFenetre) {
    $nav = Find-Chrome; if (-not $nav) { $nav = $edge }
    if ($nav) { Start-Process -FilePath $nav -ArgumentList "--app=$Url`?t=$Jeton", '--window-size=1180,860' } else { Start-Process "$Url`?t=$Jeton" }
}

$fini = $false
while ($http.IsListening -and -not $fini) {
    $ctx = $http.GetContext()
    try {
        $chemin = $ctx.Request.Url.AbsolutePath
        if ($chemin -eq '/') {
            Repondre $ctx ([IO.File]::ReadAllBytes((Join-Path $Ici 'interface.html'))) 200 'text/html; charset=utf-8'; continue
        }
        # Toute action exige le jeton de la fenêtre ouverte par l'installateur
        if ($ctx.Request.Headers['X-Jeton'] -ne $Jeton) { Repondre $ctx @{ ok = $false; erreur = 'Accès refusé' } 403; continue }
        switch ($chemin) {
            '/api/systeme' { Repondre $ctx @{ ok = $true; info = (Get-InfoSysteme); paquet = $paquet } }
            '/api/ports' { Repondre $ctx @{ ok = $true; ports = @(80, 3306, 3307, 3308 | ForEach-Object { Get-InfoPort $_ }) } }
            '/api/liberer' { $b = Lire-Json $ctx; Repondre $ctx @{ ok = $true; message = (Liberer-Port ([int]$b.port)) } }
            '/api/choisir' {
                [void][System.Reflection.Assembly]::LoadWithPartialName('System.Windows.Forms')
                $dlg = New-Object System.Windows.Forms.OpenFileDialog
                $dlg.Title = 'Choisir le paquet d''installation (pos-wan install)'; $dlg.Filter = 'Paquet zip (*.zip)|*.zip|Tous les fichiers (*.*)|*.*'
                $form = New-Object System.Windows.Forms.Form -Property @{ TopMost = $true }
                if ($dlg.ShowDialog($form) -eq 'OK') { $paquet = $dlg.FileName }
                $form.Dispose()
                Repondre $ctx @{ ok = $true; paquet = $paquet }
            }
            '/api/analyser' {
                $b = Lire-Json $ctx; if ($b.paquet) { $paquet = [string]$b.paquet }
                try { $a = Analyser-Paquet $paquet; Repondre $ctx @{ ok = $true; paquet = $paquet; analyse = $a } } catch { Repondre $ctx @{ ok = $false; erreur = $_.Exception.Message } }
            }
            '/api/installer' {
                if ($Etat.enCours) { Repondre $ctx @{ ok = $false; erreur = 'Installation déjà en cours.' }; break }
                $b = Lire-Json $ctx
                $opt = @{ liberer = [bool]$b.liberer; chrome = [bool]$b.chrome; demarrage = [bool]$b.demarrage; parefeu = [bool]$b.parefeu; root = [bool]$b.root; raccourci = [bool]$b.raccourci }
                $Etat.enCours = $true; $Etat.fini = $false; $Etat.etapes.Clear(); $Etat.resultat = $null
                $rs = [runspacefactory]::CreateRunspace(); $rs.ApartmentState = 'STA'; $rs.Open()
                $ps = [powershell]::Create(); $ps.Runspace = $rs
                [void]$ps.AddScript($Installation).AddArgument($Etat).AddArgument($Lib).AddArgument($paquet).AddArgument($opt)
                [void]$ps.BeginInvoke()
                Repondre $ctx @{ ok = $true }
            }
            '/api/progression' {
                $log = @($Etat.log); $n = $log.Count
                Repondre $ctx @{ ok = $true; enCours = $Etat.enCours; fini = $Etat.fini; etapes = @($Etat.etapes); journal = @($log[[math]::Max(0, $n - 40)..($n - 1)] | Where-Object { $_ }); resultat = $Etat.resultat }
            }
            '/api/ouvrir' {
                $nav = Find-Chrome; if (-not $nav) { $nav = $edge }
                if ($nav) { Start-Process -FilePath $nav -ArgumentList '--app=http://localhost/pos-marwa/' } else { Start-Process 'http://localhost/pos-marwa/' }
                Repondre $ctx @{ ok = $true }
            }
            '/api/journal' { Start-Process notepad.exe -ArgumentList $Journal; Repondre $ctx @{ ok = $true } }
            '/api/redemarrer' { Repondre $ctx @{ ok = $true }; & shutdown.exe /r /t 15 /c 'Redémarrage demandé par l''installateur POS Marwa' }
            '/api/quitter' { Repondre $ctx @{ ok = $true }; $fini = $true }
            default { Repondre $ctx @{ ok = $false; erreur = 'Inconnu' } 404 }
        }
    } catch {
        try { Repondre $ctx @{ ok = $false; erreur = $_.Exception.Message } 500 } catch {}
    }
}
$http.Stop()
