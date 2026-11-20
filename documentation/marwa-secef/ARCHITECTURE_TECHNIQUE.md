# Architecture technique — MARWA SECEF CONNECT

## Vue d'ensemble

```
Caisse POS Marwa (navigateur)
  │  case « Facture certifiée DGI »
  ▼
POS Marwa (PHP / MySQL) ── vente enregistrée tout de suite (jamais bloquée par le portail)
  │  événement sale.created → Marwa Fiscal Connect (module « fiscal »)
  │     FiscalEngine : ligne fiscal_certifications unique par facture (clé d'idempotence), file d'attente,
  │                    preuve, journaux masqués, « certifiée » seulement avec preuve validée
  │     pilote ne_esfe_pont (EsfePontDriver) ── Secef::buildInvoice (régime, groupes, paiements)
  ▼  HTTP 127.0.0.1:8760, jeton partagé (X-Pont-Jeton)
Pont MARWA SECEF (Node.js) ── addons/fiscal/pont-secef/serveur.js
  │  registre des factures (donnees/travaux/<clé>.json, écritures atomiques)
  │  une opération à la fois ; garde-fou : écritures vers le portail bloquées sauf
  │  « programmer un article » et « CERTIFIER »
  ▼  Playwright (Edge sous Windows, Chromium ailleurs), profil persistant (session réutilisée)
e-SECeF (esecef.impots.gouv.ne) ── connexion SyGMEF (NIF) ── application e-SFE (Factures, Articles, Journal)
```

## Rôle de chaque partie

| Partie | Fichiers | Rôle |
|---|---|---|
| Caisse | `app/Views/pos/index.php`, `public/assets/js/pos.js` | Case « Facture certifiée DGI » (mode « au choix ») ; après la vente, fenêtre « Certification sur l'e-SECeF en cours… » ; le ticket attend le résultat ; options Réessayer / Reçu NON CERTIFIÉ |
| Vente | `app/Services/SaleService.php` | Transmet le choix (`fiscal_certify`) dans l'événement `sale.created` (seule modification du cœur) |
| Moteur fiscal | `addons/fiscal/src/FiscalEngine.php` | `saleChoice()` (toutes / au choix, historique), `isSlowConnector()` (le pont ne bloque pas la caisse), file d'attente, anti-doublon, preuve |
| Règles e-SECeF | `addons/fiscal/src/Secef/Secef.php` | Groupes A–L, régimes (IS, TVA, manuel), correspondance article > catégorie > régime, paiements, construction de la facture e-SFE, refus de ce qui ne peut pas être reproduit fidèlement |
| Pilote | `addons/fiscal/src/Drivers/Niger/EsfePontDriver.php` | Traduit les états du pont en résultats Fiscal Connect ; « certifiée » seulement avec Code SECeF/DGI **et** NIM |
| Client du pont | `addons/fiscal/src/Secef/PontClient.php` | HTTP local seulement (127.0.0.1), jeton, sans proxy ; identifiants jamais journalisés |
| Administration | `addons/fiscal/src/Secef/SecefController.php`, `views/secef/*.php` | Configuration, compte, jeton, test, session, autorisation de certifier, articles, catégories |
| Reçu | `addons/fiscal/src/FiscalDocument.php` | Bloc « FACTURE CERTIFIÉE e-SECeF » : Code SECeF/DGI, SECeF NIM, compteurs, heure, ISF, mention de vérification |
| Pont | `addons/fiscal/pont-secef/` | Serveur local, registre des factures, automatisation du portail |

## Évolution vers une intégration officielle (e-MCF / API DGI)

Le pilote est une classe parmi d'autres (`DriverRegistry`). Une API officielle e-MCF, une fois sa documentation
obtenue auprès de la DGI, se branchera comme un nouveau pilote (même interface : `certifyInvoice`,
`getInvoiceStatus`, `issueCreditNote`) : la caisse, la file d'attente, le reçu et l'historique restent inchangés,
et le pont devient inutile.

## Choix de conception

- **La caisse n'attend jamais le portail** : la vente est enregistrée d'abord ; la certification suit (fenêtre de
  suivi, puis file d'attente en cas de panne). Une facture non certifiée est imprimée « NON CERTIFIÉE ».
- **Aucune référence inventée** : sans Code SECeF/DGI et NIM lus à l'écran du portail, la facture n'est pas certifiée.
- **Pas de double certification** : clé unique par facture ; le pont écrit sur disque « certification envoyée »
  avant de cliquer ; ensuite il ne renvoie jamais la facture (état « incertaine », vérification humaine).
- **Pas de devinette fiscale** : le groupe de taxation vient de l'article, de sa catégorie ou d'une règle choisie
  par l'administrateur ; sinon la facture n'est pas transmise.
- **Certification réelle désactivée par défaut** : le pont prépare, vérifie et annule ; l'administrateur
  l'autorise (mot de passe + « CERTIFIER ») après l'accord de la DGI.
- **Stock** : géré par POS Marwa (sortie de stock à la vente, comme toujours). Le pont n'active pas le contrôle de
  stock de l'e-SFE lorsqu'il programme un article.
