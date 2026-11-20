# Gestion des erreurs — MARWA SECEF CONNECT

Principe : **la vente est toujours enregistrée** ; seule la certification peut échouer. Une facture n'est jamais
affichée certifiée sans Code SECeF/DGI et NIM lus sur le portail ; sinon le reçu porte « FACTURE NON CERTIFIÉE ».

| Situation | Détection | Comportement | Action |
|---|---|---|---|
| Connexion expirée | page « La session a expiré » / absence du menu e-SFE | le pont se reconnecte (NIF + mot de passe chiffrés dans POS Marwa) puis rouvre l'e-SFE | aucune |
| Identifiant ou mot de passe incorrect | message « … mot de passe invalide » (le portail bloque après plusieurs essais) | état `identifiants`, **aucune nouvelle tentative automatique** (le compte ne se bloque pas) | corriger les identifiants, « Tester la connexion » |
| Internet / portail indisponible | erreur réseau, e-SFE non chargée à temps | `erreur` réessayable → file d'attente (1, 2, 5, 15, 30, 60 min), reçu NON CERTIFIÉ | automatique ; « Réessayer » en caisse |
| Pont arrêté | 127.0.0.1:8760 injoignable | facture en file d'attente | démarrer le pont |
| Article non reconnu | désignation absente de la liste des articles programmés | `erreur` (rien sur le portail) ; ou programmation automatique si l'option est activée | programmer l'article (page Articles) puis Réessayer |
| Article sans groupe de taxation | aucune correspondance article / catégorie / régime | non transmis (aucun groupe deviné) | choisir le groupe |
| Client refusé (NIF) | message « L'NIF … » de l'e-SFE | `erreur` | corriger le NIF (13 caractères) |
| Écart de montant | total ou ligne e-SFE ≠ POS Marwa (± 1 FCFA) | **jamais certifiée** ; projet annulé | vérifier prix/remise/groupe |
| Remise, paiement en plusieurs modes, avoir | non reproductibles fidèlement pour l'instant | non transmis, message clair | voir RAPPORT_TESTS.md (limites) |
| Formulaire modifié par la DGI | élément attendu introuvable (« le portail a peut-être changé ») | `erreur` non réessayable | mise à jour du pont |
| Vérification humaine (code, CAPTCHA, opérateur absent) | textes du portail | état `intervention` | « Afficher la fenêtre du navigateur », terminer l'étape |
| Délai dépassé | le pont répond « en_cours » après `attente_s` | file d'attente ; l'état est **redemandé**, jamais renvoyé | aucune |
| Certification réussie mais retour perdu | le pont a écrit « certification envoyée » avant le clic | au prochain essai, état redemandé au pont ; s'il n'a pas pu lire le résultat : `incertaine`, **jamais renvoyée** | vérifier dans le Journal de l'e-SFE |
| Pont arrêté pendant la certification | registre : `en_cours` + certification envoyée | redémarrage → `incertaine` | vérifier dans le Journal |
| Double clic, double validation | clé d'idempotence unique (vente) + ligne unique `fiscal_certifications` + registre du pont | une seule soumission | aucune |

## Anti-doublon, en trois verrous

1. POS Marwa : une seule ligne de certification par facture (contrainte UNIQUE) ; une ligne certifiée n'est plus modifiable.
2. Moteur : à partir du 2e essai, l'état est demandé au pont avant tout envoi (`getInvoiceStatus`).
3. Pont : registre sur disque ; une facture dont CERTIFIER a pu être actionné n'est jamais renvoyée.
