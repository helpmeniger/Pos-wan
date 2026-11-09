; Installateur POS Marwa (.exe) – à compiler avec NSIS 3 : makensis installateur.nsi
; Lance l'assistant installateur.ps1 / interface.html avec les droits administrateur, sur
; Windows 7 SP1, 8.1, 10 et 11 (32 ou 64 bits, ARM64 en émulation).
; Les fichiers sont extraits par l'exécutable : ils ne portent pas la marque « téléchargé depuis
; Internet », donc ni SmartScreen ni la stratégie RemoteSigned ne bloquent le script.
; Ne désactive jamais l'antivirus, SmartScreen, l'UAC ni le pare-feu.

Unicode true
ManifestSupportedOS all
ManifestDPIAware true
RequestExecutionLevel admin
SetCompressor /SOLID lzma

!include LogicLib.nsh
!include x64.nsh
!include WinVer.nsh

!define VERSION "1.1.0"
!define JOURNAL "C:\POS-Marwa-Installation\installation.log"

Name "Installateur POS Marwa"
Caption "Installateur POS Marwa"
OutFile "Installateur-POS-Marwa.exe"
Icon "..\pos-marwa.ico"
BrandingText "POS Marwa"
LoadLanguageFile "${NSISDIR}\Contrib\Language files\French.nlf"
VIProductVersion "${VERSION}.0"
VIAddVersionKey /LANG=${LANG_FRENCH} "ProductName" "POS Marwa"
VIAddVersionKey /LANG=${LANG_FRENCH} "FileDescription" "Installateur POS Marwa (WampServer, Visual C++, Chrome, POS Marwa)"
VIAddVersionKey /LANG=${LANG_FRENCH} "CompanyName" "Mahamadou Hamidou"
VIAddVersionKey /LANG=${LANG_FRENCH} "LegalCopyright" "© Mahamadou Hamidou"
VIAddVersionKey /LANG=${LANG_FRENCH} "FileVersion" "${VERSION}"
VIAddVersionKey /LANG=${LANG_FRENCH} "ProductVersion" "${VERSION}"

Section
SectionEnd

Function .onInit
  ${IfNot} ${AtLeastWin7}
    MessageBox MB_ICONSTOP "Windows 7 SP1 au minimum est nécessaire pour POS Marwa et WampServer."
    Abort
  ${EndIf}

  UserInfo::GetAccountType
  Pop $0
  ${If} $0 != "Admin"
    MessageBox MB_ICONSTOP "Les droits administrateur sont nécessaires.$\r$\n$\r$\nRelancez l'installateur et répondez « Oui » à la demande de Windows (sur un compte standard, saisissez le mot de passe d'un administrateur)."
    Abort
  ${EndIf}

  ; PowerShell 64 bits sur Windows 64 bits (cet exécutable est en 32 bits)
  ${If} ${RunningX64}
    ${DisableX64FSRedirection}
  ${EndIf}
  StrCpy $R0 "$SYSDIR\WindowsPowerShell\v1.0\powershell.exe"
  ${IfNot} ${FileExists} "$R0"
    MessageBox MB_ICONSTOP "Windows PowerShell est introuvable.$\r$\n$\r$\nSur Windows 7, installez .NET Framework 4.8 puis « Windows Management Framework 5.1 » (gratuits, Microsoft), redémarrez et relancez l'installateur."
    Abort
  ${EndIf}

  ; Contrôle : version de PowerShell, mode restreint, stratégie de groupe
  nsExec::ExecToStack `"$R0" -NoProfile -ExecutionPolicy Bypass -Command "$$v=$$PSVersionTable.PSVersion.Major; if($$v -lt 3){'PS'+$$v; exit}; if($$ExecutionContext.SessionState.LanguageMode -ne 'FullLanguage'){'CLM'; exit}; $$b=@(); foreach($$s in 'MachinePolicy','UserPolicy'){$$p=[string](Get-ExecutionPolicy -Scope $$s); if(@('AllSigned','Restricted','Default') -contains $$p){$$b+=$$s+'='+$$p}}; if($$b.Count){'GPO:'+($$b -join ',')}else{'OK'}"`
  Pop $1
  Pop $2
  StrCpy $3 $2 2
  ${If} $3 == "OK"
    ; tout va bien
  ${ElseIf} $3 == "CL"
    MessageBox MB_ICONSTOP "PowerShell fonctionne en mode restreint sur ce PC (AppLocker, Device Guard / WDAC ou Contrôle intelligent des applications).$\r$\n$\r$\nCette protection empêche l'installateur de fonctionner : elle doit être levée par l'administrateur du PC. L'installateur ne la contourne pas."
    Abort
  ${ElseIf} $3 == "GP"
    MessageBox MB_ICONSTOP "Une stratégie de groupe interdit les scripts PowerShell ($2).$\r$\n$\r$\nAdministrateur : gpedit.msc > Configuration ordinateur > Modèles d'administration > Composants Windows > Windows PowerShell > « Activer l'exécution des scripts » : Non configuré (ou scripts locaux autorisés). Sur un PC d'entreprise, demandez-le au service informatique."
    Abort
  ${Else}
    MessageBox MB_ICONSTOP "Windows PowerShell 3.0 au minimum est nécessaire (trouvé : $2).$\r$\n$\r$\nWindows 7 : installez .NET Framework 4.8 puis « Windows Management Framework 5.1 » (gratuits, Microsoft), redémarrez et relancez l'installateur."
    Abort
  ${EndIf}

  InitPluginsDir
  SetOutPath "$PLUGINSDIR\installateur"
  File "installateur.ps1"
  File "interface.html"

  ; L'assistant cherche le paquet « pos-wan install » à côté de cet exécutable
  ExecWait `"$R0" -NoProfile -ExecutionPolicy Bypass -STA -File "$PLUGINSDIR\installateur\installateur.ps1" -Depuis "$EXEDIR\."` $4
  ${If} $4 != 0
    MessageBox MB_ICONEXCLAMATION "L'installateur s'est arrêté (code $4).$\r$\n$\r$\nJournal : ${JOURNAL}"
  ${EndIf}
  SetOutPath "$TEMP"
  Abort
FunctionEnd
