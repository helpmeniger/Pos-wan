# Marwa Fiscal Connect — Guide de configuration

> Le module ne rend pas POS Marwa homologué. L'homologation, quand elle existe, est délivrée par l'administration fiscale du pays.
> Aucune règle fiscale n'est fournie par défaut : tout se configure à partir des textes officiels.

## 1. Pays (menu FISCAL CONNECT > Pays et règles fiscales)
- 11 pays sont listés avec leur seule devise. **Toutes les règles sont vides.**
- Pour chaque règle saisie, indiquer les **textes officiels de référence** (loi, arrêté, spécification, date) : sans source, l'enregistrement est refusé.
- Ajouter les **groupes de taxe officiels** (ex. lettre du groupe, libellé, taux, source) et, si besoin, le taux utilisé dans POS Marwa (« taux local »).
  Une vente contenant un taux non rattaché à un groupe n'est **pas envoyée** (statut « Échec (action requise) »).
- Cocher « Règles configurées » seulement quand la configuration est complète : c'est exigé pour la production.
- « Homologation obtenue » : uniquement sur décision écrite de l'administration, citée dans les sources.

## 2. Connecteurs (MCF ou API)
1. Créer le connecteur (il est créé **inactif**), en **TEST** d'abord.
2. Choisir le pilote : Simulation, REST/JSON, SOAP/XML, TCP, série, ou un squelette pays (non activable).
3. Remplir la configuration technique JSON **sans aucun secret** (adresse, points d'accès, correspondance des champs de réponse).
4. Saisir les secrets (clé d'API, jeton, identifiants, OAuth2) dans la zone « Secrets » : ils sont chiffrés et ne sont jamais réaffichés.
5. Téléverser les certificats si l'API l'exige (CA, certificat client, clé privée).
6. « Tester la connexion », puis « Activer », puis « Par défaut » (un connecteur par défaut par environnement).
   Un connecteur peut être limité à une boutique ou à une caisse.

## 3. Paramètres fiscaux (profils)
Un profil relie : pays + boutique (ou toutes) + environnement + connecteur + NIF vendeur + régime + mentions.
- **Un seul profil actif par périmètre.**
- Production refusée tant que : le pays n'est pas configuré, le NIF est absent, aucun connecteur de production réel n'est actif.
- La simulation est **interdite en production**.
- « Facture non encore certifiée » : imprimer avec la mention « NON CERTIFIÉE » (par défaut) ou bloquer l'impression.

## 4. Au quotidien
- Vente → envoi immédiat (délai du connecteur) → si réponse valide : ticket et facture portent le numéro de certification,
  l'identifiant du dispositif, la signature, les compteurs et le QR officiel reçus.
- Panne, délai dépassé, réponse incomplète → vente **enregistrée quand même**, facture **NON CERTIFIÉE**, mise en file d'attente.
  Au nouvel essai, le dispositif est d'abord interrogé sur l'état de la facture (pas de double certification).
- Refus du dispositif → statut « Refusée » : établir un document correctif.
- Facture certifiée → annulation locale impossible : faire un **retour** ; l'avoir est certifié à son tour, en référençant la facture.
- Vente hors connexion → ticket provisoire marqué « FACTURE NON CERTIFIÉE », certification à la synchronisation.

## 5. Contrôles
- **Historique** et **Factures en attente** : filtres, relance, export CSV/PDF.
- **Rapports fiscaux** : synthèse locale séparant PRODUCTION, TEST et SIMULATION ; rapport et clôture du dispositif si le pilote le permet.
- **Journaux** : chaque échange (requête et réponse, secrets masqués) et chaque modification de configuration.

## 6. Permissions
`fiscal.view`, `fiscal.configure`, `fiscal.secrets` (saisie seule), `fiscal.queue`, `fiscal.reports`, `fiscal.logs`.
Par défaut : propriétaire = tout ; gérant = consultation, relance, rapports ; comptable = consultation, rapports ; auditeur = consultation, rapports, journaux.
