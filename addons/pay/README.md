# Marwa Pay Connect

Paiements électroniques intégrés à la caisse POS Marwa : prestataires et API, terminaux (TPE), remboursements, rapprochement, rapports.

- Bouton **PAIEMENT ÉLECTRONIQUE** dans la fenêtre d'encaissement (moyens actifs de la caisse uniquement).
- Paiement réussi seulement sur confirmation authentifiée du prestataire ou du terminal ; idempotence ; un paiement ne règle qu'une vente.
- Connecteurs : simulation (TEST), REST/JSON, SOAP/XML, page de paiement + notification signée ; terminaux : simulé, autonome, réseau, USB/COM/Bluetooth via le pont local, Android.
- Squelettes non compatibles : Visa, Mastercard, cartes, PayPal, Mobile Money, My NiTA, AMANA, banque.
- Aucune donnée de carte (numéro, CVV, PIN) ; secrets chiffrés ; journaux masqués ; TEST jamais compté comme encaissement.

Documentation : `docs/INSTALLATION.md`, `docs/CONFIGURATION.md`, `docs/AJOUTER_UN_PRESTATAIRE.md`, `docs/TERMINAUX_ET_PONT.md`, `docs/API.md`, `docs/RAPPORT_DE_TESTS.md`.
