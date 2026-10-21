# MARWA VOICE AI — assistant vocal (module `marwa_voice`) — 1.1.0

POS Marwa **parle** : il annonce les ventes, les erreurs de caisse, de paiement et
d'impression, ainsi que les messages d'erreur des pages de gestion.

- La voix est produite **sur place, sans Internet**.
- Elle est lue **sur le poste concerné**.
- Les messages restent **toujours affichés à l'écran** : la voix s'ajoute, elle ne remplace rien.

> Phase 1 = lecture vocale. **Aucun microphone n'est utilisé.** Les commandes vocales
> (« Recherche du lait », « Commande 24 prête »…) viendront dans les phases suivantes (voir la fin).

## Ce que le module annonce (phase 1)

| Événement | Exemple de phrase | Niveau par défaut | Lu par défaut |
|---|---|---|---|
| Vente enregistrée | « Vente enregistrée. Total : douze mille cinq cents francs CFA. Monnaie à rendre : deux mille cinq cents francs CFA. » | Notification | oui |
| Vente refusée par le serveur | « Attention, la vente n'a pas été enregistrée. » + motif exact du serveur | Erreur | oui |
| Connexion coupée pendant la validation | « La connexion au serveur a été interrompue. La vente n'est pas confirmée. Validez à nouveau : elle ne sera pas enregistrée deux fois. » | Erreur | oui |
| Vente déjà enregistrée (nouvelle validation) | « Cette vente était déjà enregistrée. Elle n'a pas été enregistrée une deuxième fois. » | Notification | oui |
| Impression en échec | « La vente est enregistrée, mais l'impression du ticket a échoué. Vous pouvez réimprimer le ticket. » | Erreur | oui |
| Ticket envoyé à l'imprimante | « Ticket envoyé à l'imprimante. » (jamais « imprimé » : l'imprimante ne le confirme pas) | Information | non |
| Stock insuffisant à l'ajout | « Attention, stock insuffisant pour Huile 5 L. Quantité disponible : trois. » | Avertissement | oui |
| Article introuvable, caisse fermée, montant insuffisant, autorisation d'un responsable | phrases courtes | Avertissement | oui |
| Article ajouté + total du panier | « Riz 25 kg, deux. Total du panier : … » | Information | **non** (répétitif) |
| Message d'erreur d'une page de gestion | le message affiché, lu tel quel | Erreur | oui |

Règles respectées :
- **Les chiffres viennent du serveur.** Le total et la monnaie lus sont ceux renvoyés par le
  serveur après l'enregistrement, jamais un calcul de la voix.
- **Pas d'échec annoncé à tort.** Si la connexion se coupe pendant une validation, la vente
  n'est pas déclarée échouée : elle est « non confirmée ». Une nouvelle validation ne peut pas
  créer de doublon (numéro unique du panier). Le serveur répond alors « déjà enregistrée ».
- **Impression et vente sont séparées.** Un échec d'impression ne recrée jamais la vente.

## Restaurant (1.1) : écran cuisine et plan de salle

**Écran cuisine / bar** (`/kitchen`). Chaque nouveau bon est **lu à voix haute** dès son
arrivée. Le son « bip » habituel est gardé.

| Situation | Exemple de phrase |
|---|---|
| Nouvelle commande | « Nouvelle commande numéro vingt-quatre, table six. deux Poulet braisé, remarque : sans piment. trois Fanta trente-trois centilitres. Serveur : Awa. » |
| Plats ajoutés à une commande déjà envoyée | « Ajout pour la commande numéro vingt-quatre, table six. un Riz gras. » |
| À emporter / livraison | « Nouvelle commande numéro sept, livraison, pour Moussa. un demi Viande (kilo). » |
| Annulation | « Attention, annulation. Commande numéro vingt-quatre, table six. Ne pas préparer : un Poulet braisé, motif : client parti. » |
| Bouton 🔊 sur chaque bon | « Je répète. … » (relit le bon, à tout moment) |

Règles de lecture en cuisine :
- **Uniquement les données du bon** : numéro, table, plats, quantités, remarques, motif.
  Aucune priorité n'est inventée et aucune remarque n'est modifiée.
- **Une seule lecture par bon**, même si l'écran se rafraîchit toutes les 5 secondes.
  - Les bons déjà affichés à l'ouverture de l'écran ne sont pas relus ; utilisez 🔊 pour
    cela.
- **Priorité** : une annulation (avertissement) passe avant une nouvelle commande
  (notification).
- **Durée de validité de 10 minutes** pour un bon : même avec beaucoup de commandes en même
  temps, aucun bon n'est écarté de la file.
- **Quantités et unités** dites naturellement : « un demi », « deux et demi »,
  « trente-trois centilitres », « un kilo ».
- **Bon marqué « Prêt »** : il peut aussi être annoncé en cuisine (désactivé par défaut). Il
  n'est annoncé que si le serveur a bien enregistré le changement.

**Plan de salle des serveurs** (`/restaurant`). Quand la cuisine marque des plats prêts, le
poste du serveur annonce : « Table T3 : deux plats prêts à servir. », ou « Commande numéro
neuf, à emporter, pour Awa : plat prêt à servir. ». Le plan se rafraîchit toutes les
10 secondes.

Réglages : Assistant vocal → Réglages, groupes « Écran cuisine / bar » et « Salle ». Sur un
poste précis, « Ce poste » → « Ne pas lire sur ce poste ». Par exemple, ne pas lire la caisse
sur l'écran cuisine.

## Utilisation

- **Indicateur vocal** :
  - à la caisse et à la prise de commande, deux icônes dans l'en-tête : 🔊 couper ou réactiver
    la voix de ce poste, ↻ répéter la dernière annonce ;
  - ailleurs, une petite bulle en bas à gauche, qui affiche la phrase en cours de lecture.
- **Configuration → Assistant vocal** :
  - **Réglages** (responsable) :
    - activer ou désactiver les annonces ;
    - choix de la voix ;
    - volume et débit ;
    - plage de silence (par ex. 22:00 → 06:00), avec ou sans lecture des alertes critiques ;
    - événements lus et leur niveau ;
    - conservation de l'historique.
  - **Ce poste** (chaque utilisateur, gardé dans le navigateur de ce poste) :
    - nom du poste ;
    - voix coupée, volume, débit ;
    - voix de ce poste ;
    - modules à ne pas lire ici (par ex. ne rien lire de la caisse sur le poste du magasin) ;
    - masquer l'indicateur ;
    - **Écouter** un essai.
  - **Diagnostic** : état de Piper, du service et du cache, test de Piper, statistiques.
  - **Historique** : 200 dernières annonces, avec leur résultat :
    - lu ;
    - doublon ;
    - groupé ;
    - silence ;
    - expiré ;
    - interrompu ;
    - erreur de voix.

### Priorités et file d'annonces

- **Une seule phrase à la fois.** Ordre de lecture : critique, puis erreur, avertissement,
  notification, information. À niveau égal, ordre d'arrivée.
- **Interruption** : une alerte **critique** interrompt une annonce moins importante, qui est
  reprise ensuite.
- **Doublons** : la même annonce n'est pas répétée dans les 10 secondes.
- **Regroupement** : au-delà de 3 alertes du même groupe en attente, une seule phrase
  résume. Par exemple, 20 produits sous le seuil donnent « 20 produits sont sous leur seuil
  minimal ». Les alertes de stock arrivent en phase 3.
- **Annonces périmées** : une annonce en attente trop ancienne n'est plus lue. Délais :
  - information : 8 s ;
  - notification : 20 s ;
  - avertissement : 60 s ;
  - erreur : 2 min ;
  - critique : 10 min.

## Voix

| Voix | Où | Qualité | Internet |
|---|---|---|---|
| **Piper** (recommandé) | serveur POS Marwa : le son est envoyé au poste par le réseau local | voix française naturelle « siwis » | jamais |
| Voix de Windows | chaque poste (Microsoft Hortense, Julie, Paul…) | correcte | jamais si « voix locales uniquement » (réglage par défaut) |

Le réglage « Automatique » utilise Piper s'il est installé, sinon la voix de Windows. Si Piper
échoue sur une phrase, la voix de Windows prend le relais. Sans aucune voix, rien n'est lu :
les messages restent affichés et l'historique note « erreur_voix ».

### Voix Piper livrée dans le dossier (1.1) — aucune installation, aucun Internet

- **Contenu livré** : POS Marwa contient, dans `addons/marwa_voice/piper/`, le moteur
  **Piper pour Windows** et la voix française **« siwis »**.
  - C'est la meilleure qualité disponible en français pour Piper : il n'existe pas de voix
    française de niveau « high » dans Piper ; les voix françaises existent en « low » et
    « medium ».
  - 147 fichiers, 77,5 Mo, découpés en fragments de 1 Mo au plus pour passer par la mise à
    jour dans le navigateur (limites d'envoi de WampServer).
- **Mise en place automatique** : au premier usage, les fichiers sont reconstitués dans
  `storage/voix/`. Cela prend moins d'une seconde, une seule fois.
  - **Chaque fichier est vérifié par son empreinte SHA-256** ; un fragment abîmé est refusé
    (test automatique).
  - Commande équivalente : `php bin\voix.php deployer`, ou double-clic sur
    `windows\installer-voix.bat`, qui vérifie aussi par un essai.
- **Allègement vérifié** : seuls les dictionnaires de prononciation français et anglais sont
  livrés, et le modèle arabe est retiré. Le son produit a été comparé à celui de l'archive
  complète : **identique octet pour octet** (synthèse sans aléa).
- **Volume** : le son est normalisé (crête à 90 %). Mesure honnête : la voix siwis sort déjà à
  pleine échelle, ce traitement ne change donc rien pour elle. Le volume réel dépend des
  haut-parleurs, du volume Windows et du réglage « Volume » du poste.
- **Diction** : une pause de 0,35 s sépare chaque phrase, pour que la liste des plats se suive
  facilement en cuisine.
- **Prérequis** : l'extension PHP `proc_open` (active par défaut dans WampServer) et le
  runtime Visual C++ installé avec WampServer.
- **Serveur Linux** : le programme livré est pour Windows. `php bin/voix.php installer`
  télécharge la version Linux, vérifiée par SHA-256 ; la voix livrée est utilisée telle quelle.
- **Voix plus légère** (PC très modeste) : `php bin\voix.php installer siwis-low` (Internet
  nécessaire), puis choix de la voix dans Assistant vocal → Réglages.

### Service vocal (plus rapide)

- Sans service, Piper est lancé pour chaque phrase **nouvelle** : environ 1 à 2 s. Les phrases
  déjà dites sont reprises du cache, instantanément.
- Le **service vocal** garde la voix en mémoire : environ 0,15 à 0,25 s par phrase nouvelle,
  mesuré sur le serveur de test.
- `demarrer-pos-marwa.bat` le lance automatiquement (fenêtre réduite « POS Marwa - service
  vocal ») quand Piper est installé.
- À la main : `windows\service-vocal.bat`, ou `php bin\voix.php service`.
- Le service n'écoute que sur `127.0.0.1` (port 59125, réglage `voix_piper_port`). Il exige un
  jeton secret (`storage/voix/service.token`, recréé à chaque démarrage) et n'écrit que dans
  `storage/voix/cache/`.

## Fonctionnement technique

```
écran (pos.js…) ──« pm:event » { event, data }──▶ voix.js (poste)
                                                   │ réglages responsable + poste + silence
                                                   │ phrase (voix-core.js, données reçues seulement)
                                                   │ file à priorités (doublons, regroupement, expiration)
                                                   ├─▶ POST /voix/tts ─▶ PiperService ─▶ cache │ service persistant │ Piper direct
                                                   │       son WAV ◀──────────────────────────────┘
                                                   ├─▶ secours : voix du système (speechSynthesis)
                                                   └─▶ POST /voix/journal (par lots, historique)
```

| Fichier | Rôle |
|---|---|
| `public/voix-core.js` | Nombres en lettres (« quatre-vingts », « deux cent mille »), montants selon la devise des paramètres, heures, quantités, nettoyage des textes ; file d'annonces ; phrases de chaque événement. Sans dépendance, testé par `tests/js/voix.test.js` (66 vérifications). |
| `public/voix.js` | Moteur du poste : réglages, file, lecture (Piper par `<audio>`, sinon Web Audio ; sinon voix du système), indicateur, historique. Chargé sur toutes les pages tant que le module est actif. |
| `public/voix-reglages.js` | Page « Assistant vocal » : réglages du poste, essai, test de Piper. |
| `src/VoiceService.php` | Catalogue des événements, réglages, historique (contrôlé), purge. |
| `src/PiperService.php` | Piper : chemins, voix, cache, service persistant, lancement direct (un seul à la fois, verrou). |
| `src/VoiceController.php`, `routes.php` | Pages et adresses (permissions vérifiées par le serveur à chaque requête). |
| `bin/voix.php` | `installer`, `etat`, `test "texte"`, `service`, `vider-cache`. |
| `migrations/0001_marwa_voice.sql` | Table `marwa_voice_log` (historique). |

Changements dans POS Marwa (2.5.0) :
- `public/assets/js/pos.js` : émet les événements de caisse. Sans le module, cela n'a aucun
  effet.
- Message de coupure réseau corrigé : il disait à tort « la vente n'a pas été enregistrée ».
- `public/index.php` : la politique de sécurité autorise les sons générés localement
  (`media-src 'self' blob:`) ; rien d'autre n'est ouvert.
- `demarrer-pos-marwa.bat` :
  - lancement du service vocal ;
  - option `--autoplay-policy=no-user-gesture-required` de la fenêtre POS Marwa, pour que
    la première annonce ne soit pas bloquée par le navigateur.

Ajouter un événement (phases suivantes) : une ligne dans `VoiceService::catalogue()`, une phrase
dans `PHRASES` (voix-core.js), et l'écran émet
`document.dispatchEvent(new CustomEvent('pm:event', { detail: { event: 'stock.low', data: {...} } }))`
— ou appelle `PMVoice.say('stock.low', {...})`.

## Sécurité et confidentialité

- **Permissions** :
  - `marwa_voice.use` : entendre les annonces, régler son poste. Donnée au caissier, au
    serveur, au cuisinier, au magasinier, etc. L'auditeur ne l'a pas.
  - `marwa_voice.manage` : réglages, diagnostic, historique. Donnée au propriétaire et au
    gérant.
- **La voix n'ajoute aucun accès.** Elle lit uniquement ce que l'écran de l'utilisateur
  affiche ou vient de recevoir.
- **Texte vers Piper** :
  - 400 caractères au plus ;
  - caractères de contrôle retirés ;
  - transmis par l'entrée standard, jamais dans une ligne de commande ;
  - programme lancé sans interpréteur de commandes. Un texte piégé (`"; rm …`, `$(…)`) est
    simplement lu (test automatique).
- **Historique** :
  - contrôlé par le serveur : événement connu, niveau, résultat, longueur, 50 lignes par envoi ;
  - jeton CSRF obligatoire ;
  - seulement à partir du niveau choisi (avertissement par défaut) ;
  - supprimé après 90 jours (réglable) ;
  - **aucun son n'est enregistré**.
- **Aucun microphone** dans cette phase. Aucune donnée vocale ne sort du réseau local.
- **Loi n° 2017-28 du Niger sur les données personnelles** : les annonces peuvent contenir
  des noms d'articles ou des montants, entendus par les clients présents. Réglez le volume et
  les événements lus selon l'emplacement du poste ; par exemple, ne lisez pas les montants
  sur un poste à proximité du public si ce n'est pas souhaité.

## Diagnostic des problèmes

| Symptôme | Cause probable | Solution |
|---|---|---|
| Rien n'est lu, l'indicateur n'apparaît pas | module désactivé, assistant désactivé dans les Réglages, ou compte sans `marwa_voice.use` | Modules complémentaires ; Assistant vocal → Réglages ; Rôles & permissions |
| L'indicateur affiche « Touchez l'écran pour activer la voix » | le navigateur bloque le son avant le premier clic | touchez l'écran ; ouvrez POS Marwa avec `demarrer-pos-marwa.bat` (option incluse) |
| Diagnostic : « Programme absent » | voix livrée pas encore mise en place, ou fragment manquant ou abîmé (copie incomplète du dossier) | `windows\installer-voix.bat` : le message indique le fichier en cause ; recopiez le dossier `addons\marwa_voice` |
| Historique « erreur_voix » : « Aucune voix française installée sur ce poste » | Piper indisponible et pas de voix française dans Windows | installez Piper, ou Windows : Paramètres → Heure et langue → Voix → ajouter Français |
| Première phrase lente (1 à 2 s) | service vocal arrêté | `windows\service-vocal.bat` (ou redémarrer avec `demarrer-pos-marwa.bat`) |
| « Port 59125 indisponible » au lancement du service | le service tourne déjà | rien à faire |
| Voix trop forte en boutique | — | Ce poste → volume ; ou Réglages → plage de silence |

## Désactiver / désinstaller (sans perte de données)

1. **Couper la voix d'un poste** : icône 🔊.
2. **Couper partout** : Assistant vocal → Réglages → décocher « Annonces vocales activées ».
3. **Désactiver le module** : Configuration → Modules complémentaires → Désactiver.
   - Pages masquées, scripts non chargés.
   - **La caisse fonctionne exactement comme avant.**
   - L'historique est conservé.
4. **Supprimer** : désactiver le module, puis supprimer les dossiers `addons/marwa_voice` et
   `storage/voix`. La table `marwa_voice_log` reste dans la base ; aucune donnée n'est
   effacée automatiquement.

## Licences des composants

- **Piper** (moteur de synthèse vocale) : © Michael Hansen / Rhasspy, **licence MIT**. Livré
  pour Windows dans `addons/marwa_voice/piper/`. Détail de tous les composants livrés et textes
  des licences : `piper/LICENCES.md` et `piper/licences/`.
- **Voix « siwis »** (française) : entraînée sur le jeu de données SIWIS (University of
  Edinburgh), **licence CC BY 4.0**. Attribution : « SIWIS French Speech Synthesis Database ».
- **eSpeak NG** (phonétisation, bibliothèque livrée avec Piper) : **licence GPL v3**. Texte
  complet et adresse du code source dans `piper/licences/`. Elle est livrée sans modification
  et exécutée par le programme Piper, séparé de POS Marwa.
- **ONNX Runtime** (Microsoft) : licence MIT.

## Tests réalisés (1.1 : voix livrée, cuisine, salle)

- **Tests JavaScript** : 76 vérifications. S'ajoutent à celles de la phase 1 :
  - phrases de cuisine : nouvelle commande, ajout, annulation avec motif, livraison, demi ;
  - plats prêts en salle ;
  - unités et accords ;
  - un bon de cuisine encore lu après 2 minutes de file.
- **Test PHP** :
  - paquet sans fichier de plus de 1 Mo ;
  - déploiement Windows simulé : 147 fichiers reconstitués, chacun avec la bonne empreinte
    SHA-256 ; un deuxième passage ne refait rien ;
  - **fragment abîmé refusé**, voix en place intacte ;
  - écran cuisine qui charge la voix ;
  - bons repérés comme « ajout » ;
  - annonces cuisine et salle actives, bon valable 10 minutes.
- **Chromium avec la vraie voix Piper : 12/12.**
  - Une commande est prise par l'adresse de la tablette du serveur sur une table libre :
    2 Poulet braisé « sans piment », 3 Soda 33 cl.
  - Envoi en cuisine : « Nouvelle commande numéro sept, table T2. deux Poulet braisé test,
    remarque : sans piment. trois Soda trente-trois centilitres. Serveur : Admin Test. »
  - Le bon n'est lu qu'**une seule fois** malgré les rafraîchissements.
  - Le bouton 🔊 relit le bon.
  - Ajout : « Ajout pour la commande numéro sept… ».
  - Plat annulé par le serveur : « Attention, annulation… motif : client parti. »
  - La cuisine marque le bon « Prêt » : le plan de salle annonce « Table T2 : deux plats prêts
    à servir. ».
  - Historique enregistré, aucune erreur JavaScript.
- **Non testé** : sur un vrai PC Windows. Le programme Piper Windows livré a été vérifié
  fichier par fichier, mais il n'a pas pu être exécuté ici.

## Tests réalisés (phase 1)

- **Tests JavaScript** (`node tests/js/voix.test.js`), 66 vérifications :
  - nombres en lettres (accords, « et un », « quatre-vingts », milliers et millions) ;
  - montants et quantités ;
  - nettoyage des textes ;
  - phrases ;
  - plages de silence ;
  - file : priorité, doublons, regroupement de 20 alertes en une, expiration, interruption,
    file pleine.
- **Test PHP** (dans `tests/run.php`) :
  - installation et droits par rôle ;
  - pages ;
  - réglages refusés : moteur inconnu, plage incomplète ;
  - réglages enregistrés et audités ;
  - historique : nettoyage, troncature, refus des valeurs inconnues ou trop nombreuses, CSRF,
    purge ;
  - Piper : son WAV réel, texte piégé sans exécution, cache ;
  - refus pour le caissier (réglages généraux) et l'auditeur (tout) ;
  - désactivation sans perte.
- **Chromium, vraie caisse, base de test, Piper réel** : 18 vérifications sur 18, résumées
  ci-dessous.
  - **Ventes et caisse** :
    - vente lue avec le total et la monnaie du serveur ;
    - impression en échec annoncée (imprimante réseau injoignable), sans dire que la vente a
      échoué ;
    - code inconnu annoncé ;
    - stock insuffisant annoncé ;
    - refus réel du serveur annoncé avec son motif (stock négatif interdit).
  - **Coupure réseau** :
    - coupure simulée **après** l'enregistrement par le serveur : la vente est annoncée
      « non confirmée » ;
    - la nouvelle validation donne « déjà enregistrée » ;
    - la base ne contient **qu'une seule vente**.
  - **File et réglages** :
    - 5 événements identiques donnent 1 seule annonce ;
    - voix coupée : rien n'est lu ;
    - Piper arrêté et aucune voix sur le poste : « erreur_voix » journalisée, aucune erreur
      JavaScript ;
    - message d'erreur d'une page de gestion lu.
  - **Correction trouvée par ce test** : la politique de sécurité de POS Marwa bloquait la
    lecture du son généré (`blob:`). Corrigé (`media-src 'self' blob:`), avec en plus une
    lecture de secours par Web Audio.
- **Non testé** :
  - sur un vrai PC Windows avec haut-parleurs ;
  - la voix « Microsoft Hortense » de Windows ;
  - les scripts `.bat` (écrits pour Windows, non exécutés ici) ;
  - l'installateur sous Windows (le même code a été exécuté sous Linux avec les fichiers Linux).

  Ces essais sont à faire sur place avant la mise en service.

## Phases suivantes (non réalisées dans cette version)

| Phase | Contenu | Remarques honnêtes |
|---|---|---|
| 2 — Caisse vocale | recherche d'article, prix, quantité, « ajoute deux sacs de riz » avec confirmation à l'écran | demande la reconnaissance vocale (phase 5) ; aucune commande vocale ne validera seule un paiement, un remboursement ou une suppression |
| 3 — Stock et inventaire | alertes de seuil, ruptures, écarts d'inventaire lus, regroupés | le regroupement est prêt dans la file |
| 4 — Cuisine | ✅ annonces (1.1) faites : nouvelles commandes, ajouts, annulations, relecture, plats prêts en salle. Reste : commandes **vocales** du cuisinier (« Commande 24 prête ») | demande la reconnaissance vocale (phase 5) ; validation à l'écran toujours exigée |
| 5 — Reconnaissance vocale locale | Whisper (whisper.cpp) sur le serveur, compréhension des intentions par règles d'abord | à mesurer sur le PC réel (Whisper « small » est lent sans carte graphique) ; **haoussa** : aucun modèle Piper haoussa n'existe ; Whisper reconnaît le haoussa avec une précision faible, à mesurer avant d'être proposé |
| 6 — Déploiement | essais multi-postes, sans Internet, sur place | — |
