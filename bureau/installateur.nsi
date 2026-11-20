; POS Marwa Bureau — installateur Windows (NSIS 3) : makensis -DVERSION=x.y.z -DSORTIE=chemin\setup.exe installateur.nsi
; Construit par bureau/construire.sh, qui prépare le dossier « POS Marwa » (logiciel complet) et « redist » (Visual C++).
;
; - Installe tout dans un seul dossier (C:\POS Marwa par défaut) : programme, PHP, MariaDB, application, données.
; - Mise à jour : les données (donnees\, app\.env, app\storage\, app\public\uploads\, pos-marwa.ini) ne sont jamais
;   remplacées ; POS Marwa est arrêté proprement avant la copie.
; - Désinstallation : les données sont conservées, sauf demande explicite confirmée deux fois.
; - Ne désactive jamais l'antivirus, SmartScreen, l'UAC ni le pare-feu.

Unicode true
ManifestSupportedOS all
ManifestDPIAware true
RequestExecutionLevel admin
SetCompressor /SOLID lzma
SetCompressorDictSize 64

!ifndef VERSION
  !define VERSION "0.0.0"
!endif
!ifndef SORTIE
  !define SORTIE "POS-Marwa-Bureau-Setup.exe"
!endif
!define PRODUIT "POS Marwa"
!define CLE_DESINSTALL "Software\Microsoft\Windows\CurrentVersion\Uninstall\POSMarwaBureau"

!include MUI2.nsh
!include LogicLib.nsh
!include x64.nsh
!include WinVer.nsh
!include FileFunc.nsh

Name "${PRODUIT}"
Caption "Installation de ${PRODUIT} ${VERSION}"
OutFile "${SORTIE}"
InstallDir "C:\POS Marwa"
InstallDirRegKey HKLM "Software\POSMarwaBureau" "Dossier"
BrandingText "POS Marwa ${VERSION} — gestion commerciale"
VIProductVersion "${VERSION}.0"
VIAddVersionKey /LANG=1036 "ProductName" "POS Marwa Bureau"
VIAddVersionKey /LANG=1036 "FileDescription" "Installation de POS Marwa (gestion commerciale, sans serveur à installer)"
VIAddVersionKey /LANG=1036 "CompanyName" "Mahamadou Hamidou (Mohamed)"
VIAddVersionKey /LANG=1036 "LegalCopyright" "© 2019-${ANNEE} Mahamadou Hamidou (Mohamed)"
VIAddVersionKey /LANG=1036 "FileVersion" "${VERSION}"
VIAddVersionKey /LANG=1036 "ProductVersion" "${VERSION}"

!define MUI_ICON "pos-marwa.ico"
!define MUI_UNICON "pos-marwa.ico"
!define MUI_ABORTWARNING
!define MUI_WELCOMEPAGE_TITLE "Installation de POS Marwa ${VERSION}"
!define MUI_WELCOMEPAGE_TEXT "Ce programme installe POS Marwa, logiciel de gestion commerciale (caisse, articles, stock, achats, clients, rapports), sur ce PC.$\r$\n$\r$\nAucun autre logiciel n'est nécessaire : la base de données et le serveur sont fournis et fonctionnent dans le dossier de POS Marwa.$\r$\n$\r$\nWindows 10 ou 11 (64 bits) est nécessaire."
!define MUI_FINISHPAGE_RUN "$INSTDIR\POS Marwa.exe"
!define MUI_FINISHPAGE_RUN_TEXT "Lancer POS Marwa"
!define MUI_FINISHPAGE_SHOWREADME "$INSTDIR\LISEZ-MOI.txt"
!define MUI_FINISHPAGE_SHOWREADME_TEXT "Lire le mode d'emploi"
!define MUI_FINISHPAGE_SHOWREADME_NOTCHECKED

!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_LICENSE "licence.txt"
!define MUI_PAGE_CUSTOMFUNCTION_LEAVE VerifierDossier
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_COMPONENTS
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "French"

Var MiseAJour

Function .onInit
  ${IfNot} ${RunningX64}
    MessageBox MB_ICONSTOP "POS Marwa nécessite Windows 64 bits."
    Abort
  ${EndIf}
  ${IfNot} ${AtLeastWin10}
    MessageBox MB_ICONSTOP "POS Marwa nécessite Windows 10 ou Windows 11."
    Abort
  ${EndIf}
  SetRegView 64
  ReadRegStr $0 HKLM "Software\POSMarwaBureau" "Dossier"
  ${If} $0 != ""
    StrCpy $INSTDIR $0
  ${EndIf}
FunctionEnd

; POS Marwa écrit dans son dossier : pas dans « Program Files » (protégé par Windows), pas sur un lecteur réseau
Function VerifierDossier
  StrCpy $1 $INSTDIR 2
  ${If} $1 == "\\"
    MessageBox MB_ICONEXCLAMATION "Choisissez un dossier sur un disque de ce PC (pas un dossier réseau)."
    Abort
  ${EndIf}
  StrLen $2 "$PROGRAMFILES64"
  StrCpy $3 $INSTDIR $2
  ${If} $3 == "$PROGRAMFILES64"
    MessageBox MB_ICONEXCLAMATION "POS Marwa ne doit pas être installé dans « Program Files » : il garde ses données dans son propre dossier, que Windows y protège en écriture.$\r$\n$\r$\nDossier conseillé : C:\POS Marwa"
    Abort
  ${EndIf}
  StrLen $2 "$PROGRAMFILES32"
  StrCpy $3 $INSTDIR $2
  ${If} $3 == "$PROGRAMFILES32"
    MessageBox MB_ICONEXCLAMATION "POS Marwa ne doit pas être installé dans « Program Files (x86) ».$\r$\n$\r$\nDossier conseillé : C:\POS Marwa"
    Abort
  ${EndIf}
FunctionEnd

Section "POS Marwa (obligatoire)" SecMain
  SectionIn RO
  SetRegView 64
  StrCpy $MiseAJour "0"
  ${If} ${FileExists} "$INSTDIR\POS Marwa.exe"
    StrCpy $MiseAJour "1"
    DetailPrint "POS Marwa est déjà installé : mise à jour (vos données sont conservées)."
    ; Arrêt propre de POS Marwa (fenêtre, serveurs, base) avant de remplacer les fichiers
    nsExec::Exec '"$INSTDIR\POS Marwa.exe" --arreter'
    Sleep 4000
  ${EndIf}

  ; Visual C++ 2015-2022 (64 bits), seulement s'il manque
  ReadRegDWORD $0 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Installed"
  ReadRegDWORD $1 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Major"
  ${If} $0 != 1
  ${OrIf} $1 < 14
    DetailPrint "Installation de Microsoft Visual C++ 2015-2022…"
    SetOutPath "$PLUGINSDIR"
    File "redist\vc_redist.x64.exe"
    ExecWait '"$PLUGINSDIR\vc_redist.x64.exe" /install /quiet /norestart' $2
    DetailPrint "Visual C++ : code $2 (0 = installé, 1638 = version plus récente déjà présente, 3010 = redémarrage conseillé)"
  ${EndIf}

  DetailPrint "Copie de POS Marwa…"
  SetOutPath "$INSTDIR"
  SetOverwrite on
  File "POS Marwa\POS Marwa.exe"
  File "POS Marwa\LISEZ-MOI.txt"
  File "POS Marwa\LICENCES-TIERS.txt"
  File "POS Marwa\pos-marwa.ico"
  ; Réglages : jamais remplacés lors d'une mise à jour
  SetOverwrite off
  File "POS Marwa\pos-marwa.ini"
  SetOverwrite on
  SetOutPath "$INSTDIR\php"
  File /r "POS Marwa\php\*.*"
  SetOutPath "$INSTDIR\mariadb"
  File /r "POS Marwa\mariadb\*.*"
  ; Node.js (pont MARWA SECEF CONNECT, démarré par POS Marwa.exe)
  SetOutPath "$INSTDIR\node"
  File /r "POS Marwa\node\*.*"
  ; Application : le code est remplacé ; .env, storage\ et public\uploads\ (données) ne sont pas dans le paquet
  SetOutPath "$INSTDIR\app"
  File /r "POS Marwa\app\*.*"
  CreateDirectory "$INSTDIR\donnees"

  ; POS Marwa écrit dans son dossier (base, sauvegardes, journaux) : droits de modification aux utilisateurs du PC
  nsExec::ExecToLog 'icacls "$INSTDIR" /grant *S-1-5-32-545:(OI)(CI)M /T /C /Q'

  ; Raccourcis
  SetOutPath "$INSTDIR"
  CreateDirectory "$SMPROGRAMS\POS Marwa"
  CreateShortcut "$SMPROGRAMS\POS Marwa\POS Marwa.lnk" "$INSTDIR\POS Marwa.exe" "" "$INSTDIR\pos-marwa.ico"
  CreateShortcut "$SMPROGRAMS\POS Marwa\Arrêter POS Marwa.lnk" "$INSTDIR\POS Marwa.exe" "--arreter" "$INSTDIR\pos-marwa.ico"
  CreateShortcut "$SMPROGRAMS\POS Marwa\Dossier de POS Marwa.lnk" "$INSTDIR"
  CreateShortcut "$SMPROGRAMS\POS Marwa\Mode d'emploi.lnk" "$INSTDIR\LISEZ-MOI.txt"
  CreateShortcut "$SMPROGRAMS\POS Marwa\Désinstaller POS Marwa.lnk" "$INSTDIR\Desinstaller.exe"

  ; Désinstallation (Paramètres → Applications)
  WriteUninstaller "$INSTDIR\Desinstaller.exe"
  WriteRegStr HKLM "Software\POSMarwaBureau" "Dossier" "$INSTDIR"
  WriteRegStr HKLM "Software\POSMarwaBureau" "Version" "${VERSION}"
  WriteRegStr HKLM "${CLE_DESINSTALL}" "DisplayName" "POS Marwa"
  WriteRegStr HKLM "${CLE_DESINSTALL}" "DisplayVersion" "${VERSION}"
  WriteRegStr HKLM "${CLE_DESINSTALL}" "Publisher" "Mahamadou Hamidou (Mohamed)"
  WriteRegStr HKLM "${CLE_DESINSTALL}" "DisplayIcon" "$INSTDIR\pos-marwa.ico"
  WriteRegStr HKLM "${CLE_DESINSTALL}" "InstallLocation" "$INSTDIR"
  WriteRegStr HKLM "${CLE_DESINSTALL}" "UninstallString" '"$INSTDIR\Desinstaller.exe"'
  WriteRegStr HKLM "${CLE_DESINSTALL}" "HelpTelephone" "+227 92 08 12 82"
  WriteRegDWORD HKLM "${CLE_DESINSTALL}" "NoModify" 1
  WriteRegDWORD HKLM "${CLE_DESINSTALL}" "NoRepair" 1
  ${GetSize} "$INSTDIR" "/S=0K" $0 $1 $2
  IntFmt $0 "0x%08X" $0
  WriteRegDWORD HKLM "${CLE_DESINSTALL}" "EstimatedSize" "$0"
SectionEnd

Section "Raccourci sur le bureau" SecBureau
  SetOutPath "$INSTDIR"
  CreateShortcut "$DESKTOP\POS Marwa.lnk" "$INSTDIR\POS Marwa.exe" "" "$INSTDIR\pos-marwa.ico"
SectionEnd

Section "Démarrer POS Marwa à l'ouverture de Windows" SecAuto
  SetOutPath "$INSTDIR"
  CreateShortcut "$SMSTARTUP\POS Marwa.lnk" "$INSTDIR\POS Marwa.exe" "" "$INSTDIR\pos-marwa.ico"
SectionEnd

Section /o "Autoriser les autres postes du réseau local (tablettes, autres caisses)" SecReseau
  WriteINIStr "$INSTDIR\pos-marwa.ini" "POS Marwa" "RESEAU" "1"
  ; Règle du pare-feu limitée au programme POS Marwa et aux réseaux privés (jamais les réseaux publics)
  nsExec::ExecToLog 'netsh advfirewall firewall delete rule name="POS Marwa"'
  nsExec::ExecToLog 'netsh advfirewall firewall add rule name="POS Marwa" dir=in action=allow program="$INSTDIR\POS Marwa.exe" profile=private enable=yes'
SectionEnd

!insertmacro MUI_FUNCTION_DESCRIPTION_BEGIN
  !insertmacro MUI_DESCRIPTION_TEXT ${SecMain} "Le logiciel, sa base de données et son serveur, dans un seul dossier."
  !insertmacro MUI_DESCRIPTION_TEXT ${SecBureau} "Icône POS Marwa sur le bureau."
  !insertmacro MUI_DESCRIPTION_TEXT ${SecAuto} "POS Marwa s'ouvre automatiquement à l'ouverture de la session Windows."
  !insertmacro MUI_DESCRIPTION_TEXT ${SecReseau} "Les autres appareils du réseau local pourront ouvrir POS Marwa (adresse de ce PC, port 8740). Laissez décoché si POS Marwa n'est utilisé que sur ce PC (plus sûr)."
!insertmacro MUI_FUNCTION_DESCRIPTION_END

; ----------------------------------------------------------------------------------------------- désinstallation
Section "Uninstall"
  SetRegView 64
  nsExec::Exec '"$INSTDIR\POS Marwa.exe" --arreter'
  Sleep 4000
  ; Programme et code (les données restent)
  RMDir /r "$INSTDIR\php"
  RMDir /r "$INSTDIR\mariadb"
  RMDir /r "$INSTDIR\node"
  Delete "$INSTDIR\POS Marwa.exe"
  Delete "$INSTDIR\LISEZ-MOI.txt"
  Delete "$INSTDIR\LICENCES-TIERS.txt"
  Delete "$INSTDIR\pos-marwa.ico"
  ; Raccourcis, registre, pare-feu
  Delete "$DESKTOP\POS Marwa.lnk"
  Delete "$SMSTARTUP\POS Marwa.lnk"
  RMDir /r "$SMPROGRAMS\POS Marwa"
  nsExec::ExecToLog 'netsh advfirewall firewall delete rule name="POS Marwa"'
  DeleteRegKey HKLM "${CLE_DESINSTALL}"
  DeleteRegKey HKLM "Software\POSMarwaBureau"

  ; Données : conservées par défaut. Suppression seulement après deux confirmations explicites.
  MessageBox MB_YESNO|MB_ICONQUESTION|MB_DEFBUTTON2 "Le programme POS Marwa a été retiré.$\r$\n$\r$\nVos DONNÉES sont conservées dans :$\r$\n$INSTDIR$\r$\n(ventes, articles, clients, base de données, sauvegardes)$\r$\n$\r$\nVoulez-vous aussi SUPPRIMER DÉFINITIVEMENT ces données ?" IDYES confirmer
  Goto garder
  confirmer:
  MessageBox MB_YESNO|MB_ICONEXCLAMATION|MB_DEFBUTTON2 "DERNIÈRE CONFIRMATION$\r$\n$\r$\nToutes les ventes, articles, clients et sauvegardes de POS Marwa seront effacés de ce PC, sans retour possible.$\r$\n$\r$\nAvez-vous une copie de sauvegarde ailleurs (clé USB, autre PC) et voulez-vous vraiment tout supprimer ?" IDYES supprimer
  Goto garder
  supprimer:
  Delete "$INSTDIR\Desinstaller.exe"
  RMDir /r "$INSTDIR\donnees"
  RMDir /r "$INSTDIR\app"
  Delete "$INSTDIR\pos-marwa.ini"
  RMDir "$INSTDIR"
  Goto fin
  garder:
  Delete "$INSTDIR\Desinstaller.exe"
  MessageBox MB_ICONINFORMATION "Vos données sont conservées dans $INSTDIR.$\r$\nEn réinstallant POS Marwa dans ce même dossier, vous les retrouverez."
  fin:
SectionEnd
