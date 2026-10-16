# État du projet — version 2.0.0 (POS Marwa)

## Terminé et vérifié

| Module | État | Vérification |
|---|---|---|
| Installation (web + CLI), migrations | ✅ | Tests automatisés + installation réelle sous Apache sur copie vierge |
| Authentification (mot de passe, PIN, badge), verrouillage, sessions | ✅ | Tests HTTP |
| Rôles et permissions (46), contrôle serveur, rôles personnalisés | ✅ | Tests (403, IDOR) |
| Caisse : recherche, scan, catégories, quantités rapides, conditionnements, gros, promo | ✅ | Tests + navigation Chromium |
| Paiements multiples / mixtes, monnaie, crédit avec plafond, autorisation superviseur | ✅ | Tests |
| Mise en attente / reprise, tickets 58/80 mm, facture A4, réimpression DUPLICATA | ✅ | Tests + rendu |
| Retours (partiels, avoir, remise en stock), annulations contre-passées | ✅ | Tests |
| Articles : CRUD, archivage, photos sécurisées, import/export CSV, étiquettes, EAN internes, prix groupés, historique, doublons | ✅ | Tests |
| Stocks : mouvements, ajustements, transferts, inventaires, lots/FEFO, rappels, traçabilité | ✅ | Tests (dont cohérence stock = Σ mouvements = Σ lots) |
| Achats : commandes, réceptions partielles, frais d'approche, CMP, retours, dettes, paiements | ✅ | Tests |
| Clients : comptes, crédits, règlements, acomptes, reçus, relevés | ✅ | Tests |
| Caisse : ouverture, mouvements, clôture par coupures, écart, état imprimable | ✅ | Tests |
| Dépenses / recettes, contrepassation | ✅ | Tests |
| Tableau de bord et rapports (ventes, articles, bénéfices, stock, créances/dettes, ordonnancier), export CSV | ✅ | Tests + rendu |
| Pharmacie : champs DCI, ordonnance obligatoire, pharmacien, registre | ✅ | Tests |
| Multi-boutiques / multi-entrepôts / multi-caisses | ✅ | Pages testées ; transfert testé |
| Journal d'audit | ✅ | Pages testées |
| **Restaurant** : plan de salle, commandes (sur place / emporter / livraison), bons par poste, écran cuisine, annulations avec perte, transfert/fusion, addition séparée, fiches techniques, rapport | ✅ | 10 tests + scénario complet Chromium (salle → cuisine → paiement) |
| Migration d'une installation existante (0001 → 0002) | ✅ | Exécutée sur une base avec données |
| Sauvegarde, vérification SHA-256, restauration, rotation, sauvegarde CLI planifiable | ✅ | Tests (aller-retour + altération) |
| **v1.2** Imprimantes multiples ESC/POS (réseau, USB partagée), usages par poste / caisse, tiroir, test | ✅ | Tests avec serveur TCP local (octets reçus) ; **non testé sur une imprimante physique** |
| **v1.2** Langues (fr, en, ar RTL, ha, es, pt, tr), devise configurable | ✅ | Tests + rendu Chromium en anglais et en arabe |
| **v1.2** 3 modèles de ticket, 3 modèles A4, QR code signé, page de vérification | ✅ | Tests HTTP + rendu |
| **v1.2** Devis, conversion en vente, bons de livraison | ✅ | Tests + scénario Chromium |
| **v1.2** Valider = encaissement direct, opérateurs mobile money, codes-barres générés | ✅ | Tests + scénario Chromium |
| **v1.2** Articles réservés restaurant / boutique, bon cuisine à la demande | ✅ | Tests |
| **v1.2** Réception : prix moyen / nouveau / ancien, nouveau prix de vente | ✅ | Tests + scénario Chromium |
| Migration d'une installation 1.1.0 (0003) | ✅ | Exécutée sur une base 1.1.0 avec données de démonstration |
| **v1.3** TVA (aucune / générale / par article, exonération), détail sur tickets et factures | ✅ | Tests + scénario Chromium |
| **v1.3** Rapport TVA par taux et par mois, net des retours | ✅ | Tests + PDF contrôlé (pdftotext) |
| **v1.3** Export PDF de tous les rapports et listes exportables | ✅ | Tests HTTP (8 exports) + rendu |
| **v1.3** Écran cuisine : poste par défaut, envoi à l'encaissement, réimpression du bon | ✅ | Tests + scénario Chromium (catégorie sans poste → écran cuisine) |
| **v1.4** Modules complémentaires (dossier addons/) : installation, mise à jour, activation, CLI | ✅ | Tests (manifestes invalides, version requise, chemin malveillant, permissions, désactivation = 404, données conservées) + Chromium |
| **v1.4** Module d'exemple Livraisons | ✅ | Tests + rendu |
| **v1.4.1** Module Pressing (tarifs, dépôts, étiquettes, express, acompte, retrait, annulation remboursée, rapport) | ✅ | 3 tests + scénario Chromium complet (dépôt → prêt → retrait avec monnaie) |
| **v1.5** Module Clinique (patients, rendez-vous, consultations, ordonnances → pharmacie, hospitalisation, plan de soins, facturation avec prise en charge, rapport) | ✅ | 5 tests (dont HTTP : confidentialité, droits, délivrance → vente + registre) + scénario Chromium |
| **v1.5** Écran de supervision des malades (alertes, soins dus, bouton « Fait ») | ✅ | Tests (flux JSON, CSRF) + Chromium (bureau et téléphone) ; **non testé en conditions réelles de service** |
| **v1.4** Menu à droite (option gauche), assistance sur l'écran de connexion | ✅ | Tests + Chromium (bureau et téléphone) |
| **v1.5.1** Mise à jour automatique de la base après copie des fichiers ; modules : erreurs affichées, dossiers mal décompressés rangés | ✅ | 3 tests + reproduction de la panne (installation 1.4 sans migration → erreur 500, puis corrigée) |
| **v1.6** Correction « Action inconnue » (confirmation des modules) | ✅ | Chromium : panne reproduite sur l'ancien code, corrigée |
| **v1.6** Mise à jour par sélection du dossier (lots, intégrité, sauvegardes, retour arrière) | ✅ | Tests + Chromium : installation 1.6.0 mise à jour en 1.6.1 par le navigateur, migration appliquée |
| **v1.6** À propos, contrat, licence signée (essai, lecture seule), outils de l'auteur | ✅ | Tests (falsification, autre installation, expiration) + activation réelle dans Chromium |
| Protections Apache (.htaccess) en sous-dossier | ✅ | Testé sous Apache 2.4 + mod_php |
| **v2.0** Renommage POS Marwa | ✅ | Recherche dans tout le code ; compatibilité conservée (base, clés de licence) |
| **v2.0** Stock temps réel en caisse, ventes simultanées | ✅ | Test HTTP : 10 ventes simultanées pour 5 en stock → 5 acceptées, stock 0, une seule déduction par vente |
| **v2.0** Modes de fonctionnement (6), caisse et menus adaptés | ✅ | Tests (redirections, droits, modules requis) + Chromium |
| **v2.0** Accès mobile par QR code, appareils (approbation, révocation), `/health` | ✅ | Tests HTTP (révocation → session coupée) + Chromium mobile 390 × 844 ; **détection de l'adresse IP non testée sur un vrai réseau local** |
| **v2.0** Scan caméra (lecteur intégré du navigateur / ZXing, une lecture par présentation, +1, photo) | ✅ | Chromium avec caméra simulée (flux vidéo EAN-13) : caisse, inventaire ; **non testé sur un téléphone physique** |
| **v2.0** Inventaire scan + manuel, journal, filtres, rapport, validation | ✅ | Tests + Chromium (caméra et douchette) |
| **v2.0** Étiquettes (champs, A4/rouleau en mm, modèles, aperçu, calibrage, EAN-13) | ✅ | Tests + code-barres imprimé relu par ZXing ; **non testé sur une imprimante d'étiquettes réelle** |
| **v2.0** Clinique 1.1 : caisse clinique, paiement partiel, contrôle par scan à la délivrance | ✅ | Tests HTTP + Chromium |
| **v2.0** Pressing 1.1 : caisse, statuts, retrait partiel, retards, suivi, historique client | ✅ | Tests + Chromium |
| **v2.0** Lanceur Windows, raccourci, tâche planifiée, PWA | ⚠️ | Écrits et relus ; **non exécutés sous Windows** (voir `docs/DEMARRAGE_WINDOWS.md` §5) |

**Résultat de la suite de tests : 116 réussis, 0 échec** (`php tests/run.php`).

## Limites connues / non implémenté (à ne pas présenter comme disponible)

| Élément | Situation actuelle | Piste |
|---|---|---|
| Impression ESC/POS en arabe, logo imprimé en ESC/POS | Texte latin (page de codes PC850) uniquement ; l'arabe et le logo passent par l'impression navigateur | Rendu image (raster) des tickets |
| Intégration API mobile money (Airtel Money, Moov/Flooz, Zamani Cash…) et TPE | Paiements enregistrés avec la référence de transaction, sans échange avec l'opérateur | Intégration selon les API et contrats des opérateurs |
| Lecture automatique d'une balance électronique | Saisie manuelle du poids (quantités décimales) ; pas de lecture des étiquettes code-barres « prix/poids » | Prise en charge des codes EAN à poids variable (préfixe 2x) |
| Afficheur client (second écran) | Non disponible | Page « afficheur » synchronisée |
| Utilisation des points de fidélité (conversion en remise) | Les points sont cumulés ; leur utilisation se fait par une remise manuelle | Bon de réduction automatique |
| Export Excel natif (.xlsx) | CSV compatible Excel ; PDF généré par POS Marwa (polices latines : pas d'arabe dans les PDF) | Bibliothèque XLSX |
| Prix saisis hors taxe (TVA ajoutée au total) | Prix toujours TTC, TVA extraite | Mode HT si besoin |
| Conversion d'unités générique (kg ↔ g, etc.) | Conditionnements (N unités de base) uniquement | Table de conversions |
| Génération automatique de la grille de variantes | Chaque variante est un article lié à son parent (création par « Dupliquer / variante ») | Assistant taille × couleur |
| Clinique : résultats de laboratoire / imagerie, facturation électronique aux assurances | Examens facturés comme actes ; montants dus par organisme dans le rapport | Module laboratoire |
| Clinique : certification logiciel médical | Non certifié : outil de gestion, seuils d'alerte indicatifs | — |
| Protection anti-copie | Clé signée liée à l'installation + lecture seule ; code PHP lisible, donc contournable par un informaticien | Encodage ionCube / SourceGuardian (payant) |
| Mode hors ligne (coupure du serveur local) | Le panier en cours est conservé dans le navigateur, mais la vente nécessite le serveur | File d'attente locale synchronisée |
| Traductions | Générées et contrôlées automatiquement, non relues par des locuteurs natifs (haoussa en priorité) ; certains messages dynamiques restent en français | Relecture ; ajout des textes manquants dans `lang/<code>.php` |
| Devise | Changement d'affichage uniquement : aucun taux de change, montants existants non convertis | — |
| Restaurant : modificateurs payants (suppléments), menus/formules à prix fixe, réservations | Notes libres ; suppléments à saisir comme articles | Options d'articles et réservations |
| HTTPS | Documenté (certificat local), non configuré automatiquement ; nécessaire pour la caméra en direct sur smartphone (sinon « Photo du code ») | — |
| Conformité réglementaire pharmacie / fiscale (Niger) | Fonctions de traçabilité fournies, **non certifiées** | Validation par un pharmacien et un expert-comptable |
| Factures normalisées / dispositifs fiscaux éventuels exigés par l'administration | Non implémenté | À étudier selon la réglementation en vigueur |

## Prochaines tâches proposées
0. Essayer sur le poste Windows le lanceur, le raccourci et le démarrage automatique ; essayer le scan caméra sur les téléphones de l'établissement (en HTTPS) et l'imprimante d'étiquettes.
1. Valider le module pharmacie et les mentions des factures avec des professionnels locaux.
2. Essais sur les imprimantes thermiques réellement utilisées (réseau et USB partagée) ; relecture des traductions.
3. Codes-barres balance (poids/prix variable) et afficheur client.
4. Utilisation des points de fidélité.
5. Export XLSX natif des rapports.
