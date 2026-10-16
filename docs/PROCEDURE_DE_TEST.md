# Procédure de test

## 1. Tests automatisés

### Suite PHP (unitaires + fonctionnels) — `php tests/run.php`

Crée la base `marwa_test` à partir des migrations, puis exécute **61 tests / 306 assertions** :

| Fichier | Couvre |
|---|---|
| `Unit/PricingTest` | Tarif distance+durée, minimum, majoration de nuit après minuit, arrondi, règle la plus spécifique, estimation approximative signalée, hors zone |
| `Unit/CommissionTest` | Modèles %, fixe, mixte, abonnement, min/max, exemple 3 000 → 300 / 2 700, contrat individuel prioritaire, règles expirées ignorées, répartition équilibrée avec promo financée par le commerce, TVA |
| `Unit/LedgerTest` | Écriture équilibrée, refus des écritures déséquilibrées, idempotence, **immuabilité (triggers)**, contre-passation |
| `Unit/StateMachineTest` | Transitions autorisées par rôle, états terminaux, transition invalide refusée |
| `Unit/HelpersTest` | Téléphones nigériens, dates JJ/MM/AAAA, validation en liste blanche, géométrie |
| `Feature/AuthTest` | Inscription, hachage, doublons, prestataire « en attente », jetons, messages d'erreur génériques, verrouillage, CSRF, compte suspendu, réinitialisation par OTP |
| `Feature/AuthorizationTest` | 401/403 par rôle et permission, commande d'un autre client, commerce concurrent, finances d'un autre partenaire, fichiers privés |
| `Feature/RideFlowTest` | Parcours complet d'une course en espèces (commission, solde, reçu, historique, évaluation), double encaissement refusé, désistement et réaffectation, annulation, anti-spam, expiration sans prestataire |
| `Feature/NegotiationTest` | Bornes de prix, contre-proposition, acceptation explicite, second chauffeur refusé, limites anti-spam, expiration, acceptation du prix client |
| `Feature/ConcurrencyTest` | **5 processus acceptent la même commande au même instant : un seul l'obtient** (Linux, `pcntl`) |
| `Feature/PaymentTest` | Paiement électronique : idempotence, signature falsifiée refusée, montant altéré refusé, rejeu sans effet, double paiement refusé ; remboursement automatique après annulation ; échec ; compte entreprise (plafonds, créance, net chauffeur) |
| `Feature/FinanceTest` | Remboursement plafonné, demande assistance / approbation finance, versements et reversements, dépenses et tableau de bord issus de la base, 12 rapports + exports CSV/XLSX/PDF valides, audit des commissions immuable |
| `Feature/MerchantFlowTest` | Repas avec variantes et stock, prix recalculé par le serveur, livreur selon temps de préparation, OTP (code jamais transmis au livreur), soldes livreur/restaurant, refus avec remise en stock, commerce fermé, masquage des numéros |

Résultat obtenu lors du développement (Linux, PHP 8.3, MariaDB 10.11) : `61 test(s) réussi(s), 0 échec(s), 306 assertion(s)`.

### Tests navigateur (Playwright, facultatifs)

```bash
npm install && npx playwright install chromium
php bin/migrate.php --fresh && php bin/seed.php        # base de démonstration fraîche
php -S 127.0.0.1:8080 -t public bin/dev-router.php     # ou Apache/WampServer
BASE_URL=http://127.0.0.1:8080 node tests/e2e/ui-smoke.mjs      # 55 écrans des 6 rôles
BASE_URL=http://127.0.0.1:8080 node tests/e2e/full-journey.mjs  # parcours complet (base fraîche)
php -S 127.0.0.1:8099 tests/e2e/fake-osrm.php                   # faux OSRM si pas d'accès Internet (OSRM_URL=http://127.0.0.1:8099)
BASE_URL=http://127.0.0.1:8080 node tests/e2e/features.mjs      # téléphone : autorisation GPS, taxis proches, navigation, photos, push
BASE_URL=http://127.0.0.1:8080 node tests/e2e/i18n.mjs          # interface en haoussa, zarma, anglais
BASE_URL=http://127.0.0.1:8080 node tests/e2e/mobile.mjs        # écrans sur Android 360 px et iPhone
BASE_URL=http://127.0.0.1:8080 node tests/e2e/live-ops.mjs      # connexion par SMS, se souvenir de moi, favoris, suivi en direct, promotions, notifications
```

Exécutez `full-journey.mjs` puis `features.mjs` sur une base fraîche (le second laisse une course
en cours). La limitation de débit des connexions s'applique aussi aux tests : videz la table
`rate_limits` entre deux campagnes rapprochées.

`features.mjs` vérifie : la fenêtre explicative avant la demande d'autorisation de localisation,
les véhicules proches (icônes), la navigation intégrée (consignes, recalcul après écart, arrivée,
bouton « Je suis arrivé »), l'itinéraire restant côté client, l'ajout de 2 photos à un produit
(compressées sur le téléphone), la photo principale, la couverture, la galerie côté client et
l'affichage d'une notification push par le service worker (message délivré par le protocole
DevTools de Chromium, l'abonnement réel nécessitant le service de push de Google).

`full-journey.mjs` ouvre **deux navigateurs** (client sur ordinateur, chauffeur sur téléphone
Android simulé) : passage en ligne, choix de la destination sur la carte, commande, réception et
acceptation, déplacement GPS réel du navigateur du chauffeur visible sur la carte du client,
étapes de la course, déclaration des espèces, « Paiement confirmé », évaluation, contrôle par
l'administration (répartition et grand livre équilibré). Vérifié avec le serveur intégré de PHP
et avec Apache 2.4 en mode sous-dossier (`/marwa`).

## 2. Procédure manuelle complète (de la commande au règlement)

Préparer : `php bin/migrate.php --fresh && php bin/seed.php`, démarrer le worker
(`php bin/worker.php`). Utiliser trois navigateurs (ou fenêtres privées) : A (client),
B (chauffeur, idéalement un téléphone en HTTPS pour le GPS réel), C (administration).

### Course en taxi avec prix négocié
1. **B** — se connecter `chauffeur@marwa.demo` / `Demo2026!` → basculer « En ligne », accepter la localisation. Le bandeau indique « GPS actif ».
2. **A** — `client@marwa.demo` → *Taxi*. Le départ se remplit avec « Ma position » (ou toucher l'icône 📍 puis la carte). Choisir la destination par la recherche, un favori ou la carte.
3. **A** — vérifier la distance, la durée, les prix par catégorie et la mention « approximative » si OSRM est indisponible. Choisir « Proposer mon prix », saisir un montant dans les bornes, confirmer.
4. **B** — la demande apparaît avec un compte à rebours, la commission et le gain net. Cliquer *Contre-proposer*, saisir un prix et une validité.
5. **A** — la contre-proposition s'affiche (prénom, note, véhicule, distance, expiration). *Accepter*. Le statut passe à « Prestataire affecté », la plaque s'affiche.
6. **B** — *Je suis en route* → se déplacer : **A** voit l'icône de voiture bouger et s'orienter, l'heure de dernière mise à jour et l'heure d'arrivée estimée. Couper le réseau de B : A affiche « position non actualisée » avec l'heure.
7. **B** — *Je suis arrivé* → *Client à bord : démarrer* → *Terminer la course* → saisir le montant encaissé.
8. **A** — statut « Paiement confirmé », bouton *Reçu*, formulaire d'évaluation.
9. **C** — `admin@marwa.demo` → *Commandes* → la course : historique horodaté, sollicitations, négociation, répartition (commission 10 %, net chauffeur). *Versements et soldes* : le chauffeur doit la commission. Enregistrer un *reversement* (référence de reçu) → solde à 0. *Grand livre* : balance équilibrée. *Rapports* → *Commissions* → exporter en PDF/Excel.

### Repas avec paiement électronique (démonstration)
1. **A** — *Repas* → « Le Jardin du Fleuve (démo) » → ajouter « Poulet braisé » (choisir *Alloco*) et 2 jus → *Voir le panier* → adresse sur la carte → code `JARDIN500` → paiement « Mobile Money (démo) » → *Commander*.
2. Page de la passerelle de démonstration → *Simuler un paiement accepté* (la confirmation passe par une notification signée côté serveur).
3. `restaurant@marwa.demo` → *Commandes* → *Accepter* (temps de préparation 5 min) → *En préparation* → *Prête*.
4. `livreur@marwa.demo` (en ligne près du restaurant) → accepter → *Je pars vers le commerce* → *Je suis au commerce* → *Commande récupérée* → *Livrer* : saisir le code affiché chez le client.
5. Vérifier : client « Paiement confirmé » ; restaurant *Finances* : net = ventes − 15 % − 500 FCFA de promo ; livreur *Revenus* : frais de livraison − 10 % ; administration : frais de paiement (démo) en charge, grand livre équilibré.

### Contrôles de sécurité rapides
- Copier l'URL d'une commande du client A et l'ouvrir avec `client2@marwa.demo` → accès refusé.
- Avec un compte client, appeler `/api/admin/dashboard` → 403.
- 5 mots de passe erronés → compte verrouillé 15 minutes.
- Ouvrir `/.env`, `/config/app.php`, `/storage/logs/` → 403/404.
- *Paramètres* (admin) : modifier une commission → l'action apparaît dans le *Journal d'audit*.
