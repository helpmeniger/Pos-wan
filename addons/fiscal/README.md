# Marwa Fiscal Connect (MF CONNECT)

Module de connexion des factures POS Marwa aux dispositifs fiscaux (MCF) et aux plateformes de facturation normalisée.

- Moteur central : certification après chaque vente, file d'attente, idempotence, preuves conservées, avoirs.
- Pilotes : simulation, REST/JSON, SOAP/XML, TCP, série ; squelettes Niger, Bénin, Togo (non compatibles).
- Interface : menu « FISCAL CONNECT » (11 écrans).
- Sécurité : secrets chiffrés et jamais réaffichés, masquage des journaux, TEST/PROD séparés, permissions dédiées.

**Aucun pilote n'est déclaré compatible avec un dispositif officiel. Aucune règle fiscale n'est préremplie. Le module ne vaut pas homologation.**

Documentation : `docs/INSTALLATION.md`, `docs/CONFIGURATION.md`, `docs/AJOUTER_UN_MCF.md`, `docs/API.md`, `docs/RAPPORT_DE_TESTS.md`
(également consultable dans l'application : FISCAL CONNECT > Documentation des connecteurs).
