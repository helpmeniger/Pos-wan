<#
  Installateur POS Marwa pour Windows 7 SP1, 8.1, 10 et 11 (32 ou 64 bits) – WampServer.
  - Affiche la configuration de l'ordinateur et contrôle les prérequis.
  - Diagnostic de tout ce qui peut bloquer l'installation (fichiers marqués « Internet », mise à jour
    en attente de redémarrage, Windows Installer occupé ou désactivé, ports réservés par Hyper-V / WSL /
    Docker, Contrôle intelligent des applications, mode S, antivirus…) et correction des blocages
    qui peuvent l'être sans danger.
  - Détecte les programmes qui occupent les ports de WampServer (80, 3306, 3307, 3308) et les libère.
  - Installe depuis le paquet fourni (zip ou dossier) : Visual C++ 2008 à 2022, WampServer,
    Google Chrome, puis POS Marwa (base de données, utilisateur MySQL dédié, fichier .env).
  - Démarre le serveur automatiquement avec Windows, crée le raccourci, ouvre les règles du pare-feu utiles.
  Interface : page HTML locale (http://127.0.0.1:<port>, accessible uniquement depuis ce PC).
  Ne désactive jamais l'UAC, l'antivirus, SmartScreen, le Contrôle intelligent des applications ni le pare-feu.
  Compatible Windows PowerShell 3.0 et plus récent (5.1 conseillé).
#>
param([switch]$SansFenetre, [string]$Depuis = '')
$ErrorActionPreference = 'Stop'
# Sans barre de progression : Expand-Archive et Invoke-WebRequest sont plusieurs fois plus rapides sous PowerShell 5.1
$ProgressPreference = 'SilentlyContinue'
$Ici = $PSScriptRoot
if (-not $Ici) { $Ici = Split-Path -Parent $MyInvocation.MyCommand.Path }
if ($Depuis) {
    $Depuis = $Depuis.Trim('"').TrimEnd('\'); if ($Depuis -match '^[A-Za-z]:$') { $Depuis += '\' }
    try { $Depuis = [IO.Path]::GetFullPath($Depuis) } catch {}
}

try { $Host.UI.RawUI.WindowTitle = 'Installateur POS Marwa' } catch {}
Write-Host ''
Write-Host '  INSTALLATEUR POS MARWA' -ForegroundColor Yellow
Write-Host '  Ne fermez pas cette fenêtre : elle fait fonctionner l''assistant d''installation.' -ForegroundColor Gray
Write-Host ''

# ------------------------------------------------------------------ mode restreint (AppLocker, WDAC, Contrôle intelligent des applications)
if ($ExecutionContext.SessionState.LanguageMode -ne 'FullLanguage') {
    Write-Host "  PowerShell fonctionne en mode restreint ($($ExecutionContext.SessionState.LanguageMode))." -ForegroundColor Red
    Write-Host '  Une stratégie de sécurité de Windows (AppLocker, Device Guard / WDAC ou le Contrôle intelligent' -ForegroundColor Red
    Write-Host '  des applications) empêche l''installateur de fonctionner. Elle doit être levée par l''administrateur du PC.' -ForegroundColor Red
    Start-Sleep -Seconds 20
    exit 4
}

# ------------------------------------------------------------------ droits administrateur
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    $a = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-STA', '-File', "`"$PSCommandPath`"")
    if ($Depuis) { $a += @('-Depuis', "`"$($Depuis.TrimEnd('\'))`"") }
    try {
        Start-Process -FilePath (Join-Path $PSHOME 'powershell.exe') -Verb RunAs -ArgumentList $a -ErrorAction Stop
    } catch {
        Write-Host '  L''autorisation administrateur a été refusée : l''installation ne peut pas continuer.' -ForegroundColor Red
        Write-Host '  Relancez l''installateur et répondez « Oui » à la demande de Windows (ou saisissez le mot de passe d''un administrateur).' -ForegroundColor Red
        Start-Sleep -Seconds 15
        exit 5
    }
    exit 0
}

# ------------------------------------------------------------------ bibliothèque (partagée avec la tâche d'installation)
$Lib = @'
$ProgressPreference = 'SilentlyContinue'
$Racine = 'C:\POS-Marwa-Installation'
New-Item -ItemType Directory -Force -Path $Racine | Out-Null
$Journal = Join-Path $Racine 'installation.log'

function Log([string]$m) {
    $l = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $m
    try { Add-Content -Path $Journal -Value $l -Encoding UTF8 } catch {}
    if ($script:Etat) { [void]$script:Etat.log.Add($l) }
}

# WMI / CIM : Get-CimInstance (PowerShell 3+), sinon Get-WmiObject
function Get-Wmi([string]$classe, [string]$filtre = '', [string]$ns = 'root\cimv2') {
    $p = @{ Namespace = $ns; ErrorAction = 'Stop' }
    if ($filtre) { $p.Filter = $filtre }
    try { return @(Get-CimInstance -ClassName $classe @p) } catch {}
    try { return @(Get-WmiObject -Class $classe @p) } catch {}
    return @()
}

function Commande([string]$nom) { return [bool](Get-Command $nom -ErrorAction SilentlyContinue) }

# Décompression : .NET 4.5 (rapide), sinon Expand-Archive (PowerShell 5, lent), sinon l'Explorateur Windows
function Extraire-Zip([string]$zip, [string]$dest) {
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    try { Add-Type -AssemblyName System.IO.Compression.FileSystem; [IO.Compression.ZipFile]::ExtractToDirectory($zip, $dest); return } catch { Log "Décompression .NET impossible ($($_.Exception.Message)) : autre méthode" }
    if (Commande 'Expand-Archive') { Expand-Archive -LiteralPath $zip -DestinationPath $dest -Force; return }
    $sh = New-Object -ComObject Shell.Application
    $sh.NameSpace($dest).CopyHere($sh.NameSpace($zip).Items(), 0x614)
}

# Liste des fichiers d'un zip, sans rien décompresser (lecture de la table des matières : instantané)
function Lister-Zip([string]$zip) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $z = [IO.Compression.ZipFile]::OpenRead($zip)
    try { return @($z.Entries | Where-Object { $_.Name } | ForEach-Object { $_.FullName }) } finally { $z.Dispose() }
}

# Extrait du zip les seuls fichiers demandés (un fichier, ou tout un dossier si $prefixe est donné)
function Extraire-Entrees([string]$zip, [string]$dossier, [string]$entree = '', [string]$prefixe = '') {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $z = [IO.Compression.ZipFile]::OpenRead($zip)
    try {
        foreach ($en in $z.Entries) {
            if (-not $en.Name) { continue }
            $rel = $en.FullName -replace '/', '\'
            if ($rel -match '(^|\\)\.\.(\\|$)') { continue }   # chemin piégé (« ..\ ») : ignoré
            if ($entree) { if ($en.FullName -ne $entree) { continue } }
            elseif (-not $rel.StartsWith($prefixe, [StringComparison]::OrdinalIgnoreCase)) { continue }
            $cible = Join-Path $dossier $rel
            if (Test-Path -LiteralPath $cible) { continue }
            New-Item -ItemType Directory -Force -Path (Split-Path $cible -Parent) | Out-Null
            # Écrit d'abord sous un nom temporaire : une extraction interrompue ne laisse jamais un fichier tronqué
            [IO.Compression.ZipFileExtensions]::ExtractToFile($en, "$cible.part", $true)
            Move-Item -LiteralPath "$cible.part" -Destination $cible -Force
        }
    } finally { $z.Dispose() }
}

# Rend disponible sur le disque le fichier d'un composant du paquet (extraction à la demande)
function Obtenir-Element($e) {
    if ($e.zip) {
        if ($null -ne $e.prefixe) { Extraire-Entrees $e.zip $e.dossier '' ([string]$e.prefixe) }
        elseif (-not (Test-Path -LiteralPath $e.fichier)) { Log "Extraction de $($e.nom)"; Extraire-Entrees $e.zip $e.dossier $e.entree }
    }
    if (-not (Test-Path -LiteralPath $e.fichier)) { throw "Fichier introuvable : $($e.fichier)" }
    return $e.fichier
}

# Version (majeure.mineure.build) d'un exécutable
function Get-VersionFichier([string]$f) {
    try {
        $vi = (Get-Item -LiteralPath $f).VersionInfo
        $t = [string]$vi.ProductVersion; if (-not $t) { $t = [string]$vi.FileVersion }
        $p = @(($t -replace '[^\d\.]', '').Split('.') | Where-Object { $_ -ne '' })
        if ($p.Count -ge 3) { return [version]('{0}.{1}.{2}' -f $p[0], $p[1], $p[2]) }
    } catch {}
    return $null
}

# Visual C++ déjà installé : version la plus récente pour une génération (9 = 2008 … 14 = 2015-2022) et une architecture
function Get-VcInstalle([int]$majeur, [bool]$x64) {
    $arch = if ($x64) { 'x64' } else { 'x86' }
    $meilleure = $null
    foreach ($k in @(Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*' -ErrorAction SilentlyContinue)) {
        $nom = [string]$k.DisplayName
        if ($nom -notlike 'Microsoft Visual C++*Redistributable*' -or $nom -notmatch $arch) { continue }
        $p = @(([string]$k.DisplayVersion).Split('.') | Where-Object { $_ -ne '' })
        if ($p.Count -lt 3) { continue }
        try { $v = [version]('{0}.{1}.{2}' -f $p[0], $p[1], $p[2]) } catch { continue }
        if ($v.Major -eq $majeur -and (-not $meilleure -or $v -gt $meilleure)) { $meilleure = $v }
    }
    return $meilleure
}

# Famille Visual C++ utile à WampServer, d'après le chemin du fichier dans le paquet (nom ET dossiers :
# « VC2008\vcredist_x64.exe » est bien un 2008). Les seules installées, dans cet ordre :
#   1 VC9 2008 SP1 · 2 VC10 2010 SP1 · 3 VC11 2012 Update 4 · 4 VC13 2013 Update 5 · 5 VC14 2015 · 6 VC17 2015-2022
# rang = préférence entre plusieurs fichiers d'une même famille (service pack / mise à jour la plus récente).
function Get-FamilleVc([string]$chemin) {
    # Nom du fichier et de son dossier seulement (« pos-marwa install\… » ne doit rien influencer)
    $c = (@(($chemin -replace '/', '\').Split('\') | Where-Object { $_ }) | Select-Object -Last 2) -join '\'
    $c = $c.ToLower()
    if ($c -match '(^|[\\_.\s-])(arm64|arm|ia64|itanium|debug)([\\_.\s-]|$)') { return $null }
    if ($c -match '2015\s*[-_]\s*20(19|22)|2022|2019|2017') {
        $r = if ($c -match '2022') { 3 } elseif ($c -match '2019') { 2 } else { 1 }
        return @{ slot = 6; libelle = 'VC17 2015-2022'; annee = 2022; rang = $r }
    }
    if ($c -match '2015') { return @{ slot = 5; libelle = 'VC14 2015'; annee = 2015; rang = 1 } }
    if ($c -match '2013') { return @{ slot = 4; libelle = 'VC13 2013 Update 5'; annee = 2013; rang = $(if ($c -match 'upd(ate)?[\s_-]*5|u5') { 2 } else { 1 }) } }
    if ($c -match '2012') { return @{ slot = 3; libelle = 'VC11 2012 Update 4'; annee = 2012; rang = $(if ($c -match 'upd(ate)?[\s_-]*4|u4') { 2 } else { 1 }) } }
    if ($c -match '2010') { return @{ slot = 2; libelle = 'VC10 2010 SP1'; annee = 2010; rang = $(if ($c -match 'sp1') { 2 } else { 1 }) } }
    if ($c -match '2008') { return @{ slot = 1; libelle = 'VC9 2008 SP1'; annee = 2008; rang = $(if ($c -match 'sp1') { 2 } else { 1 }) } }
    if ($c -match '2005') { return $null }   # inutile à WampServer
    # Sans année : « VC_redist.x64.exe » = Visual C++ 2015-2022 (le moins prioritaire de sa famille)
    return @{ slot = 6; libelle = 'VC17 2015-2022'; annee = 2022; rang = 0 }
}

function Get-MajeurVc([int]$annee) { switch ($annee) { 2005 { 8 } 2008 { 9 } 2010 { 10 } 2012 { 11 } 2013 { 12 } default { 14 } } }

# Le fichier porte-t-il la marque « téléchargé depuis Internet » ?
# (Windows PowerShell 5.1 peut échouer sur « Get-Item -LiteralPath … -Stream » quand le flux n'existe pas,
#  on passe par -Path échappé et -Stream *, et aucune erreur ne remonte.)
function Test-MarqueInternet([string]$f) {
    try {
        $flux = @(Get-Item -Path ([Management.Automation.WildcardPattern]::Escape($f)) -Stream * -ErrorAction Stop)
        return [bool]($flux | Where-Object { $_.Stream -eq 'Zone.Identifier' })
    } catch { return $false }
}

# Retire la marque « téléchargé depuis Internet » (comme Propriétés > Débloquer). Ne bloque jamais l'installation.
function Debloquer([string]$chemin) {
    $n = 0
    try {
        if (-not $chemin -or -not (Test-Path -LiteralPath $chemin)) { return 0 }
        $fichiers = if ((Get-Item -LiteralPath $chemin).PSIsContainer) { @(Get-ChildItem -LiteralPath $chemin -Recurse -File -ErrorAction SilentlyContinue) } else { @(Get-Item -LiteralPath $chemin) }
        foreach ($f in $fichiers) {
            if (Test-MarqueInternet $f.FullName) {
                try { Unblock-File -LiteralPath $f.FullName -ErrorAction Stop; $n++ } catch {}
            }
        }
    } catch { Log "Avertissement (déblocage de $chemin) : $($_.Exception.Message)" }
    return $n
}

function Get-InfoSysteme {
    $os = Get-Wmi Win32_OperatingSystem | Select-Object -First 1
    $cs = Get-Wmi Win32_ComputerSystem | Select-Object -First 1
    $cpu = Get-Wmi Win32_Processor | Select-Object -First 1
    $disk = Get-Wmi Win32_LogicalDisk "DeviceID='$env:SystemDrive'" | Select-Object -First 1
    $gpu = @(Get-Wmi Win32_VideoController | ForEach-Object { $_.Name }) -join ', '
    if (Commande 'Get-NetIPAddress') {
        $ips = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | ForEach-Object { $_.IPAddress })
    } else {
        $ips = @(Get-Wmi Win32_NetworkAdapterConfiguration 'IPEnabled=True' | ForEach-Object { $_.IPAddress } | Where-Object { $_ -match '^\d+\.\d+\.\d+\.\d+$' -and $_ -notlike '127.*' -and $_ -notlike '169.254.*' })
    }
    $vc = @(Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*' -ErrorAction SilentlyContinue |
        Where-Object { $_.DisplayName -like 'Microsoft Visual C++*Redistributable*' } | ForEach-Object { $_.DisplayName } | Sort-Object -Unique)
    $ramGo = [math]::Round([double]$cs.TotalPhysicalMemory / 1GB, 1)
    $libreGo = [math]::Round([double]$disk.FreeSpace / 1GB, 1)
    $x64 = [Environment]::Is64BitOperatingSystem
    $build = [int]$os.BuildNumber
    $boot = $os.LastBootUpTime
    if ($boot -is [string]) { try { $boot = [Management.ManagementDateTimeConverter]::ToDateTime($boot) } catch {} }
    $demarre = if ($boot -is [datetime]) { $boot.ToString('dd/MM/yyyy HH:mm') } else { '' }
    if (Commande 'Get-NetFirewallProfile') {
        $fw = @(Get-NetFirewallProfile -ErrorAction SilentlyContinue | ForEach-Object { $_.Name + ' : ' + $(if ($_.Enabled) { 'actif' } else { 'désactivé' }) }) -join ', '
    } else { $fw = 'voir Panneau de configuration > Pare-feu Windows' }
    $verdicts = @(
        @{ ok = ($build -ge 7601); t = 'Windows 7 SP1, 8.1, 10 ou 11' },
        @{ ok = $true; t = $(if ($x64) { 'Windows 64 bits' } else { 'Windows 32 bits : le paquet doit contenir WampServer 32 bits (fichier …_x86.exe)' }) },
        @{ ok = ($PSVersionTable.PSVersion.Major -ge 3); t = "Windows PowerShell $($PSVersionTable.PSVersion)" },
        @{ ok = ($ramGo -ge 3.5); t = "Mémoire : $ramGo Go (4 Go conseillés)" },
        @{ ok = ($libreGo -ge 5); t = "Espace libre sur $($env:SystemDrive) : $libreGo Go (5 Go minimum)" }
    )
    return [ordered]@{
        ordinateur = $env:COMPUTERNAME; utilisateur = "$env:USERDOMAIN\$env:USERNAME"
        systeme = "$($os.Caption) ($($os.OSArchitecture)) – version $($os.Version)"; demarre = $demarre
        fabricant = "$($cs.Manufacturer) $($cs.Model)"; processeur = "$(([string]$cpu.Name).Trim()) – $($cpu.NumberOfCores) cœurs / $($cpu.NumberOfLogicalProcessors) threads"
        memoire = "$ramGo Go"; disque = "$($env:SystemDrive) $libreGo Go libres sur $([math]::Round([double]$disk.Size / 1GB, 0)) Go"; graphique = $gpu
        reseau = $(if ($ips) { $ips -join ', ' } else { 'aucune adresse réseau' }); parefeu = $fw; powershell = $PSVersionTable.PSVersion.ToString()
        vcredist = $vc; chrome = (Find-Chrome); wamp = (Find-Wamp); admin = $true; verdicts = $verdicts
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

function Find-Edge {
    foreach ($p in "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe", "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe") {
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

# PID du programme qui écoute sur un port TCP (Get-NetTCPConnection sur Windows 8+, sinon netstat)
function Get-PidEcoute([int]$port) {
    if (Commande 'Get-NetTCPConnection') {
        $c = @(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
        if ($c) { return [int]$c[0].OwningProcess }
        return $null
    }
    foreach ($l in @(& netstat.exe -ano -p TCP) + @(& netstat.exe -ano -p TCPv6)) {
        # « TCP  0.0.0.0:80  0.0.0.0:0  LISTENING  4 » (le mot LISTENING est traduit selon la langue de Windows)
        if ($l -match '^\s*TCP\s+(\S+):(\d+)\s+(\S+):0\s+\S+\s+(\d+)\s*$' -and [int]$Matches[2] -eq $port) { return [int]$Matches[4] }
    }
    return $null
}

function Get-InfoPort([int]$port) {
    $owner = Get-PidEcoute $port
    if ($null -eq $owner) { return [ordered]@{ port = $port; libre = $true } }
    $p = Get-Process -Id $owner -ErrorAction SilentlyContinue
    $svc = @(Get-Wmi Win32_Service "ProcessId=$owner" | ForEach-Object { $_.Name + ' (' + $_.DisplayName + ')' })
    if ($owner -eq 4) {
        $svc = @($ServicesHttp | ForEach-Object { Get-Service -Name $_ -ErrorAction SilentlyContinue } | Where-Object { $_.Status -eq 'Running' } | ForEach-Object { $_.Name + ' (' + $_.DisplayName + ')' })
    }
    $path = $null; try { $path = $p.Path } catch {}
    $nom = if ($p) { $p.ProcessName } else { "PID $owner" }
    $wamp = ($path -and ($path -match '\\wamp(64)?\\')) -or ($nom -in @('httpd', 'mysqld', 'mariadbd') -and $path -match 'wamp')
    return [ordered]@{ port = $port; libre = $false; pid = $owner; processus = $(if ($owner -eq 4) { 'Système Windows (http.sys)' } else { $nom }); chemin = $path; services = $svc; wamp = [bool]$wamp }
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
        foreach ($s in @(Get-Wmi Win32_Service "ProcessId=$($i.pid)")) {
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

# Plages de ports réservées par Windows (Hyper-V, WSL, Docker) : Apache ou MySQL ne peuvent pas s'y installer
function Get-PortsReserves {
    $out = @()
    $lignes = @()
    try { $lignes = @(& netsh.exe interface ipv4 show excludedportrange protocol=tcp 2>$null) } catch { return @() }
    foreach ($l in $lignes) {
        if ($l -match '^\s*(\d+)\s+(\d+)\s*(\*)?\s*$') { $out += @{ debut = [int]$Matches[1]; fin = [int]$Matches[2]; admin = [bool]$Matches[3] } }
    }
    return $out
}

function Get-ConflitsReserves {
    $c = @()
    $plages = @(Get-PortsReserves)
    foreach ($port in 80, 3306, 3307, 3308) {
        foreach ($r in $plages) { if ($port -ge $r.debut -and $port -le $r.fin) { $c += @{ port = $port; debut = $r.debut; fin = $r.fin; admin = $r.admin } } }
    }
    return $c
}

# Une installation Windows Installer (MSI, Windows Update…) est-elle en cours ?
function Test-MsiOccupe {
    $m = $null
    try { if ([Threading.Mutex]::TryOpenExisting('Global\_MSIExecute', [ref]$m)) { $m.Dispose(); return $true } } catch {}
    return $false
}

function Test-RedemarrageEnAttente {
    return (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') -or
           (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired')
}

# Diagnostic : tout ce qui peut empêcher l'installation (niveau ok / info / attention / bloquant)
function Get-Blocages([string]$paquet, [string]$ici) {
    $l = New-Object System.Collections.ArrayList
    function Ajouter($niveau, $titre, $detail, $action = $null, $libelle = $null) { [void]$l.Add([ordered]@{ niveau = $niveau; titre = $titre; detail = $detail; action = $action; libelle = $libelle }) }

    $os = Get-Wmi Win32_OperatingSystem | Select-Object -First 1
    $build = [int]$os.BuildNumber
    if ($build -lt 7601) { Ajouter 'bloquant' 'Version de Windows trop ancienne' "$($os.Caption) : Windows 7 SP1 au minimum." }
    elseif ($build -lt 10240) { Ajouter 'attention' "$($os.Caption)" 'Windows 7 et 8.1 ne reçoivent plus de mises à jour : les versions récentes de WampServer et de Google Chrome peuvent refuser de s''installer. Windows 10 ou 11 conseillé.' }
    else { Ajouter 'ok' 'Version de Windows' "$($os.Caption) (build $build)" }
    Ajouter 'ok' 'PowerShell et droits administrateur' "PowerShell $($PSVersionTable.PSVersion) en mode complet, exécuté en administrateur."

    $ci = Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\CI\Policy' -ErrorAction SilentlyContinue
    if ($ci -and $ci.SkuPolicyRequired -eq 1) { Ajouter 'bloquant' 'Windows en mode S' 'Le mode S n''autorise que les applications du Microsoft Store : WampServer ne peut pas s''installer. Sortez du mode S (Paramètres > Système > Activation) : gratuit, mais définitif.' }
    $sac = $null; if ($ci) { $sac = $ci.VerifiedAndReputablePolicyState }
    if ($sac -eq 1) { Ajouter 'attention' 'Contrôle intelligent des applications : activé' 'Windows 11 bloque alors, sans possibilité de passer outre, les programmes non signés ou peu connus. Si un programme du paquet est refusé, il faut le désactiver dans Sécurité Windows > Contrôle des applications et du navigateur. Cette désactivation est définitive : c''est à vous de décider, l''installateur ne le fait pas.' }
    elseif ($sac -eq 2) { Ajouter 'info' 'Contrôle intelligent des applications : en évaluation' 'Windows observe les programmes installés et pourra l''activer plus tard.' }

    if (Test-RedemarrageEnAttente) { Ajouter 'attention' 'Redémarrage de Windows en attente' 'Une mise à jour attend un redémarrage : les composants Visual C++ peuvent échouer tant que Windows n''a pas redémarré. Après le redémarrage, relancez l''installateur.' 'redemarrer' 'Redémarrer Windows' }
    if (Test-MsiOccupe) { Ajouter 'attention' 'Une autre installation est en cours' 'Windows Installer est occupé (souvent par Windows Update). L''installateur attendra automatiquement qu''il soit libre.' }
    $msi = Get-Wmi Win32_Service "Name='msiserver'" | Select-Object -First 1
    if ($msi -and $msi.StartMode -eq 'Disabled') { Ajouter 'bloquant' 'Service Windows Installer désactivé' 'Les composants Visual C++ et Google Chrome (MSI) ne peuvent pas s''installer.' 'msiserver' 'Réactiver le service' }

    $marques = @()
    $aVerifier = @()
    if ($paquet -and (Test-Path -LiteralPath $paquet -PathType Leaf)) { $aVerifier += $paquet }
    if ($ici) { $aVerifier += @(Get-ChildItem -LiteralPath $ici -File -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName }) }
    foreach ($f in $aVerifier) { if (Test-MarqueInternet $f) { $marques += (Split-Path $f -Leaf) } }
    if ($marques) { Ajouter 'attention' 'Fichiers marqués « téléchargé depuis Internet »' (($marques -join ', ') + ' : Windows (SmartScreen) peut bloquer ou interrompre leur exécution.') 'debloquer' 'Débloquer ces fichiers' }

    foreach ($c in @(Get-ConflitsReserves)) {
        if ($c.admin) { Ajouter 'bloquant' "Port $($c.port) réservé par un administrateur" "La plage $($c.debut)-$($c.fin) est réservée de façon permanente. Commande pour la supprimer (invite de commandes administrateur) : netsh int ipv4 delete excludedportrange protocol=tcp startport=$($c.debut) numberofports=$($c.fin - $c.debut + 1)" }
        else { Ajouter 'bloquant' "Port $($c.port) réservé par Windows" "Hyper-V, WSL ou Docker a réservé la plage $($c.debut)-$($c.fin) : WampServer ne pourra pas utiliser le port $($c.port)." 'winnat' 'Libérer la réservation' }
    }

    $autres = @(Get-Process -Name 'httpd', 'mysqld', 'mariadbd', 'nginx' -ErrorAction SilentlyContinue | Where-Object { $p = $null; try { $p = $_.Path } catch {}; -not ($p -match '\\wamp(64)?\\') })
    if ($autres) { Ajouter 'info' 'Autre serveur web ou MySQL en marche' ((@($autres | ForEach-Object { $_.ProcessName } | Sort-Object -Unique) -join ', ') + ' (XAMPP, Laragon… ?) : s''il occupe les ports de WampServer, libérez-les à l''étape 2.') }

    $av = @(Get-Wmi AntiVirusProduct '' 'root\SecurityCenter2' | ForEach-Object { $_.displayName } | Sort-Object -Unique)
    if ($av) { Ajouter 'info' 'Antivirus' (($av -join ', ') + ' : s''il signale WampServer ou un fichier du paquet, choisissez « Autoriser ». L''installateur ne le désactive pas.') }
    try { $mp = Get-MpPreference -ErrorAction Stop; if ($mp.EnableControlledFolderAccess -eq 1) { Ajouter 'attention' 'Accès contrôlé aux dossiers (Windows Defender) : activé' 'Il peut empêcher la création du raccourci sur le Bureau. Le raccourci du menu Démarrer sera créé quand même.' } } catch {}

    $rel = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full' -ErrorAction SilentlyContinue).Release
    if (-not $rel -or $rel -lt 378389) { Ajouter 'attention' '.NET Framework 4.5 absent' 'Il sert à décompresser le paquet sur Windows 7 et 8. Installez .NET Framework 4.8 (gratuit, Microsoft) si la décompression échoue.' }

    if ($ici -and $ici -like '\\*') { Ajouter 'attention' 'Installateur lancé depuis le réseau' 'Copiez le dossier de l''installateur et le paquet sur le disque C: (Bureau ou Téléchargements), puis relancez.' }
    return $l
}

# Corrections sans danger proposées par le diagnostic
function Corriger-Blocage([string]$action, [string]$paquet, [string]$ici) {
    switch ($action) {
        'debloquer' {
            $n = 0; foreach ($c in @($paquet, $ici)) { $n += Debloquer $c }
            Log "Fichiers débloqués : $n"
            return "$n fichier(s) débloqué(s)."
        }
        'msiserver' {
            Set-Service -Name msiserver -StartupType Manual
            Log 'Service Windows Installer réactivé (démarrage manuel)'
            return 'Service Windows Installer réactivé.'
        }
        'winnat' {
            & net.exe stop winnat 2>&1 | Out-Null
            & net.exe start winnat 2>&1 | Out-Null
            $reste = @(Get-ConflitsReserves | ForEach-Object { $_.port })
            $msg = if ($reste) { "Réservation toujours présente pour le(s) port(s) $($reste -join ', ') : redémarrez Windows puis relancez l'installateur." } else { 'Réservation libérée : les ports de WampServer sont disponibles.' }
            Log "Service NAT de Windows redémarré. $msg"
            return $msg
        }
        'redemarrer' {
            & shutdown.exe /r /t 30 /c 'Redémarrage demandé par l''installateur POS Marwa. Relancez ensuite l''installateur.'
            return 'Windows redémarre dans 30 secondes. Relancez ensuite l''installateur.'
        }
        default { throw "Action inconnue : $action" }
    }
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

# Contenu du paquet : chaque programme est reconnu par son nom de fichier.
# Un zip est lu sans être décompressé : seuls les composants réellement installés seront extraits.
function Analyser-Paquet([string]$chemin) {
    if (-not $chemin -or -not (Test-Path -LiteralPath $chemin)) { throw "Introuvable : $chemin" }
    $zip = $null; $fichiers = @()
    if ((Get-Item -LiteralPath $chemin).PSIsContainer -eq $false) {
        [void](Debloquer $chemin)
        $dossier = Join-Path $Racine ('paquet\' + [IO.Path]::GetFileNameWithoutExtension($chemin))
        $entrees = $null
        try { $entrees = Lister-Zip $chemin } catch { Log "Lecture directe du zip impossible ($($_.Exception.Message)) : décompression complète" }
        if ($null -ne $entrees) {
            $zip = $chemin
            foreach ($en in $entrees) {
                $rel = $en -replace '/', '\'
                if ($rel -match '(^|\\)\.\.(\\|$)') { continue }
                $fichiers += @{ nom = (Split-Path $rel -Leaf); rel = $rel; entree = $en; complet = (Join-Path $dossier $rel) }
            }
        } else {
            if (-not (Test-Path $dossier)) {
                Log "Extraction du paquet $chemin"
                try { Extraire-Zip $chemin $dossier } catch { Remove-Item $dossier -Recurse -Force -ErrorAction SilentlyContinue; throw "Décompression impossible de $chemin : $($_.Exception.Message)" }
            }
        }
    } else { $dossier = $chemin }
    if (-not $zip) {
        [void](Debloquer $dossier)
        $racineLen = ([string]$dossier).TrimEnd('\').Length + 1
        foreach ($f in Get-ChildItem -LiteralPath $dossier -Recurse -File) { $fichiers += @{ nom = $f.Name; rel = $f.FullName.Substring($racineLen); entree = $null; complet = $f.FullName } }
    }
    $wampIci = Find-Wamp; $chromeIci = Find-Chrome
    $items = @(); $vcChoix = @{}; $vcIgnores = 0
    foreach ($f in $fichiers) {
        $n = $f.nom.ToLower()
        $x64 = $n -match 'x64|amd64'
        $e = [ordered]@{ type = $null; fichier = $f.complet; nom = $f.nom; zip = $zip; dossier = $dossier; entree = $f.entree; installe = $null }
        if ($n -match '^vc_?redist|vcredist' -and $n -like '*.exe') {
            $fam = Get-FamilleVc $f.rel
            if (-not $fam) { $vcIgnores++; continue }   # 2005, ARM, débogage… : inutile à WampServer
            $x64 = ((@(($f.rel -replace '/', '\').Split('\') | Where-Object { $_ }) | Select-Object -Last 2) -join '\').ToLower() -match 'x64|amd64'
            if ($x64 -and -not [Environment]::Is64BitOperatingSystem) { $vcIgnores++; continue }   # x64 impossible sur Windows 32 bits
            $e['type'] = 'vcredist'; $e['annee'] = $fam.annee; $e['famille'] = $fam.libelle + $(if ($x64) { ' x64' } else { ' x86' }); $e['x64'] = $x64
            $e['ordre'] = $fam.slot * 10 + [int]$x64; $e['rang'] = $fam.rang
            $iv = Get-VcInstalle (Get-MajeurVc $fam.annee) $x64
            if ($iv) { $e['installe'] = "v$iv" }
            # Un seul fichier par famille et par architecture : le plus récent (SP1, Update 4/5, 2022…)
            $k = [string]$e['ordre']
            if ($vcChoix.ContainsKey($k)) { $vcIgnores++; if ($e['rang'] -le $vcChoix[$k]['rang']) { continue } }
            $vcChoix[$k] = $e
            continue
        } elseif ($n -like 'wampserver*.exe') {
            $e['type'] = 'wamp'; $e['x64'] = ($n -notmatch 'x86|32'); $e['ordre'] = 50000; if ($wampIci) { $e['installe'] = $wampIci }
        } elseif ($n -like '*chrome*.exe' -or $n -like 'googlechrome*.msi') {
            $e['type'] = 'chrome'; $e['ordre'] = 60000; if ($chromeIci) { $e['installe'] = 'installé' }
        } elseif ($n -match '^pos[-_ ]?(marwa|wan).*\.zip$') {
            $e['type'] = 'posmarwa'; $e['ordre'] = 70000
        } else { continue }
        $items += $e
    }
    # POS Marwa déjà décompressé dans le paquet (dossier contenant public\index.php et app\bootstrap.php)
    if (-not ($items | Where-Object { $_.type -eq 'posmarwa' })) {
        $rels = @($fichiers | ForEach-Object { $_.rel })
        foreach ($r in $rels) {
            if ($r -match '^(.*?)public\\index\.php$' -and ($rels -contains ($Matches[1] + 'app\bootstrap.php'))) {
                $pre = $Matches[1]
                $items += [ordered]@{ type = 'posmarwa'; fichier = $(if ($pre) { Join-Path $dossier $pre.TrimEnd('\') } else { $dossier }); nom = 'POS Marwa (dossier)'; zip = $zip; dossier = $dossier; prefixe = $pre; ordre = 70000 }
                break
            }
        }
    }
    $items += @($vcChoix.Values)
    $items = @($items | Sort-Object { $_.ordre })
    if ($vcIgnores) { Log "Visual C++ : $($vcChoix.Count) paquet(s) retenu(s) pour WampServer, $vcIgnores fichier(s) du paquet ignoré(s) (doublons, 2005, ARM…)" }
    return [ordered]@{ dossier = $dossier; elements = $items; vcIgnores = $vcIgnores }
}

function Arguments-Silencieux($e) {
    switch ($e.type) {
        'vcredist' {
            if ($e.annee -le 2008) { return '/q' }
            if ($e.annee -eq 2010) { return '/q /norestart' }
            return '/install /quiet /norestart'
        }
        'wamp' { if ($e.x64) { return '/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP- /DIR="C:\wamp64"' } else { return '/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP- /DIR="C:\wamp"' } }
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
    try { $fichier = Obtenir-Element $e } catch {
        Log "  → $($e.nom) : extraction impossible ($($_.Exception.Message))"
        return @{ ok = $false; texte = "extraction impossible : $($_.Exception.Message)"; redemarrage = $false }
    }
    for ($essai = 1; $essai -le 20; $essai++) {
        Log "Installation : $($e.nom) $arguments"
        $debut = Get-Date
        try {
            # Pas de « -Wait » : sous Windows PowerShell il attend aussi TOUS les processus lancés par
            # l'installateur (assistants de Windows Installer, mises à jour…), qui restent parfois
            # ouverts 5 à 10 minutes après la fin réelle. On attend uniquement l'installateur lui-même.
            if ($fichier -like '*.msi') {
                $p = Start-Process -FilePath 'msiexec.exe' -ArgumentList "/i `"$fichier`" $arguments" -PassThru
            } else {
                $p = Start-Process -FilePath $fichier -ArgumentList $arguments -PassThru
            }
            $null = $p.Handle   # garde l'accès au code de retour une fois le programme terminé
        } catch {
            # Programme refusé par Windows avant même de démarrer
            $ex = $_.Exception; while ($ex -and -not ($ex -is [ComponentModel.Win32Exception])) { $ex = $ex.InnerException }
            $code = if ($ex) { $ex.NativeErrorCode } else { $null }
            $txt = switch ($code) {
                1260 { 'bloqué par une stratégie de Windows (contrôle des applications)' }
                4551 { 'bloqué par le Contrôle intelligent des applications (ou Windows Defender Application Control)' }
                225 { 'bloqué par l''antivirus (fichier signalé comme dangereux)' }
                1223 { 'lancement annulé' }
                default { $_.Exception.Message }
            }
            Log "  → $($e.nom) : $txt"
            return @{ ok = $false; texte = $txt; redemarrage = $false }
        }
        # Attente du seul installateur (30 minutes au plus : WampServer est le plus long)
        while (-not $p.WaitForExit(1000)) {
            if (((Get-Date) - $debut).TotalMinutes -ge 30) { break }
        }
        if (-not $p.HasExited) {
            Log "  → $($e.nom) : toujours en cours après 30 minutes, l'installation continue avec le composant suivant"
            return @{ ok = $false; texte = 'toujours en cours après 30 minutes (une fenêtre attend peut-être une réponse)'; redemarrage = $false }
        }
        $c = $null; try { $c = $p.ExitCode } catch {}
        Log ("  durée : {0:N0} s" -f ((Get-Date) - $debut).TotalSeconds)
        # 1618 : une autre installation est en cours (Windows Update…) → on attend et on recommence
        if ($c -eq 1618 -and $essai -lt 20) { Log '  Windows Installer occupé par une autre installation : nouvel essai dans 15 secondes'; Start-Sleep -Seconds 15; continue }
        # 0 réussi · 3010/1641 redémarrage demandé · 1638/5100 version plus récente déjà présente
        $ok = $c -in @(0, 3010, 1641, 1638, 5100, 1603) -or $null -eq $c
        $txt = switch ($c) { 0 { 'installé' } 3010 { 'installé (redémarrage conseillé)' } 1641 { 'installé (redémarrage lancé)' } 1638 { 'déjà installé (version plus récente présente)' } 5100 { 'déjà installé (version plus récente présente)' } 1603 { 'déjà présent ou refusé par Windows (code 1603)' } 1618 { 'Windows Installer toujours occupé (code 1618) : redémarrez Windows puis relancez' } default { "code $c" } }
        Log "  → $($e.nom) : $txt"
        return @{ ok = $ok; texte = $txt; redemarrage = ($c -in @(3010, 1641)) }
    }
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

# Pare-feu : New-NetFirewallRule (Windows 8+), sinon netsh (Windows 7)
function Autoriser-Apache([string]$wampDir) {
    $exes = @(Get-ChildItem (Join-Path $wampDir 'bin\apache') -Recurse -Filter 'httpd.exe' -ErrorAction SilentlyContinue)
    if (Commande 'New-NetFirewallRule') {
        Get-NetFirewallRule -DisplayName 'POS Marwa - Apache*' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
        foreach ($exe in $exes) { New-NetFirewallRule -DisplayName "POS Marwa - Apache ($($exe.Directory.Parent.Name))" -Direction Inbound -Program $exe.FullName -Action Allow -Profile Private, Domain | Out-Null }
    } else {
        & netsh.exe advfirewall firewall delete rule name=POS-Marwa-Apache 2>&1 | Out-Null
        foreach ($exe in $exes) { & netsh.exe advfirewall firewall add rule name=POS-Marwa-Apache dir=in action=allow "program=$($exe.FullName)" enable=yes profile=private,domain | Out-Null }
    }
    return $exes.Count
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
        # 0. Préparation de Windows : corrige ce qui peut bloquer, sans toucher à la sécurité
        Etape 'prep' 'Préparation de Windows' 'encours' 'recherche de ce qui peut bloquer l''installation'
        # Chaque contrôle est facultatif : un échec est noté dans le journal mais n'arrête jamais l'installation.
        $prep = @()
        try { $n = Debloquer $Paquet; if ($n) { $prep += "$n fichier(s) du paquet débloqué(s)" } } catch { Log "Avertissement (déblocage) : $($_.Exception.Message)" }
        try {
            $msi = Get-Wmi Win32_Service "Name='msiserver'" | Select-Object -First 1
            if ($msi -and $msi.StartMode -eq 'Disabled') { Set-Service -Name msiserver -StartupType Manual; $prep += 'service Windows Installer réactivé' }
        } catch { Log "Avertissement (Windows Installer) : $($_.Exception.Message)" }
        try {
            if ($Options.liberer -and @(Get-ConflitsReserves | Where-Object { -not $_.admin })) {
                & net.exe stop winnat 2>&1 | Out-Null; & net.exe start winnat 2>&1 | Out-Null
                $prep += 'réservation de ports Hyper-V / WSL libérée (service NAT redémarré)'
            }
        } catch { Log "Avertissement (ports réservés) : $($_.Exception.Message)" }
        try {
            # Pas d'attente ici : si une autre installation occupe Windows Installer, chaque composant
            # concerné est réessayé automatiquement (code 1618) au moment de son installation.
            if (Test-MsiOccupe) { $prep += 'Windows Installer occupé par une autre installation : les composants concernés seront réessayés' }
            if (Test-RedemarrageEnAttente) { $prep += 'un redémarrage de Windows est en attente (conseillé avant l''installation)' }
        } catch { Log "Avertissement (Windows Installer occupé) : $($_.Exception.Message)" }
        $p = Analyser-Paquet $Paquet
        $wampDir = Find-Wamp
        Etape 'prep' 'Préparation de Windows' 'ok' $(if ($prep) { $prep -join ' · ' } else { 'aucun blocage' })

        # 1. Ports
        if ($Options.liberer) {
            Etape 'ports' 'Libérer les ports de WampServer' 'encours'
            $m = @(); foreach ($port in 80, 3306, 3307, 3308) { $i = Get-InfoPort $port; if (-not $i.libre -and -not $i.wamp) { $m += Liberer-Port $port } }
            Etape 'ports' 'Libérer les ports de WampServer' 'ok' $(if ($m) { $m -join ' ' } else { 'ports 80, 3306, 3307, 3308 libres' })
        }

        # 2. Visual C++ (indispensables à Apache, PHP et MySQL)
        $x64 = [Environment]::Is64BitOperatingSystem
        $vcs = @($p.elements | Where-Object { $_.type -eq 'vcredist' -and ($_.x64 -eq $false -or $x64) })
        $n = 0; $refus = @(); $installes = 0; $ignores = 0
        foreach ($v in $vcs) {
            $n++
            Etape 'vc' 'Composants Visual C++ 2008 à 2022' 'encours' "$n / $($vcs.Count) : $($v.famille) ($($v.nom))"
            # Déjà installé dans une version égale ou plus récente : rien à faire (gain de temps)
            $deja = $null
            if ($v.installe) {
                try {
                    $pv = Get-VersionFichier (Obtenir-Element $v)
                    $iv = Get-VcInstalle (Get-MajeurVc $v.annee) $v.x64
                    if ($pv -and $iv -and $iv -ge $pv) { $deja = "v$iv" }
                } catch { Log "  Version de $($v.nom) non vérifiable : installation" }
            }
            if ($deja) { $ignores++; Log "  → $($v.nom) : déjà installé ($deja), ignoré"; continue }
            $r = Lancer-Installateur $v
            if ($r.redemarrage) { $res.redemarrage = $true }
            if ($r.ok) { $installes++ } else { $refus += "$($v.nom) : $($r.texte)" }
        }
        $bilan = "$installes installé(s), $ignores déjà à jour (ignoré(s))"
        Etape 'vc' 'Composants Visual C++ 2008 à 2022' $(if (-not $vcs) { 'absent' } elseif ($refus) { 'erreur' } else { 'ok' }) $(if (-not $vcs) { 'aucun vcredist dans le paquet' } elseif ($refus) { "$bilan · " + ($refus -join ' · ') } else { $bilan })

        # 3. WampServer (version 64 bits sur Windows 64 bits, sinon 32 bits)
        $ws = @($p.elements | Where-Object { $_.type -eq 'wamp' })
        $w = if ($x64) { @($ws | Where-Object { $_.x64 }) + @($ws | Where-Object { -not $_.x64 }) | Select-Object -First 1 } else { $ws | Where-Object { -not $_.x64 } | Select-Object -First 1 }
        if ($wampDir) {
            Etape 'wamp' 'WampServer' 'ok' "déjà installé dans $wampDir"
        } elseif ($w) {
            Etape 'wamp' 'WampServer' 'encours' "$($w.nom) (plusieurs minutes)"
            $r = Lancer-Installateur $w
            $wampDir = Find-Wamp
            if (-not $wampDir) { throw "WampServer ne s'est pas installé ($($r.texte)). Lancez $($w.nom) à la main puis relancez l'installateur." }
            Etape 'wamp' 'WampServer' 'ok' "installé dans $wampDir"
        } elseif ($ws -and -not $x64) {
            throw 'Ce Windows est en 32 bits et le paquet ne contient que WampServer 64 bits : ajoutez la version 32 bits (wampserver3.x.x_x86.exe) au paquet.'
        } else { throw 'WampServer absent du paquet et non installé sur ce PC.' }

        # 4. Google Chrome
        $c = $p.elements | Where-Object { $_.type -eq 'chrome' } | Select-Object -First 1
        if (Find-Chrome) { Etape 'chrome' 'Google Chrome' 'ok' 'déjà installé' }
        elseif ($c -and $Options.chrome) { Etape 'chrome' 'Google Chrome' 'encours' $c.nom; $r = Lancer-Installateur $c; Etape 'chrome' 'Google Chrome' $(if (Find-Chrome) { 'ok' } else { 'erreur' }) $r.texte }
        else { Etape 'chrome' 'Google Chrome' 'absent' 'non fourni : Microsoft Edge (ou le navigateur par défaut) sera utilisé' }

        # 5. Démarrage de WampServer, services en démarrage automatique
        Etape 'serveur' 'Serveur Apache et MySQL / MariaDB' 'encours' 'démarrage de WampServer'
        $httpd = Get-ChildItem (Join-Path $wampDir 'bin\apache') -Directory -ErrorAction SilentlyContinue | ForEach-Object { Join-Path $_.FullName 'conf\httpd.conf' } | Where-Object { Test-Path $_ }
        foreach ($conf in $httpd) {
            $t = [IO.File]::ReadAllText($conf)
            if ($t -match '(?m)^\s*#\s*LoadModule rewrite_module') { [IO.File]::WriteAllText($conf, ($t -replace '(?m)^\s*#\s*(LoadModule rewrite_module)', '$1')); Log "mod_rewrite activé dans $conf" }
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
        if (-not $etatSvc) { throw 'Les services de WampServer ne sont pas apparus. Ouvrez WampServer (icône près de l''horloge) et vérifiez son message.' }
        Etape 'serveur' 'Serveur Apache et MySQL / MariaDB' 'ok' "$etatSvc – démarrage automatique avec Windows"

        # 6. Démarrage avec Windows : icône Wamp à l'ouverture de session (les services démarrent seuls)
        if ($Options.demarrage) {
            & schtasks.exe /Create /F /TN 'POS Marwa - WampServer' /SC ONLOGON /RL HIGHEST /DELAY 0000:20 /TR "`"$(Join-Path $wampDir 'wampmanager.exe')`"" 2>&1 | Out-Null
            if ($LASTEXITCODE -ne 0) {
                # Windows 7 : l'option /DELAY n'existe pas pour ONLOGON sur certaines éditions
                & schtasks.exe /Create /F /TN 'POS Marwa - WampServer' /SC ONLOGON /RL HIGHEST /TR "`"$(Join-Path $wampDir 'wampmanager.exe')`"" 2>&1 | Out-Null
            }
            Etape 'auto' 'Démarrage automatique' 'ok' 'services Apache/MySQL automatiques + tâche planifiée « POS Marwa - WampServer » à l''ouverture de session'
        }

        # 7. Pare-feu : Apache joignable depuis les téléphones et tablettes du réseau local (pas depuis Internet)
        if ($Options.parefeu) {
            $nb = Autoriser-Apache $wampDir
            Etape 'parefeu' 'Pare-feu Windows' 'ok' "Apache autorisé sur le réseau privé ($nb version(s)) ; MySQL reste fermé au réseau"
        }

        # 8. POS Marwa
        $app = $p.elements | Where-Object { $_.type -eq 'posmarwa' } | Select-Object -First 1
        $cible = Join-Path $wampDir 'www\pos-marwa'
        if (Test-Path (Join-Path $cible '.env')) {
            Etape 'app' 'POS Marwa' 'ok' "déjà installé dans $cible : données conservées, utilisez la mise à jour intégrée pour changer de version"
            $res.url = 'http://localhost/pos-marwa/'
        } elseif ($app) {
            Etape 'app' 'POS Marwa' 'encours' "copie dans $cible"
            $src = Obtenir-Element $app
            if ($src -like '*.zip') {
                $tmp = Join-Path $Racine 'pos-marwa-tmp'; Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
                Extraire-Zip $src $tmp
                $idx = Get-ChildItem -LiteralPath $tmp -Recurse -Filter 'index.php' | Where-Object { $_.Directory.Name -eq 'public' -and (Test-Path (Join-Path $_.Directory.Parent.FullName 'app\bootstrap.php')) } | Select-Object -First 1
                if (-not $idx) { throw "Le zip $($app.nom) ne contient pas POS Marwa (dossiers public\ et app\ introuvables)." }
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

        # 9. Raccourci « POS Marwa » (fenêtre dédiée Chrome ou Edge, sinon navigateur par défaut)
        if ($res.url -and $Options.raccourci) {
            $nav = Find-Chrome; if (-not $nav) { $nav = Find-Edge }
            $sh = New-Object -ComObject WScript.Shell
            $faits = @(); $echecs = @()
            foreach ($dir in @([Environment]::GetFolderPath('CommonDesktopDirectory'), [Environment]::GetFolderPath('CommonPrograms'))) {
                try {
                    $lnk = $sh.CreateShortcut((Join-Path $dir 'POS Marwa.lnk'))
                    if ($nav) { $lnk.TargetPath = $nav; $lnk.Arguments = "--app=$($res.url) --autoplay-policy=no-user-gesture-required" }
                    else { $lnk.TargetPath = "$env:WINDIR\explorer.exe"; $lnk.Arguments = $res.url }
                    $ico = Join-Path $cible 'windows\pos-marwa.ico'; if (Test-Path $ico) { $lnk.IconLocation = $ico }
                    $lnk.Description = 'POS Marwa'; $lnk.Save(); $faits += $dir
                } catch { $echecs += $dir }
            }
            Etape 'raccourci' 'Raccourci POS Marwa' $(if ($faits) { 'ok' } else { 'erreur' }) $(if ($echecs) { "créé dans : $($faits -join ', ') ; refusé dans : $($echecs -join ', ') (accès contrôlé aux dossiers ?)" } else { 'Bureau et menu Démarrer (tous les utilisateurs)' })
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
        $ou = ''; try { $ou = " [ligne $($_.InvocationInfo.ScriptLineNumber) : $(([string]$_.InvocationInfo.Line).Trim())]" } catch {}
        Log "ERREUR : $($_.Exception.Message)$ou"
    } finally {
        $Etat.resultat = $res; $Etat.enCours = $false; $Etat.fini = $true
    }
}

# ------------------------------------------------------------------ serveur web local (interface)
# Plusieurs ports possibles : Hyper-V / WSL / Docker réservent parfois des plages entières sur Windows 10 / 11.
$http = $null; $Url = $null
foreach ($Port in @(47811, 47812, 38211, 28211, 18211, 52811, 61811) + @(1..5 | ForEach-Object { Get-Random -Minimum 20000 -Maximum 40000 })) {
    $h = New-Object System.Net.HttpListener
    $h.Prefixes.Add("http://127.0.0.1:$Port/")
    try { $h.Start(); $http = $h; $Url = "http://127.0.0.1:$Port/"; break } catch { try { $h.Close() } catch {} }
}
if (-not $http) {
    Write-Host '  Impossible d''ouvrir l''interface : aucun port local disponible (l''installateur est peut-être déjà ouvert).' -ForegroundColor Red
    Log 'Aucun port local disponible pour l''interface'
    Start-Sleep -Seconds 20
    exit 1
}
$Jeton = New-Hex 16
$Adresse = "$Url`?t=$Jeton"
Log "Installateur démarré ($Url) – PowerShell $($PSVersionTable.PSVersion)"

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

# Paquet trouvé automatiquement à côté de l'installateur (ou de l'installateur .exe)
function Paquet-Proche {
    $dossiers = @()
    if ($Depuis) { $dossiers += $Depuis; $dossiers += (Split-Path $Depuis -Parent) }
    $dossiers += $Ici; $dossiers += (Split-Path $Ici -Parent)
    foreach ($d in $dossiers) {
        if (-not $d -or -not (Test-Path -LiteralPath $d)) { continue }
        $z = Get-ChildItem -LiteralPath $d -Filter '*.zip' -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -match 'install|paquet|pos' -and $_.Name -notmatch '^pos[-_ ]?(marwa|wan)-\d' -and $_.Name -notmatch '^installateur' } | Select-Object -First 1
        if ($z) { return $z.FullName }
        if (Get-ChildItem -LiteralPath $d -Filter 'wampserver*.exe' -File -ErrorAction SilentlyContinue) { return $d }
    }
    return $null
}

# Fenêtre de l'installateur : Chrome ou Edge en mode application, avec un profil séparé
# (un navigateur lancé en administrateur ne peut pas rejoindre la fenêtre déjà ouverte par l'utilisateur).
function Ouvrir-Interface([string]$adresse) {
    $nav = Find-Chrome; if (-not $nav) { $nav = Find-Edge }
    if ($nav) {
        try {
            Start-Process -FilePath $nav -ArgumentList @("--app=$adresse", "--user-data-dir=`"$(Join-Path $Racine 'navigateur')`"", '--no-first-run', '--no-default-browser-check', '--window-size=1180,860')
            return
        } catch { Log "Ouverture de $nav impossible : $($_.Exception.Message)" }
    }
    Ouvrir-Secours $adresse
}

# Secours : navigateur par défaut, ouvert par l'Explorateur (donc sans droits administrateur)
function Ouvrir-Secours([string]$adresse) {
    try { Start-Process -FilePath "$env:WINDIR\explorer.exe" -ArgumentList "`"$adresse`"" } catch { try { Start-Process $adresse } catch {} }
}

$paquet = Paquet-Proche
$rs = $null; $ps = $null

Write-Host "  Assistant : $Adresse" -ForegroundColor Cyan
Write-Host '  (si aucune fenêtre ne s''ouvre, copiez cette adresse dans Google Chrome, Microsoft Edge ou Firefox)' -ForegroundColor Gray
Write-Host "  Journal : $Journal" -ForegroundColor Gray
if (-not $SansFenetre) { Ouvrir-Interface $Adresse }

$fini = $false
$lance = Get-Date; $derniere = $lance; $premiere = $null; $secours = $false
while ($http.IsListening -and -not $fini) {
    $ar = $http.BeginGetContext($null, $null)
    while (-not $ar.AsyncWaitHandle.WaitOne(1000)) {
        $maintenant = Get-Date
        # La fenêtre ne s'est pas connectée : on ouvre le navigateur par défaut
        if (-not $premiere -and -not $secours -and -not $SansFenetre -and ($maintenant - $lance).TotalSeconds -gt 25) {
            $secours = $true; Log 'Fenêtre de l''installateur non connectée : ouverture du navigateur par défaut'
            Write-Host '  La fenêtre ne s''est pas ouverte : essai avec le navigateur par défaut…' -ForegroundColor Yellow
            Ouvrir-Secours $Adresse
        }
        # Fenêtre fermée (plus de signe de vie) et aucune installation en cours : l'installateur s'arrête
        if (-not $Etat.enCours) {
            if ($premiere -and ($maintenant - $derniere).TotalMinutes -gt 5) { $fini = $true; break }
            if (-not $premiere -and ($maintenant - $lance).TotalMinutes -gt 20) { $fini = $true; break }
        }
    }
    if ($fini) { break }
    $ctx = $http.EndGetContext($ar)
    $derniere = Get-Date; if (-not $premiere) { $premiere = $derniere }
    try {
        $chemin = $ctx.Request.Url.AbsolutePath
        if ($chemin -eq '/') {
            Repondre $ctx ([IO.File]::ReadAllBytes((Join-Path $Ici 'interface.html'))) 200 'text/html; charset=utf-8'; continue
        }
        # Toute action exige le jeton de la fenêtre ouverte par l'installateur
        if ($ctx.Request.Headers['X-Jeton'] -ne $Jeton) { Repondre $ctx @{ ok = $false; erreur = 'Accès refusé' } 403; continue }
        switch ($chemin) {
            '/api/ping' { Repondre $ctx @{ ok = $true } }
            '/api/systeme' { Repondre $ctx @{ ok = $true; info = (Get-InfoSysteme); paquet = $paquet } }
            '/api/diagnostic' {
                try { Repondre $ctx @{ ok = $true; elements = @(Get-Blocages $paquet $Ici) } }
                catch { Log "Erreur (diagnostic) : $($_.Exception.Message) [ligne $($_.InvocationInfo.ScriptLineNumber)]"; Repondre $ctx @{ ok = $false; erreur = "Diagnostic incomplet : $($_.Exception.Message)" } }
            }
            '/api/corriger' {
                $b = Lire-Json $ctx
                try { Repondre $ctx @{ ok = $true; message = (Corriger-Blocage ([string]$b.action) $paquet $Ici) } } catch { Repondre $ctx @{ ok = $false; erreur = $_.Exception.Message } }
            }
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
                try { $a = Analyser-Paquet $paquet; Repondre $ctx @{ ok = $true; paquet = $paquet; analyse = $a } } catch { Log "Erreur (analyse) : $($_.Exception.Message) [ligne $($_.InvocationInfo.ScriptLineNumber)]"; Repondre $ctx @{ ok = $false; erreur = $_.Exception.Message } }
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
                # Ouvert par l'Explorateur : POS Marwa s'ouvre avec les droits normaux de l'utilisateur
                $lnk = Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) 'POS Marwa.lnk'
                if (-not (Test-Path $lnk)) { $lnk = Join-Path ([Environment]::GetFolderPath('CommonPrograms')) 'POS Marwa.lnk' }
                if (Test-Path $lnk) { Start-Process -FilePath "$env:WINDIR\explorer.exe" -ArgumentList "`"$lnk`"" } else { Ouvrir-Secours 'http://localhost/pos-marwa/' }
                Repondre $ctx @{ ok = $true }
            }
            '/api/journal' { Start-Process notepad.exe -ArgumentList "`"$Journal`""; Repondre $ctx @{ ok = $true } }
            '/api/redemarrer' { Repondre $ctx @{ ok = $true }; & shutdown.exe /r /t 15 /c 'Redémarrage demandé par l''installateur POS Marwa' }
            '/api/quitter' { Repondre $ctx @{ ok = $true }; $fini = $true }
            default { Repondre $ctx @{ ok = $false; erreur = 'Inconnu' } 404 }
        }
    } catch {
        $ou = ''; try { $ou = " [ligne $($_.InvocationInfo.ScriptLineNumber) : $(([string]$_.InvocationInfo.Line).Trim())]" } catch {}
        Log "Erreur ($chemin) : $($_.Exception.Message)$ou"
        try { Repondre $ctx @{ ok = $false; erreur = $_.Exception.Message } 500 } catch {}
    }
}
try { $http.Stop(); $http.Close() } catch {}
Log 'Installateur fermé'
exit 0
