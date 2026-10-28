# Guide d'utilisation rapide — POS Marwa

## Se connecter
- **Accès rapide PIN** : choisir son nom, taper son code PIN sur le pavé, puis **GO**.
- **Identifiant** : identifiant + mot de passe.
- **Badge** (si activé) : passer le badge devant le lecteur.

Après plusieurs erreurs, le compte est bloqué 15 minutes (un administrateur peut le débloquer).

## La caisse

```
┌──────────────── Catégories (tuiles) ────────────────┐ ┌2┐ ┌──── Ticket ────┐
│ Recherche…                                          │ │3│ │ Total : 14 700 F│
│ ┌────┐ ┌────┐ ┌────┐  Articles : photo, stock, prix │ │…│ │ actions         │
│ └────┘ └────┘ └────┘                                │ │9│ │ lignes          │
│ [Auto][Manuel] [ code-barres ] ✓ 🗑 [Argent client] 🖩 [à rendre] │ │ VALIDER         │
└──────────────────────────────────────────────────────┘ └─┘ └────────────────┘
```

1. **Ouvrir la caisse** le matin : saisir le fond de caisse compté.
2. **Ajouter des articles** : scanner, toucher une tuile, ou chercher par nom.
   - Touches **2 à 9** (bande centrale) : quantité pour le prochain article. Toucher deux fois la même touche l'applique à la ligne sélectionnée.
   - Bouton « Pack de 6 », « Carton de 24 »… sur la tuile : vend le conditionnement.
3. **Modifier une ligne** : la toucher, puis *Quantité*, *Prix*, *Remise* ou *Retirer*.
   Clavier : `+` / `-` (quantité), `Suppr` (retirer), flèches (sélection).
4. **Client** : bouton *Client* (recherche par nom ou téléphone, création rapide).
5. **Encaisser** :
   - **Valider** (ou F9) : encaissement **espèces immédiat**, sans fenêtre. Si un montant
     est saisi dans « Argent client » (en bas), il sert de montant remis et la **monnaie à
     rendre** s'affiche dans la barre orange au-dessus du bouton ; sinon le montant exact
     est encaissé. Si l'argent client ne couvre pas le total, la fenêtre d'encaissement s'ouvre.
   - **Espèces** (ou F10, bouton en bas du ticket) : ouvre la **fenêtre d'encaissement**
     complète : espèces avec billets rapides, carte, **mobile money** (choisir l'opérateur :
     Airtel Money, Moov Money, Zamani Cash… et saisir la référence), virement, paiement
     mixte. Valider la vente dans la fenêtre.
   - La validation directe peut être désactivée (Paramètres → « Caisse : « Valider »
     encaisse directement en espèces ») : Valider ouvre alors toujours la fenêtre.
6. Le ticket s'imprime automatiquement (case **TICKET**) — sur l'imprimante de la caisse si
   elle est déclarée dans Paramètres → Imprimantes, sinon par le navigateur. Case
   **Facture A4** pour une facture. Un **QR code** en bas du ticket et de la facture permet
   de vérifier le document (menu *Vérifier un QR code*, avec une douchette 2D).

| Touche | Action |
|---|---|
| F2 | Recherche d'article |
| F4 | Client |
| F6 | Quantité de la ligne |
| F7 | Remise |
| F8 | Mettre en attente |
| F9 | Valider (encaissement espèces direct) / valider dans la fenêtre |
| F10 | Fenêtre d'encaissement (espèces, mobile money, carte, mixte) |
| F12 | Vider le panier |
| Entrée | (après une vente) nouvelle vente |

Les touches sont modifiables dans Paramètres.

### Cas particuliers
- **Vente à crédit** : choisir le client, bouton *Crédit*, encaisser l'éventuel acompte, valider. Le reste est inscrit sur son compte (plafond contrôlé).
- **Remise au-delà du seuil, prix modifié, crédit hors plafond** : une fenêtre demande l'identifiant et le mot de passe (ou PIN) d'un responsable.
- **Mise en attente** (F8) : le client va chercher un article ; servir le suivant ; *Reprendre* pour rappeler la vente.
- **Médicament sur ordonnance** (pharmacie) : renseigner prescripteur et patient dans la fenêtre d'encaissement ; un pharmacien doit être connecté ou autoriser.
- **Coupure réseau / double clic** : la vente n'est jamais enregistrée deux fois ; réessayer sans crainte.
- **Caisse hors connexion** (v2.11) : voir ci-dessous.
- **Réimpression** : *Tickets* → icône imprimante ; la copie porte la mention DUPLICATA (permission requise).

## Caisse hors connexion (coupure Internet ou serveur injoignable)

Une fois la caisse ouverte **au moins une fois avec la connexion**, elle continue de vendre si la
connexion tombe :

1. Un **bandeau rouge « Hors connexion »** apparaît en haut de la caisse.
2. Les articles, la recherche et le code-barres fonctionnent avec le **catalogue gardé sur
   l'appareil** (mis à jour toutes les 10 minutes quand la connexion est là).
3. **Valider** : la vente reçoit un **numéro provisoire** (ex. `HL-2610-143205-4BDB`), la monnaie
   s'affiche, un **ticket provisoire** s'imprime par le navigateur. La vente est gardée dans le
   navigateur de ce poste, même si la page est rechargée ou le PC redémarré.
4. **Retour de la connexion** : les ventes sont envoyées **automatiquement** (bouton « Envoyer
   maintenant » en plus). Le serveur leur donne leur numéro définitif ; la date et le numéro
   provisoire sont recopiés dans la note de la vente. Aucune vente ne peut être enregistrée deux fois.
5. **Fermer le navigateur ou l'application** alors que des ventes ne sont pas envoyées : le
   navigateur demande confirmation, et le bandeau rappelle qu'il faut **rétablir la connexion pour
   les enregistrer**. Si vous fermez quand même, elles restent gardées sur ce poste et partent à la
   prochaine ouverture de la caisse (même utilisateur).
6. **Vente refusée** à l'envoi (caisse fermée entre-temps, stock insuffisant, plafond de crédit…) :
   bouton rouge « vente(s) refusée(s) — vérifier » → **Réessayer**, **Reprendre dans le panier**
   (pour corriger puis valider) ou **Supprimer** (après confirmation ; la vente n'existe alors nulle part).

Hors connexion, **impossible** : rechercher ou créer un client (le client déjà choisi reste),
mettre en attente / reprendre, consulter les tickets, et toute opération qui exige l'autorisation
d'un responsable (remise ou prix sans droit, vente à crédit sans droit, prix de gros sans droit) :
son mot de passe ne peut pas être vérifié sans le serveur. Les autres pages (stocks, rapports…)
exigent la connexion.

## Devis et bons de livraison
- **Devis** (menu Gestion des ventes → Devis) : *Nouveau devis*, choisir une fiche client ou
  saisir le nom, ajouter les articles (recherche ou douchette), quantités, remises, date de
  validité. Le devis n'affecte ni le stock ni la caisse. Imprimer (modèle A4 choisi dans les
  paramètres, avec QR code), marquer *envoyé*, *accepté* ou *refusé*, *Dupliquer*.
- **Convertir en vente** : saisir le ou les paiements ; la vente reprend les prix du devis,
  déduit le stock et passe dans la caisse ouverte. Le devis devient « Facturé ».
- **Bon de livraison** : fiche d'une vente → *Bon de livraison* (sans les prix ; bouton
  « Avec les prix » dans la barre d'impression). Colonne « Livré / contrôle » et signatures.

## Articles : code-barres
- Article sans code-barres : dans la fiche (création ou modification), bouton **Générer**
  à côté du champ Code-barres (code interne EAN-13 commençant par 21), puis imprimer
  l'étiquette. Sur la fiche d'un article : bouton **Créer un code-barres**.
- Articles → **Codes-barres manquants** : crée un code pour tous les articles actifs qui n'en
  ont pas et ouvre la page d'étiquettes.

## Achats : prix qui change
À la réception (commande ou réception directe), pour chaque ligne :
- **Prix moyen** (par défaut) : coût = (ancien stock × ancien coût + quantité reçue × nouveau
  coût) ÷ stock total ;
- **Nouveau prix** : tout le stock, ancien compris, est valorisé au nouveau coût ;
- **Ancien prix** : le coût actuel ne change pas.
Le menu en haut du tableau applique le même choix à toutes les lignes. La colonne
**Nouveau prix de vente** (droit « Modifier les prix de vente ») met à jour le prix en caisse ;
le changement est historisé.

## Mode de fonctionnement (Paramètres)
Le mode se règle dans **Paramètres → Mode de fonctionnement de la caisse**. Choix possibles :
Commerce général, Restaurant, Pharmacie, Clinique, Clinique + Pharmacie, Pressing.

« Ouvrir la caisse » et l'écran d'accueil ouvrent alors la caisse de l'activité :
- `/pos` pour le commerce, le restaurant et la pharmacie ;
- la caisse clinique ;
- la caisse pressing.

Les menus des autres activités sont masqués, sauf si vous cochez « Afficher aussi les menus
des autres activités ». Changer de mode ne supprime aucune donnée.

## Scanner avec la caméra
Le bouton **caméra** existe à plusieurs endroits :
- dans la caisse, à côté du champ code-barres ;
- dans l'inventaire ;
- dans la liste des articles ;
- dans les étiquettes ;
- à la délivrance pharmacie ;
- dans la caisse pressing.

Comment il fonctionne :
- Présentez le code devant la caméra : **un bip = un article**.
- Laisser le code devant la caméra n'ajoute rien de plus. Pour un deuxième article
  identique, retirez le code puis représentez-le, ou touchez **+1**.
- Sans HTTPS, le navigateur interdit la caméra en direct : utilisez **Photo du code**. Le
  téléphone prend une photo, que POS Marwa lit.
- La douchette USB ou Bluetooth fonctionne toujours, sans rien régler.

## Accès depuis un smartphone (QR code)
1. **Configuration → Accès mobile (QR code)** : scannez le QR code avec le téléphone, qui
   doit être sur le même Wi-Fi que l'ordinateur. Les réglages Apache et pare-feu sont
   indiqués sur la page et dans `docs/DEMARRAGE_WINDOWS.md`.
2. Connectez-vous avec votre identifiant et votre mot de passe : le QR code seul ne donne
   aucun accès.
3. **Configuration → Appareils connectés** :
   - liste des appareils ;
   - **Révoquer** un appareil : sa session est coupée et il ne peut plus se connecter ;
   - option « approuver les nouveaux appareils du réseau avant leur première utilisation ».

## Inventaire
1. Stock → Inventaires → **Démarrer** : choisissez l'entrepôt, et éventuellement une
   catégorie pour un inventaire partiel.
2. Comptez de l'une des deux façons, ou des deux :
   - **Scan** (douchette ou caméra) : chaque lecture ajoute 1. Quinze articles scannés
     donnent 15.
   - **Saisie** : tapez la quantité et une observation ; le bouton « 0 » marque un article
     absent ; « Recommencer » efface le comptage d'un article.

   Tout est enregistré immédiatement : vous pouvez fermer et reprendre plus tard.
3. Utilisez les filtres pour contrôler : non comptés, écarts, surplus, manquants, conformes.
4. Le rapport s'imprime ou s'exporte en CSV ou PDF.
5. La **validation** est faite par un responsable : les écarts deviennent des mouvements de
   stock, puis l'inventaire est verrouillé.

## Étiquettes code-barres (Articles → Étiquettes)
1. Ajoutez les articles :
   - par recherche ;
   - par scan ;
   - ou toute une catégorie.

   Réglez ensuite le nombre d'étiquettes par article.
2. Cochez les informations à imprimer, ou choisissez une combinaison rapide.
3. Choisissez le format :
   - planche A4 (largeur, hauteur, colonnes, marges, espacements) ;
   - ou rouleau d'imprimante d'étiquettes.
4. Contrôlez l'**aperçu**. Imprimez d'abord une **étiquette de calibrage**, à l'échelle 100 %
   et sans marges du navigateur.
5. Enregistrez vos réglages comme **modèle**, et cochez « par défaut » si besoin.

## Modules complémentaires
Pour ajouter une fonction (ex. Livraisons) : copier le dossier du module dans `addons/`,
puis **Gestion → Modules complémentaires → Installer**. *Désactiver* masque le module sans
effacer ses données. Détails : `docs/MODULES.md`.

### Clinique
Module fourni (`addons/clinique/`) : à installer depuis Modules complémentaires. Patients,
rendez-vous, consultations, ordonnances délivrées par la pharmacie, hospitalisation,
écran de supervision des malades. Guide : `addons/clinique/README.md`.

**Caisse clinique** (menu Clinique → Caisse clinique, ou automatiquement en mode Clinique) :
1. Recherchez le patient, ou créez-le en deux champs.
2. Touchez les prestations.
3. Encaissez tout ou partie : le reste dû apparaît sur la facture du patient.
4. Imprimez le ticket.

La pharmacie reste une vente séparée, reliée par l'ordonnance.

### Établissement scolaire / université
Module `addons/ecole/`, à activer dans Modules complémentaires, puis choisir le mode
« Établissement scolaire / université » dans Paramètres (la caisse et les menus s'adaptent).

1. **Paramètres de l'établissement :**
   - créez l'année (3 trimestres ou 2 semestres) ;
   - réglez les horaires et la tolérance de retard.
2. **Classes et matières :** créez les classes avec leurs frais, puis le programme de chaque
   classe (matières, coefficients, enseignant).
3. **Caisse :**
   - « Nouvelle inscription » crée la fiche, inscrit dans la classe, calcule l'échéancier et
     encaisse le premier versement ;
   - pour un élève déjà inscrit : recherchez-le (nom, matricule, téléphone du parent, carte
     scannée) puis encaissez. Les échéances les plus anciennes sont réglées d'abord, sauf si
     vous en cochez ;
   - le reçu s'imprime en ticket ou en A4.
4. **Notes :**
   - créez une évaluation (barème, coefficient), puis saisissez les notes au clavier (Entrée =
     élève suivant, « abs » = absent) ;
   - Bulletins : complétez les appréciations, puis « Imprimer tous les bulletins ».
5. **Présences :** faites l'appel par classe ; en fin de journée, « Clôturer la journée » note
   absents ceux qui n'ont été ni appelés ni pointés.
6. **Pointage par caméra (facultatif) :**
   - activez la reconnaissance faciale dans les paramètres, après avoir informé les familles ;
   - sur chaque fiche, cochez le consentement et prenez 2 ou 3 photos de face ;
   - ouvrez « Pointage par caméra » sur le poste relié à la caméra et cliquez sur
     « Démarrer » ;
   - vérifiez chaque jour les « Passages à vérifier ».

### Reconnaissance des articles par photo
Module fourni (`addons/vision/`), à activer dans Modules complémentaires.

1. **Apprendre :** dans **Gestion des articles → Reconnaissance par photo**, prenez 5 à 10
   photos de chaque article avec la caméra de la caisse. Le bouton « Apprendre les photos des
   fiches » donne un premier départ.
2. **Vendre :** en caisse, touchez le bouton violet **👁** avec l'article dans le cadre, puis
   touchez le bon article parmi les propositions.
3. **Corriger :** si l'article n'est pas proposé, cherchez-le par son nom. La photo est alors
   apprise.

Le même bouton existe au restaurant, et « Reconnaître l'article » apparaît dans la fenêtre
caméra de l'inventaire, des étiquettes, de la pharmacie et de la recherche d'articles.

**Caisse en continu (1.2) :** dans la fenêtre caméra, cadre vide, touchez **« 🧱 Apprendre le
fond »** : le mur et le comptoir sont alors ignorés. Cochez **En continu** et **Ajout
automatique** : chaque article posé et reconnu avec certitude est ajouté au panier ; retirez-le
pour passer au suivant. **↶ Annuler** retire l'ajout. Activez l'ajout automatique seulement
quand tous les articles présentés devant la caméra ont été appris.

Le module reconnaît **les articles qui lui ont été appris**. Pour les articles presque
identiques, préférez le code-barres. Guide : `addons/vision/README.md`.

### Assistance à distance (module MARWA REMOTE ASSIST)
Module fourni (`addons/marwa_assist/`), à activer dans Modules complémentaires. Menu
**Configuration → Assistance à distance**.

1. **Assistance à distance** :
   - décrivez le problème ;
   - donnez à High Tech Niger le **code boutique** et l'**identifiant du poste** affichés
     (bouton WhatsApp).
2. **Partager mon écran** : acceptez la connexion dans la fenêtre RustDesk qui s'ouvre. Le
   technicien **voit** l'écran ; vous gardez la souris et le clavier. La caisse continue de
   vendre normalement.
   Sur un poste équipé de l'agent (kit 1.1), rien à cliquer dans RustDesk : envoyez l'identifiant et le
   **code d'accès** affichés au technicien.
3. **Autoriser le contrôle à distance** :
   - un gérant ou le propriétaire saisit son identifiant et son mot de passe ou PIN ;
   - choisissez la durée ;
   - cochez **Clavier** dans la fenêtre RustDesk ;
   - à la fin du délai, le technicien revient en observation.
4. **Terminer** : sur la page ou dans le bandeau. Ou cliquez **Disconnect** dans la fenêtre
   RustDesk : la coupure est immédiate.

Un bandeau reste affiché sur toutes les pages pendant l'assistance : bleu en observation, rouge
avec compte à rebours en contrôle.

Les responsables trouvent aussi :
- **Postes équipés** : kits d'installation des PC et du technicien ;
- **Diagnostic** : état technique à envoyer, sans donnée de vente ;
- **Historique** ;
- **Réglages** : adresse et clé du serveur HTN, durée maximale du contrôle.

N'ouvrez à l'écran que ce qui est utile : l'écran partagé peut montrer des données de clients
(loi n° 2017-28). Installation et sécurité : `addons/marwa_assist/README.md`.

### Hôtel
À installer une fois : **Système & maintenance → Modules complémentaires → Hôtel → Installer**.
Ensuite, si l'établissement est d'abord un hôtel : **Paramètres → Mode de fonctionnement → Hôtel**.
Guide complet : `addons/hotel/README.md`.
1. **Chambres et tarifs** (Hôtel → Chambres, tarifs et réglages) : adaptez les types de chambres (prix, prix
   week-end, capacité), les numéros de chambres et les prestations (petit-déjeuner, blanchisserie…).
2. **Plan des chambres** : l'écran de la réception, à garder ouvert (bouton plein écran). Chaque chambre a une
   couleur : vert libre, orange à nettoyer, bleu occupée, turquoise arrivée attendue, violet départ du jour,
   rouge départ dépassé, gris hors service. Touchez une chambre pour agir.
3. **Réserver** : Nouvelle réservation → dates → type de chambre (le nombre de chambres libres s'affiche) →
   chambre (ou plus tard) → client → acompte éventuel.
4. **Arrivée** : ouvrez la réservation (tableau de bord, plan ou planning) → complétez la pièce d'identité →
   « Enregistrer l'arrivée ». Client sans réservation : bouton **Client sans réservation**.
5. **Pendant le séjour** : « Ajouter une prestation » porte une consommation au folio ; « Encaisser » reçoit un
   paiement ; « Changer de chambre / prolonger ».
6. **Chaque matin : Audit de nuit** (tableau de bord) : les nuitées de la nuit passée sont portées aux folios.
7. **Départ** : « Enregistrer le départ » encaisse le solde, imprime la facture et passe la chambre à nettoyer.
8. **Ménage** : la gouvernante ouvre « Ménage des chambres » sur son téléphone : Commencer → Terminé.

### Comptabilité générale
À installer une fois : **Système & maintenance → Modules complémentaires → Comptabilité générale → Installer**.
Tout se trouve ensuite dans la catégorie **Comptabilité** du menu, et dans les onglets en haut de chaque page comptable.
1. **Mise en place** (première ouverture) : choisissez le plan (SYSCOHADA révisé recommandé), les dates du
   premier exercice, puis validez. Les journaux AC, VT, CA, BQ, MM, OD et AN sont créés.
2. **Soldes de départ** : une écriture dans le journal **AN**, au premier jour de l'exercice (caisse, banque,
   clients, fournisseurs, capital…).
3. **Saisie** : journal, date, pièce, libellé, puis une ligne par compte :
   - tapez le numéro ou le nom du compte et choisissez dans la liste ;
   - débit ou crédit ;
   - le tiers est demandé pour un compte client ou fournisseur ;
   - **Équilibrer** porte l'écart sur la ligne en cours, ou sur le compte de banque / caisse du journal.
   - « Enregistrer au brouillard » laisse l'écriture modifiable ; « Enregistrer et valider » la numérote
     définitivement.
4. **Écritures de la caisse** :
   - le logiciel prépare les écritures des ventes, achats, règlements, dépenses, recettes des modules et
     banques, une par journée terminée ;
   - contrôlez-les dans **Écritures et brouillard**, puis **Valider la sélection** ;
   - les comptes utilisés se règlent dans **Paramètres comptables** (par exemple un compte par catégorie
     de dépense).
5. **Corriger une écriture validée** : ouvrez-la, puis **Extourner** avec un motif. L'écriture inverse est
   passée ; saisissez ensuite la bonne écriture.
6. **Lettrage** : **Consultation et lettrage**, choisissez le compte (401, 411…) et le tiers. Cochez la facture
   et son règlement : quand l'écart est nul, **Lettrer la sélection**.
7. **États** :
   - journal, grand livre, balance, balance des tiers ;
   - bilan et compte de résultat SYSCOHADA ;
   - « Validées seulement » pour les états définitifs ;
   - **Imprimer**, **CSV** ou **PDF**.
8. **Fin de mois** : **Exercices et clôture → Verrouiller une période**. Plus aucune écriture ne peut alors
   être passée avant cette date.
9. **Fin d'exercice** : validez tout le brouillard, puis tapez CLOTURER et cliquez **Clôturer**. Une
   sauvegarde est faite d'abord, et les à-nouveaux sont passés dans l'exercice suivant. Cette étape est
   irréversible.

### Banques et coffres
Menu **Trésorerie & banques → Banques et coffres**.
1. Créez vos comptes : banque, coffre, argent gardé par le propriétaire, mobile money, avec leur solde actuel.
2. Après chaque clôture de caisse : **Clôtures à verser**, puis choisissez le compte, le montant, le n° de
   bordereau et **Verser**. Vous pouvez répartir entre plusieurs comptes. Imprimez le reçu.
3. **Dépôt**, **Retrait** (motif obligatoire), **Virement** entre comptes, **Frais / intérêts**.
4. Cliquez un compte pour voir son **relevé** (solde ligne par ligne, export CSV/PDF).
5. Une erreur ? Le propriétaire **annule** l'opération avec un motif. Elle reste visible, barrée, et le
   solde est rétabli.

### Pressing : écran des dépôts en cours
**Pressing → Écran des dépôts en cours** (ou le bouton dans « Suivi des vêtements ») :
- colonnes Déposés / En traitement / Prêts, retards en rouge, express et dépôts du jour signalés ;
- un clic sur « En traitement » ou « Prêt » fait avancer le dépôt ;
- actualisation toutes les 15 secondes, plein écran ;
- le lien **Affichage pour les clients** ouvre un écran à placer côté comptoir : numéro et prénom des
  dépôts prêts, sans téléphone ni montant.

### Imagerie médicale (clinique)
**Clinique → Imagerie médicale**, et la carte « Imagerie » du dossier patient et de la consultation.
- Le médecin clique **Demander un examen**. Le bon imprimé porte le n° de demande et le n° de dossier,
  que le manipulateur saisit dans l'appareil : les images arrivent toutes seules dans le bon dossier.
- Un examen « à rattacher » se relie au patient après vérification de son identité.
- Visionneuse :
  - glisser = contraste ; molette = loupe ; double-clic = ajuster ;
  - outils règle (mm), angle, zone, flèche, texte ;
  - flèches du clavier = image suivante.
- Compte rendu : choisissez un modèle, complétez, **Enregistrer** puis **Valider** ; **Compte rendu**
  imprime le document avec les images.
- Réglage des appareils : `addons/clinique/README.md`.

### Assistant vocal (module MARWA VOICE AI)
Module fourni (`addons/marwa_voice/`), à activer dans Modules complémentaires.

- POS Marwa **annonce à voix haute** :
  - les ventes (total et monnaie à rendre) ;
  - les ventes refusées et leur motif ;
  - les coupures de connexion pendant une validation ;
  - les échecs d'impression ;
  - le stock insuffisant ;
  - les messages d'erreur des pages.
- Les messages restent affichés à l'écran.
- **Voix** : la voix locale Piper est livrée avec POS Marwa et se met en place toute seule. Il
  n'y a rien à installer et aucun Internet n'est nécessaire. `windows\installer-voix.bat`
  vérifie l'installation.
- **Écran cuisine** : chaque commande envoyée est lue à voix haute (numéro, table, plats,
  quantités, remarques). Les ajouts et les annulations, avec leur motif, sont aussi lus. Le
  bouton 🔊 d'un bon le relit.
- **Plan de salle** : le serveur entend « Table T3 : deux plats prêts à servir ».
- **À la caisse** : icône 🔊 dans l'en-tête pour couper ou réactiver la voix du poste, ↻ pour
  répéter.
- **Configuration → Assistant vocal** :
  - événements lus et leur niveau ;
  - volume et débit ;
  - plage de silence ;
  - réglages de chaque poste ;
  - diagnostic et historique.

Guide : `addons/marwa_voice/README.md`.

### Caméras et surveillance des articles (module Reconnaissance 1.1)
1. **Configuration → Caméras :** ajoutez une webcam ou caméra USB, une carte d'acquisition
   ou une caméra IP (adresse de l'image ou du flux MJPEG). Utilisez « Tester » pour vérifier
   l'image.
2. **Gestion des articles → Surveillance des articles :**
   - tracez la ligne rouge (deux clics) ou la zone (glisser), puis enregistrez le tracé ;
   - touchez « Démarrer la surveillance » et laissez l'écran ouvert sur ce poste.
3. **En fin de journée, « Passages vus / ventes » :** comparez les passages aux ventes et aux
   sorties de stock. Revoyez les passages sans vente (confirmer, corriger, ignorer).

Ce sont des indications à vérifier, pas des preuves. Informez le personnel et la clientèle de
la présence de caméras.

### Pressing
Module fourni (`addons/pressing/`) : à installer depuis Modules complémentaires. Guide :
`addons/pressing/README.md`.

**Caisse pressing** : nouveau dépôt, et retrait par numéro, téléphone ou scan du ticket.
Articles **au kilo** (ex. « Linge au kilo ») : toucher l'article puis saisir le poids avec une virgule (4,5) ; le prix est calculé au kilo. L'unité de chaque article se règle dans Pressing → Tarifs.

**Suivi des vêtements** :
- filtres : en cours, en retard, retrait proche, prêts, impayés, retirés ;
- jours de retard affichés ;
- export CSV ou PDF.

**Statuts** : Déposé, En attente, En traitement, Prêt, Partiellement retiré, Retiré, Annulé.

**Retrait partiel** : sur la fiche du dépôt, cochez les pièces remises. Chaque retrait est
daté.

**Historique d'un client** : lien depuis la fiche du dépôt.

## Mise à jour du logiciel (Configuration → Mise à jour du logiciel)
1. Décompressez le fichier ZIP de la nouvelle version reçu de l'auteur (dossier `pos-marwa`).
2. Ouvrez **Configuration → Mise à jour du logiciel**, cliquez sur « Choisir », sélectionnez le
   dossier `pos-marwa` et saisissez votre mot de passe.
3. **Envoyer et vérifier** : les fichiers sont envoyés et contrôlés (version, fichiers complets).
4. **Sauvegarder puis installer** : sauvegarde de la base et du code, copie, puis mise à jour
   automatique de la base. Vos données, `.env`, photos et sauvegardes ne sont pas touchés.
En cas de problème : « Revenir à la version précédente » sur la même page.

Les modules complémentaires actifs (Clinique, Pressing, Reconnaissance…) sont mis à jour
automatiquement à la première page ouverte après la mise à jour. Si l'un d'eux ne peut pas
l'être, ses écrans sont masqués et Configuration → Modules indique « Mettre à jour » avec la
cause.

## Licence, contrat, À propos
- **À propos de POS Marwa** (en bas du menu) : auteur, version, contacts, contrat de licence.
- **Licence et activation** : communiquez l'identifiant d'installation à l'auteur
  (+227 92 08 12 82 / 87 22 52 02), collez la clé reçue, cliquez sur « Activer ».
- Sans licence, après 30 jours d'essai, POS Marwa passe en lecture seule (données conservées).

## Affichage
- Le **menu de gestion** est à droite ; le remettre à gauche : Paramètres → Position du menu.
- Le menu est rangé **par catégories** (Gestion des ventes, Gestion des articles, Comptabilité…) :
  - seule la catégorie de la page affichée est ouverte ;
  - cliquez sur une catégorie pour voir ses titres ;
  - la zone **Rechercher dans le menu** trouve une page en tapant quelques lettres ; « Entrée » l'ouvre.
- L'écran de connexion affiche le numéro d'**assistance** (modifiable dans les paramètres).

## TVA et rapport de TVA
- **Paramètres → TVA** : choisir *Pas de TVA*, *TVA générale* (taux au choix : 19 %, 18 %…,
  boutons rapides) ou *Taux défini article par article*. Les prix de vente restent saisis
  **TVA comprise (TTC)** : la TVA de chaque ligne est calculée à la vente et indiquée sur
  le ticket (« dont TVA 19 % »), la facture et le devis (Total HT, TVA, TTC).
- En TVA générale, cocher **Exonéré de TVA** dans la fiche des articles non taxés.
- Changer de taux ne modifie pas les ventes passées (leur TVA est mémorisée).
- **Rapports → TVA** : ventes TTC, retours, base HT et TVA nettes par taux et par mois,
  boutons **CSV** et **PDF**. C'est une aide à la déclaration, à rapprocher de la comptabilité.

## Export PDF
Chaque rapport et chaque liste exportable (ventes, stock, mouvements, péremptions,
créances, dépenses…) a un bouton **PDF** à côté du bouton CSV : le fichier est créé par
POS Marwa, sans Internet. (L'arabe ne s'imprime pas dans ces PDF : les en-têtes restent en
français ; utiliser « Imprimer / PDF » du navigateur pour un document en arabe.)

## Paramètres utiles (v1.2)
- **Langue** : langue par défaut du logiciel ; chacun peut choisir la sienne dans *Mon
  compte* ou sur l'écran de connexion (français, anglais, arabe, haoussa, espagnol,
  portugais, turc). La traduction haoussa est à faire relire par un locuteur.
- **Devise** : FCFA par défaut ; choisir une autre devise ou en saisir une (symbole,
  position, décimales). Les montants déjà enregistrés ne sont pas convertis.
- **Mobile money** : liste des opérateurs proposés à l'encaissement.
- **Modèles** : ticket (Classique, Compact, Moderne) et facture / devis (Classique, Moderne,
  Épuré), lien *aperçu* ; QR code activable.
- **Imprimantes** : voir INSTALLATION_WAMPSERVER.md § 7.

## Restaurant (si le module est activé)

**Serveur**
1. *Plan de salle* : les tables vertes sont libres, oranges occupées, bleues « addition
   demandée » ; une cloche violette signale des plats prêts, un badge jaune des articles
   non encore envoyés.
2. Toucher une table libre → choisir le nombre de couverts → la commande s'ouvre.
   *À emporter* / *Livraison* : saisir le nom et le téléphone du client.
3. Toucher les plats (2–9 pour la quantité). *Note plat* : « sans piment », « bien cuit »…
   Le service (boisson, entrée, plat, dessert) est automatique ou choisi en haut.
4. **Envoyer en cuisine** : un bon part à chaque poste (cuisine, grill, bar).
5. Quand la cuisine marque un plat « prêt », la table clignote : servir puis *Servi*.
6. *Table* : transférer vers une table libre ou fusionner avec une table occupée.
7. *Annuler* : un article non envoyé se retire librement ; un article envoyé exige un
   motif et l'accord d'un responsable ; cocher « déjà préparé » le déclare en perte.
8. *Addition* imprime la pré-note (montant par personne si plusieurs couverts).

**Caissier / responsable** — *Encaisser* : choisir les articles payés (addition séparée
par client) ou *÷ parts* pour diviser en parts égales ; la table se libère quand tout est payé.

**Cuisine / bar** — *Écran cuisine* : *Commencer* puis *Prêt* ; *Servi — retirer* quand le
plat est parti ; ↶ pour revenir en arrière. Le chronomètre passe à l'orange après 10 min
et au rouge après 20 min. Les tickets rouges sont des **annulations**.

**Gestion** — *Salles & tables*, *Fiches techniques* (ingrédients par portion : le stock
d'ingrédients baisse à chaque vente), *Rapport restaurant*.

### Pourquoi une commande n'apparaît pas en cuisine ?
- Un article part sur l'écran du **poste** de sa catégorie (cuisine, bar, grill…). Une
  catégorie sans poste utilise le **poste par défaut** (Paramètres, « cuisine » par défaut).
  Une catégorie au poste `aucun` est servie directement, sans ticket.
- Les articles s'affichent en cuisine après **Envoyer en cuisine** ; s'ils sont encaissés
  sans avoir été envoyés, ils partent en cuisine au moment de l'encaissement.
- Sur l'écran cuisine, vérifier l'onglet du poste (Cuisine, Bar…) en haut de l'écran.
- **Bon cuisine** (commande) ou l'icône imprimante d'un ticket (écran cuisine) imprime le
  bon à tout moment, même déjà préparé.

### Alimentation + restaurant dans le même établissement
- Par défaut un article est vendu « en boutique et au restaurant ». Pour qu'un plat
  n'apparaisse **qu'au restaurant**, choisir « Restaurant uniquement » dans sa fiche (champ
  *Vendu sur*) ou sur sa catégorie ; pour que les articles d'épicerie n'encombrent pas la
  prise de commande, mettre leurs catégories en « Boutique (caisse) uniquement ».
- Les commandes envoyées s'affichent immédiatement sur l'écran cuisine ; le bouton
  **Bon cuisine** de la commande (ou l'icône imprimante d'un ticket sur l'écran cuisine)
  imprime le bon au besoin.

## Fin de journée
*Ma caisse* → compter les billets et pièces par coupure → *Clôturer*. L'état de caisse
(théorique, compté, écart) s'imprime. Tout écart doit être commenté.

## Retours et annulations
- *Historique des ventes* → ouvrir la vente → **Retour / remboursement** : choisir les quantités, le mode de remboursement et le motif.
- **Annuler la vente** (responsable, dans les 24 h) : tout est contre-passé automatiquement.

## Gestion (bouton « Menu »)
Le **Menu de gestion** regroupe tous les modules en icônes rondes (comme l'ancien logiciel) :
ventes, articles, stocks, achats, finances, rapports et configuration. Chaque utilisateur ne
voit que les modules autorisés par son rôle.

- **Réception de marchandises** : Achats → Réceptions → Réception directe (ou depuis une commande). Scanner les articles, saisir quantités, prix d'achat, lot et péremption ; le stock, le coût moyen et la dette fournisseur sont mis à jour.
- **Inventaire** : Inventaires → Démarrer → imprimer la feuille de comptage → saisir les quantités → Valider.
- **Pertes / casse / périmés** : Ajustement de stock (motif obligatoire).
- **Étiquettes** : depuis une fiche article ou par catégorie (planche A4).
- **Rapports** : ventes (par jour, catégorie, vendeur, caisse, mode de paiement, heure), articles, bénéfices, valeur du stock, créances et dettes ; export CSV et impression/PDF.
