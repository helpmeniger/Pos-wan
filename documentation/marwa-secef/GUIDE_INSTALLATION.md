# Guide d'installation — MARWA SECEF CONNECT

## 1. Prérequis

- POS Marwa 3.15 ou plus, module **Marwa Fiscal Connect** activé (Configuration → Modules complémentaires).
- Un compte e-SECeF actif avec un **e-SFE actif** (NIM attribué) et **au moins un opérateur** enregistré dans l'e-SFE.
- **Microsoft Edge** (présent sur Windows 10/11) ou Google Chrome.

## 2. Le pont

- **Édition Bureau (setup.exe)** : rien à installer. Node.js est livré (`C:\POS Marwa\node\node.exe`) avec les
  dépendances du pont ; `POS Marwa.exe` démarre le pont dès que le jeton est créé (étape 3), le surveille (redémarrage
  espacé en cas d'arrêt) et l'arrête avec POS Marwa. Journal : `donnees\journaux\pont-secef.log` ; données du pont :
  `donnees\pont-secef\` (registre des factures, PDF officiels, session du portail).
- **Version web (WampServer)** : installez Node.js 18 ou plus (nodejs.org), puis dans
  `addons\fiscal\pont-secef` : `npm install` ; le pont se démarre avec `demarrer-pont-secef.bat` (fenêtre à laisser ouverte).

## 3. Configurer dans POS Marwa

Paramètres → **Certification fiscale (MARWA SECEF CONNECT)** :

1. Cochez « Activer », gardez l'adresse du portail `https://esecef.impots.gouv.ne` et celle du pont `http://127.0.0.1:8760`.
2. Caisse : « Au choix du caissier » (case « Facture certifiée DGI ») ; type FV ; PRIX TTC.
3. Régime : « Impôt synthétique (groupe H) » ou « Assujetti à la TVA » (groupes par taux) ou « Manuel ».
4. Modes de paiement : vérifiez la correspondance.
5. **Compte e-SECeF** : identifiant (NIF) et mot de passe — chiffrés, jamais réaffichés.
6. **Créer le jeton du pont** : POS Marwa l'écrit lui-même dans `addons\fiscal\pont-secef\jeton.txt`.
7. Édition Bureau : le pont démarre seul dans les 10 secondes (état « en marche » sur la page). Version web :
   double-cliquez `demarrer-pont-secef.bat` (laissez la fenêtre ouverte).
8. **Tester la connexion** : le pont se connecte avec le NIF et ouvre l'e-SFE (première authentification). La session
   est ensuite réutilisée. Si le portail demande une vérification humaine, cochez « Afficher la fenêtre du navigateur ».

## 4. Articles

Page **Articles et taxes** : « Relever les articles de l'e-SFE » (lecture seule) rapproche les articles de même
désignation. Pour les autres : corrigez la désignation, choisissez le groupe (ou celui de la catégorie), puis
programmez-les sur l'e-SFE (bouton nuage), ou activez la programmation automatique depuis la caisse.

## 5. Essai sans certification (par défaut)

Tant que la certification réelle n'est pas autorisée, chaque vente cochée est **préparée et vérifiée** sur l'e-SFE
(articles, quantités, total), puis annulée : aucune facture n'est certifiée. Contrôlez le résultat dans
Fiscal Connect → Historique (message « préparée et vérifiée »).

## 6. Certification réelle

Après accord de la DGI sur ce mode d'intégration : bloc « Certification réelle » → mot de passe + `CERTIFIER`.
Faites une première vente d'essai de faible montant et comparez le reçu avec la facture du Journal de l'e-SFE.

## 7. Exploitation

- Le pont doit tourner pendant les ventes certifiées : automatique en édition Bureau ; version web : raccourci de
  `demarrer-pont-secef.bat` dans le dossier Démarrage de Windows.
- Factures en attente : Fiscal Connect → En attente ; « incertaine » : vérifier dans le Journal de l'e-SFE.
- Sauvegardez les données du pont (édition Bureau : `donnees\pont-secef\` ; version web : `pont-secef\donnees\`).
