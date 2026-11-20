# Rapport de tests — MARWA SECEF CONNECT (20/11/2026)

**Aucune facture fiscale réelle n'a été émise.** Pendant tous les essais sur le vrai portail, le pont bloquait
toute écriture vers le portail (seules les lectures passaient) et n'a jamais cliqué sur CERTIFIER.

## 1. Tests automatiques de POS Marwa (`tests/run.php`) — 198 / 198 réussis

Le test « MARWA SECEF CONNECT » utilise un **faux pont local** (`tests/fake-secef-pont.php`, références marquées
TEST, aucun lien avec la DGI). Il vérifie :

| Domaine | Vérifications |
|---|---|
| Base de données | 4 tables créées par la migration ; aucune table existante modifiée |
| Règles fiscales | régime IS → H ; TVA → groupe par taux (19 → B, 0 → A) ; taux inconnu → aucun groupe ; catégorie puis article prioritaires ; régime manuel ; groupe inconnu refusé ; pont hors de 127.0.0.1 refusé ; écart de taux signalé |
| Configuration | connecteur et profil créés et activés ; identifiants chiffrés, jamais réaffichés ; jeton affiché une seule fois ; test de connexion ; page Articles |
| Articles | relevé du portail : rapprochement par désignation (casse et espaces ignorés) |
| Caisse | vente ordinaire → aucune certification, choix conservé ; « Facture certifiée DGI » → la caisse n'attend pas le portail ; case affichée en caisse ; mode « toutes les ventes » |
| Certification non autorisée (défaut) | facture préparée et vérifiée, jamais certifiée ; ticket « NON CERTIFIÉE » ; le pont reçoit « ne pas certifier » |
| Autorisation | refusée avec un mauvais mot de passe ; accordée avec mot de passe + « CERTIFIER » |
| Certification (faux pont) | « Réessayer » en caisse → certifiée ; Code SECeF/DGI et NIM tels que renvoyés ; facture transmise (type, groupe, quantité, référence POS) ; aucun mot de passe dans les journaux |
| Reçus | ticket et A4 : « FACTURE CERTIFIÉE e-SECeF », Code SECeF/DGI, SECeF NIM, mention de vérification sygmef |
| Anti-doublon | certifiée : jamais resoumise ; réponse lente : file d'attente puis état redemandé, aucun second envoi ; « incertaine » : jamais renvoyée, jamais affichée certifiée, vérification dans le Journal demandée |
| Refus avant envoi | paiement en plusieurs modes ; article sans groupe de taxation → rien envoyé au pont |
| Identifiants refusés | signalés, sans nouvel essai |

## 2. Tests du pont sans portail (`npm test`) — 6 / 6 réussis

Registre anti-doublon (renvoi interdit dès que CERTIFIER a pu partir) ; reprise après arrêt brutal (→ « incertaine »
ou « erreur réessayable ») ; journal sans secret ; lecture des montants affichés (espaces insécables) ; serveur :
jeton absent ou faux (401), appel depuis une page web (403), autre nom d'hôte (403), facture invalide (400, rien
enregistré), refus de démarrer sans jeton.

## 3. Essais réels du pont sur l'e-SECeF (compte du contribuable, lecture seule)

| Essai | Résultat |
|---|---|
| Connexion (NIF + mot de passe) puis ouverture de l'e-SFE | ✅ réussi ; NIM lu sur l'écran d'accueil ; ~80 s depuis l'environnement d'essai |
| Conservation de la session | ✅ le relevé suivant n'a pas eu à se reconnecter (profil du navigateur) |
| Session absente (« La session a expiré ») | ✅ détectée ; connexion par l'accueil (corrigé après le 1er essai) |
| Relevé des articles programmés | ✅ 38 articles lus (groupe, prix TTC/HT, mode) sur 39 annoncés : un doublon de désignation probable, fusionné |
| Ouverture du formulaire « FV / PRIX TTC » | ✅ |
| Recherche de l'article et ajout au panier | ✅ « Support de communication » ajouté (quantité 1, 300 000), bandeau « Groupes: H », total 300 000, boutons CERTIFIER / ANNULER présents (non cliqués) |
| Modification de la quantité (ligne du panier) | ⚠️ **non vérifiée** : correction faite (icône sous la barre « Total »), mais les deux derniers essais n'ont pas pu charger l'e-SFE (liaison réseau de l'environnement d'essai coupée) |
| Contrôle du total, annulation sans certifier | ⚠️ non atteints pour la même raison |
| Garde-fou d'écriture | ✅ aucune écriture n'a atteint le portail |
| Déconnexion | ✅ « Se déconnecter » |

Défauts trouvés par les essais réels et corrigés : connexion par l'accueil ; menu cherché dans le mauvais en-tête ;
champs des pages cachées (l'application garde les pages déjà visitées) ; formulaire visé avant son chargement ;
lignes de tableau `mat-row` ; icône de ligne sous la barre fixe ; seconde tentative d'ouverture de l'e-SFE.

## 4. Non testé — à faire avant toute facture réelle

1. **Fin de la préparation sur le portail** : quantité ≠ 1, contrôle du total, annulation (à refaire depuis le PC du
   contribuable : réseau normal).
2. **CERTIFIER** : jamais actionné. Format exact du résultat (libellés des éléments de sécurité, compteurs, heure),
   **PDF officiel** et **contenu du QR code** : inconnus tant qu'une facture réelle n'a pas été certifiée — le pont
   lit les libellés observés dans l'application (« Code SECeF/DGI », « SECeF NIM », « SECeF Compteurs »,
   « SECeF Heure ») ; une différence donnerait l'état « incertaine », jamais une fausse certification.
3. **Saisie du client et des commentaires** sur le portail (fenêtres vues, saisie automatique non essayée).
4. **Programmation automatique d'un article** (écriture sur le portail : non essayée).
5. Impression thermique réelle et A4 d'une facture e-SECeF réelle (vérifiée seulement avec le faux pont).
6. **Pont sous Windows avec Edge** : écrit pour (canal `msedge`), essayé seulement sous Linux avec Chromium.

## 5. Limites actuelles (refus explicites, jamais de facture différente de la vente)

- Remise de ligne ou globale ; paiement en plusieurs modes ; factures d'avoir (FA/EA) : non transmis, message clair.
- Types FT, ET (acompte) non proposés.
- Édition Bureau 3.15.1 : Node.js 24 LTS et les dépendances du pont sont livrés ; le pont est démarré par
  `POS Marwa.exe` (essayé sous Linux ; non exécuté sous Windows).
