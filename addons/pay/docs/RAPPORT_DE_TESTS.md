# Marwa Pay Connect — Rapport de tests

**Date :** 12/11/2026 · **Environnement :** Linux, PHP 8.3.6, MariaDB 10.11.14, serveur PHP intégré, Chromium (Playwright).
**Commande :** `TEST_DB_USER=… TEST_DB_PASS=… php tests/run.php` (base de test recréée à chaque exécution).
**Résultat :** 181 tests réussis, 0 échec (dont 7 blocs consacrés à Marwa Pay ; les autres vérifient que POS Marwa et Fiscal Connect ne sont pas affectés).

## Tests réellement exécutés
| Phase | Vérifié | Résultat |
|---|---|---|
| 3 — Tables | 13 tables ; 6 devises ISO sans aucun taux ; aucun prestataire déclaré intégré ; aucun moyen actif d'office ; réinstallation sans effet ; ventes et règlements intacts | Réussi |
| 4 — Moteur (simulation) | Réussi + idempotence (pas de second débit) ; refusé ; en attente puis confirmé ; expiration ; perte de connexion puis confirmation sans second débit ; réponse incohérente → à vérifier ; annulation confirmée ; montant invalide ; simulation interdite en production ; transaction TEST refusée pour une vente ; montant, panier, double usage contrôlés ; paiement mixte ; remboursements (justification, responsable, partiel, total, refus, attente) ; devise sans taux refusée, conversion avec taux saisi ; numéro de carte masqué | Réussi |
| 5 — Connecteurs (fausse API locale) | REST : clé absente, mauvaise clé, montants en centimes, frais, n° d'autorisation, clé absente des journaux, remboursement par l'API, attente puis confirmation, HTTP 402/503, statut inconnu jamais pris pour un succès, HTTPS exigé en production ; SOAP avec échappement XML ; page de paiement + notification signée (montant incohérent → à vérifier, doublon ignoré) ; 8 squelettes « non pris en charge » ; aucun connecteur déclaré vérifié | Réussi |
| 6 — Interface | 25 écrans et exports sans erreur ; menu ; caissier refusé ; clé jamais réaffichée ; secret dans la configuration refusé ; squelette non activable ; suppression refusée si historique ; moyen créé inactif puis activé ; taux sans source refusé ; remboursement par le gérant ; webhook HTTP (jeton, signature, « réussi » non cru sur parole, doublon) ; rapprochement CSV (confirmation d'un TPE autonome, ligne inconnue, écart traité) | Réussi |
| 7 — Caisse | Bouton et script présents ; moyens de la caisse ; montant invalide refusé ; aucune donnée interne exposée ; renvoi réseau idempotent ; vente mixte espèces + électronique par `/pos/checkout` ; référence sur le reçu ; double usage refusé ; annulation depuis la caisse ; non connecté et sans droit refusés ; vente en espèces inchangée. **Parcours réel dans un navigateur** : panier → Encaissement → Paiement électronique → paiement simulé confirmé → vente enregistrée en mobile money avec la référence | Réussi |
| 8 — Terminaux | Écrans ; validations (port, pont, liaison, simulation en production) ; TPE simulé de la caisse (en cours puis accepté, confirmé « terminal ») ; TPE autonome (n° d'autorisation exigé, « à vérifier ») ; TPE réseau TCP (montant en centimes, 4 derniers chiffres seulement, numéro complet jamais conservé, terminal éteint) ; **pont local avec un faux port série** (sans secret, mauvais secret, paiement accepté, secret absent des journaux, rejeu refusé, port non déclaré refusé) ; terminal Android (HTTP public refusé en production, réseau privé accepté) | Réussi |
| 9 — Sécurité | XSS échappé ; CSRF ; « vérifié » sans documentation refusé ; transaction d'une autre caisse invisible ; remboursement réel → sortie en carte, espèces attendues à la clôture inchangées ; tâche planifiée (expiration) ; aucune colonne ni journal contenant un numéro de carte ; téléphone du payeur masqué | Réussi |

## Ce qui N'A PAS été testé
- **Aucun prestataire réel** : ni Visa/Mastercard (acquéreur), ni PayPal, ni My NiTA, ni AMANA, ni opérateur Mobile Money, ni banque.
  Les connecteurs génériques ont été testés uniquement contre une **fausse API écrite pour les tests**.
- **Aucun TPE réel** (USB, COM, Bluetooth, Ethernet, Android) : le pont a été testé avec un faux port série (pty Linux), pas sous Windows ni avec un vrai terminal.
- mTLS avec un vrai serveur, OAuth 2.0 d'un vrai prestataire, notifications d'un vrai prestataire.
- Charge, montée en volume, impression du reçu sur imprimante thermique réelle.
- Aucun test d'intrusion sur un système tiers.

## Conclusion
Le moteur, la caisse, les remboursements, le rapprochement et la sécurité se comportent comme prévu dans les tests ci-dessus.
**Un encaissement réel n'est possible qu'après** : contrat marchand avec un prestataire, sa documentation officielle, la configuration
du connecteur (ou le développement d'un connecteur dédié) et sa validation sur l'environnement de test du prestataire.
