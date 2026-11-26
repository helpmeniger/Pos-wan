# MARWA GUARDIAN

Agent de surveillance et de protection des données pour POS Marwa — « Votre caisse protégée, vos données en sécurité. »

- Architecture et décisions : `documentation/marwa-guardian/ARCHITECTURE.md`
- Avancement : `documentation/marwa-guardian/RAPPORT_PHASE2.md`, `RAPPORT_PHASE3.md` (sauvegardes)

## Construire
    guardian/construire.sh <dossier de sortie> <version>     # tests, puis MarwaGuardian.exe et MarwaGuardian-Notificateur.exe

## Essayer (Linux, sans Windows)
    GUARDIAN_DATA=/tmp/g GUARDIAN_FIXTURES=guardian/internal/core/testdata go run ./guardian run
    # console : http://127.0.0.1:8750/

## Windows (administrateur)
    MarwaGuardian.exe install        # service + notificateur ; GUARDIAN_MOT_DE_PASSE=… pour fixer le mot de passe
    MarwaGuardian.exe uninstall      # l'historique reste dans %ProgramData%\MarwaGuardian
