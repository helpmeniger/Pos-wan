# POS Marwa 2.0 — améliorations, fichiers, migrations, vérifications

## 1. Analyse préalable (ce qui existait déjà et a été conservé)

L'analyse du code 1.6.1 a montré que plusieurs fondations demandées existaient déjà. Elles ont
été **étendues, pas recréées** :

| Demande | Existant en 1.6.1 | Travail 2.0 |
|---|---|---|
| Stock sans double déduction, caisses simultanées | Ventes en transaction, verrous `FOR UPDATE`, idempotence par identifiant de vente, FEFO | Stock vendable (lots périmés et rappelés exclus) affiché et actualisé dans la caisse ; test réel de 10 ventes simultanées |
| Caisse ouverte à la connexion | Caissiers dirigés vers `/pos` | Caisse du mode : clinique, pressing ou commerce |
| Inventaire | Saisie des quantités, validation par un responsable, écarts en mouvements | Scan, journal des corrections, filtres, rapport, inventaire partiel |
| Étiquettes | Planche simple par article | Concepteur complet : champs, formats en mm, modèles, aperçu |
| Clinique / pharmacie | Module Clinique 1.0 relié à la pharmacie par les ordonnances | Modes d'activité, caisse clinique, contrôle par scan à la délivrance |
| Pressing | Module Pressing 1.0 : dépôts, retrait, acompte | Caisse, retrait partiel, statuts, retards, suivi, historique client |

Les autres demandes ont été ajoutées :
- scan par la caméra ;
- accès mobile par QR code et appareils révocables ;
- lanceur Windows, raccourci et tâche planifiée ;
- application installable sur l'écran d'accueil (PWA).

## 2. Fichiers créés

**Application**
- `app/Services/BusinessMode.php` : modes de fonctionnement, caisse du mode, menus masqués,
  alertes pharmacie.
- `app/Services/DeviceService.php` : enregistrement, approbation et révocation des appareils.
- `app/Services/NetworkService.php` : adresses IP locales, adresse du QR code.
- `app/Controllers/MobileController.php` : page QR code, page mobile `/m`, `/health`,
  gestion des appareils.
- `app/Controllers/LabelController.php` : concepteur d'étiquettes. Il remplace l'ancienne
  action `ProductController::labels`.
- Vues :
  - `app/Views/mobile/index.php`, `landing.php` et `devices.php` ;
  - `app/Views/stock/inventory_report.php`.
- `database/migrations/0006_marwa.sql`.

**Navigateur**
- Scripts `public/assets/js/` :
  - `scanner.js` : lecteur caméra commun ;
  - `inventory.js` ;
  - `labels.js` ;
  - `mobile.js` et `mobile-landing.js` ;
  - `pwa.js`.
- `public/assets/css/scanner.css`.
- `public/assets/vendor/zxing/` : ZXing 0.21.3, licence Apache 2.0.
- `public/manifest.webmanifest`, `public/sw.js` et `public/assets/img/icons/`.

**Modules**
- Clinique :
  - `addons/clinique/src/CashierController.php` ;
  - `addons/clinique/views/caisse.php` et `bill_ticket.php` ;
  - `addons/clinique/public/caisse.js`.
- Pressing :
  - `addons/pressing/migrations/0002_suivi_retraits.sql` ;
  - `addons/pressing/views/suivi.php` et `client.php`.

**Windows**
- `demarrer-pos-marwa.bat`.
- `windows/installer-raccourci.bat`.
- `windows/installer-demarrage-auto.bat` et `windows/desinstaller-demarrage-auto.bat`.
- `windows/pos-marwa.ini.exemple` et `windows/pos-marwa.ico`.

**Documentation**
- `docs/DEMARRAGE_WINDOWS.md` et ce document.

## 3. Fichiers modifiés (principaux)

**Renommage POS Wan → POS Marwa**
- Vues, documents, traductions, outils de l'auteur et documentation.
- `docs/apache-vhost-pos-wan.conf` est renommé en `docs/apache-vhost-pos-marwa.conf`.

**Caisse**
- `PosController.php` : stock vendable, `/pos/stock`, redirection selon le mode.
- `pos.js` : actualisation du stock, bouton caméra.
- `pos/index.php` et `pos.css` : barre du mode, alertes pharmacie, file des ordonnances.

**Modes**
- `SettingsController.php` et `settings/index.php`.
- `Menu.php`, `DashboardController.php` et `layouts/admin.php`.

**Connexion**
- `AuthController.php` et `Core/Auth.php` : contrôle de l'appareil.

**Inventaire**
- `InventoryService.php`, `StockController.php` et `stock/inventory.php`.

**Étiquettes**
- `Barcode.php` : EAN-13.
- `products/labels.php` et `labels_print.php`.
- `ProductController.php`.

**Clinique**
- `manifest.json` 1.1.0, `routes.php`, `PharmacyController.php`.
- `bill.php` et `pharmacy_dispense.php`.
- `clinique.js` et `clinique.css`.

**Pressing**
- `manifest.json` 1.1.0, `routes.php`, `PressingService.php`, `PressingController.php`.
- Vues `form.php`, `show.php`, `index.php` et `ticket.php`.
- `Notifications.php` : alertes de retard et d'échéance proche.

**Sécurité et serveur**
- `public/index.php` : caméra autorisée pour le site.
- `.htaccess` : dossiers `windows/` et `outils-auteur/` interdits.
- `public/.htaccess` : type MIME du manifeste.
- `UpdateService.php` : le dossier `windows/` et le lanceur sont inclus dans les mises à jour.

**Divers**
- `routes/web.php`, `config/app.php` (version 2.0.0) et `tests/run.php`.

## 4. Migrations

| Migration | Contenu | Application |
|---|---|---|
| `database/migrations/0006_marwa.sql` | Tables `user_devices`, `inventory_count_log`, `label_templates` (3 modèles fournis) ; colonnes `note`, `scans`, `counted_by` et `counted_at` dans `inventory_items` ; `category_id` dans `inventories` ; réglages `device_approval` et `scan_repeat_ms` | Automatique à la première page ouverte après la copie des fichiers (ou `php bin/migrate.php`) |
| `addons/pressing/migrations/0002_suivi_retraits.sql` | Statut `partial`, `picked_qty` et `picked_at` ; les dépôts déjà retirés sont marqués entièrement retirés | Configuration → Modules complémentaires → Pressing → **Mettre à jour** |
| Clinique 1.1.0 | Pas de nouvelle table ; nouveau réglage « paiement partiel » et menu « Caisse clinique » | Configuration → Modules → Clinique → **Mettre à jour** |

Les migrations ne font qu'ajouter des tables et des colonnes : aucune donnée n'est supprimée.
Une sauvegarde est faite par la mise à jour depuis le navigateur. Pour une copie manuelle,
faites d'abord une sauvegarde depuis Configuration → Sauvegardes.

## 5. Mise en service

1. **Mettre à jour une installation existante** :
   - Configuration → Mise à jour du logiciel → sélectionner le dossier `pos-marwa` ;
   - ou bien copier les fichiers par-dessus en conservant `.env`, `storage/` et
     `public/uploads/`.

   Le dossier peut garder son ancien nom (`pos-wan`) : l'adresse ne change pas.
2. Configuration → Modules complémentaires : **Mettre à jour** Clinique et Pressing s'ils sont
   installés.
3. Paramètres → **Mode de fonctionnement de la caisse** : choisir l'activité.
4. Facultatif :
   - Configuration → Accès mobile (QR code) ;
   - Configuration → Appareils connectés (approbation des nouveaux appareils).
5. Windows :
   - `windows\installer-raccourci.bat` ;
   - puis `windows\installer-demarrage-auto.bat` ;
   - détails dans `docs/DEMARRAGE_WINDOWS.md`.

## 6. Vérifications réellement effectuées

- **Suite automatisée** : `php tests/run.php` → **116 réussis, 0 échec**, sur MariaDB avec
  un serveur PHP à 6 processus. Elle couvre notamment :
  - 10 ventes simultanées pour 5 en stock : 5 acceptées, stock final 0, une seule déduction
    par vente ;
  - modes et redirections des caisses ;
  - appareils : approbation, révocation et coupure de la session ;
  - `/health` ;
  - inventaire par scan, avec l'anti-doublon côté serveur, la catégorie, la validation et le
    rapport ;
  - étiquettes : validation des formats, modèles, EAN-13 ;
  - pressing : retrait partiel puis total, retards et suivi ;
  - caisse clinique ;
  - en-têtes de sécurité, dont la caméra.
- **Navigateur Chromium 141**, avec une vraie caméra simulée (flux vidéo d'un code EAN-13) :
  - **Caisse :** le code reste devant la caméra → **1 seule** unité. Il sort puis revient →
    2 unités. Bouton « +1 » → 3 unités. ZXing a été utilisé, car le lecteur intégré du
    navigateur est absent sous Linux.
  - **Inventaire :** deux présentations du code → compté 2 ; la douchette (saisie + Entrée)
    fonctionne aussi.
  - **Étiquettes :** l'aperçu est généré et le code-barres imprimé est **relu par ZXing**
    (`2100000000012`).
  - **Pages :** caisse clinique, caisse pressing, suivi des vêtements, QR code, appareils et
    paramètres s'affichent sans erreur JavaScript.
  - **Smartphone (390 × 844) :** page `/m` (serveur et caméra vérifiés) et connexion d'un
    caissier.
- Le test navigateur a révélé un **défaut réel**, corrigé : l'en-tête
  `Permissions-Policy: camera=()` bloquait la caméra.

## 7. Ce qui n'a pas pu être vérifié / limites

- **Fichiers `.bat`** : non exécutés (pas de Windows dans l'environnement de développement).
  Faire l'essai du §5 de `docs/DEMARRAGE_WINDOWS.md`.
- **Caméra en direct sur smartphone** : exige HTTPS (certificat local, voir
  `DEMARRAGE_WINDOWS.md` §7). En HTTP, POS Marwa propose la photo du code. Aucun essai n'a
  été fait sur un téléphone physique ni sur une imprimante d'étiquettes réelle.
- **Détection de l'adresse IP** : testée seulement sans réseau local (l'environnement de
  test n'en a pas). La page affiche alors un message explicite et un bouton « Régénérer ».
- **Hors ligne** : la vente nécessite le serveur. La PWA ne stocke aucune donnée hors
  ligne.
- **Certification** : le logiciel n'est pas certifié (pharmacie, santé, fiscalité). Comme
  tout logiciel, il n'est pas inviolable : voir `SECURITE.md`.
