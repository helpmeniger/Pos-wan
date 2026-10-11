# Modules complémentaires (addons)

POS Wan accepte des modules ajoutés **sans toucher au code du logiciel**, comme dans Odoo :
on dépose le dossier du module dans `addons/`, puis on l'installe en un clic.

## 1. Installer un module (utilisateur)

1. Copier le dossier du module dans le dossier `addons/` de POS Wan
   (ex. `C:\wamp64\www\pos-wan\addons\livraisons\`, qui contient `manifest.json`).
2. Se connecter en **administrateur général** → **Gestion → Modules complémentaires**
   (le module apparaît avec l'état « Non installé » ; sinon cliquer sur
   « Rechercher de nouveaux modules »).
3. Cliquer sur **Installer** : POS Wan fait une **sauvegarde de sécurité**, crée les tables
   du module, ajoute ses droits et son menu. Le module est actif immédiatement.
4. Donner les droits aux rôles qui en ont besoin (**Rôles & permissions**) si le module ne
   l'a pas déjà fait.

| Action | Effet |
|---|---|
| Installer | Sauvegarde, migrations SQL du module, paramètres par défaut, permissions, menu |
| Mettre à jour | Apparaît quand la version du dossier est plus récente : sauvegarde + nouvelles migrations |
| Désactiver | Pages et menu masqués (404), **données conservées** |
| Activer | Le module revient tel quel |

En cas d'échec, la cause exacte s'affiche en rouge sur la page des modules (une sauvegarde a
été faite avant). Un dossier décompressé dans un sous-dossier ou mal nommé est rangé
automatiquement (`addons/module-x-1.0.0/x/` → `addons/x/`).

Pour retirer un module : le désactiver, puis supprimer son dossier. Ses tables restent dans
la base (aucune donnée n'est effacée automatiquement).

En ligne de commande (même effet) :

```
php bin/addons.php list
php bin/addons.php install livraisons
php bin/addons.php disable livraisons
php bin/addons.php enable livraisons
```

> ⚠️ Un module est du code PHP qui s'exécute avec les mêmes droits que POS Wan (accès à toute
> la base). N'installez que des modules dont vous connaissez l'auteur. L'installation est
> réservée à la permission `addons.manage` (administrateur général uniquement par défaut).
> Les modules ne peuvent pas être envoyés par le navigateur : il faut un accès au dossier
> du serveur.

## 2. Créer un module (développeur)

Le module d'exemple `addons/livraisons/` est un modèle simple ; `addons/pressing/` est un
module complet (tarifs, paiements en caisse, impressions, rapport, export PDF) ;
`addons/clinique/` montre la création de rôles, un écran plein écran actualisé en JSON et
l'utilisation des services du noyau (vente pharmacie via `SaleService`). Copiez-les et
adaptez-les.

```
addons/
└── livraisons/                  ← code du module = nom du dossier (a-z, 0-9, _)
    ├── manifest.json            ← obligatoire
    ├── routes.php               ← pages du module
    ├── src/                     ← classes PHP : espace de noms Addons\Livraisons\
    │   ├── LivraisonController.php
    │   └── LivraisonService.php
    ├── views/                   ← gabarits : view('livraisons::index')
    │   └── index.php
    ├── migrations/              ← tables : 0001_xxx.sql, 0002_xxx.sql…
    │   └── 0001_livraisons.sql
    ├── lang/                    ← traductions : en.php, ar.php… (clé = texte français)
    │   └── en.php
    ├── public/                  ← CSS / JS / images → asset('addons/livraisons/…')
    │   └── livraisons.css
    └── README.md
```

### manifest.json

```json
{
    "code": "livraisons",
    "name": "Livraisons",
    "version": "1.0.0",
    "requires": "1.4.0",
    "author": "Votre nom",
    "description": "Ce que fait le module.",
    "icon": "bi-truck",
    "color": "#16a085",
    "permissions": {
        "livraisons.view": "Voir les livraisons",
        "livraisons.manage": "Créer et mettre à jour les livraisons"
    },
    "roles": {
        "store_manager": ["livraisons.view", "livraisons.manage"],
        "cashier": ["livraisons.view"]
    },
    "menu": [
        { "group": "Gestion des ventes", "label": "Livraisons", "icon": "bi-truck",
          "url": "/livraisons", "permission": "livraisons.view", "color": "#16a085" }
    ],
    "settings": { "livraisons_frais_defaut": "1000" }
}
```

| Clé | Obligatoire | Rôle |
|---|---|---|
| `code` | oui | Identique au nom du dossier |
| `name`, `version` | oui | Version au format `1.0.0` ; l'augmenter pour proposer « Mettre à jour » |
| `requires` | non | Version minimale de POS Wan (sinon le module est refusé) |
| `permissions` | non | `module.action` → libellé ; apparaissent dans Rôles & permissions |
| `new_roles` | non | Rôles créés à l'installation s'ils n'existent pas : `{"code": {"name", "description", "permissions": [...]}}` (POS Wan ≥ 1.5.0 ; ex. module Clinique) |
| `roles` | non | Droits accordés à l'installation aux rôles système (`owner`, `store_manager`, `cashier`, `stock_manager`, `buyer`, `accountant`, `pharmacist`, `waiter`, `cook`, `auditor`). L'administrateur général reçoit toujours tout |
| `menu` | non | Entrées ajoutées au menu (groupe existant ou nouveau groupe) |
| `settings` | non | Paramètres créés à l'installation (jamais écrasés), lus avec `setting('clé')` |
| `icon`, `color`, `author`, `description` | non | Affichage dans la page Modules |

### routes.php

```php
<?php
use Addons\Livraisons\LivraisonController as L;

$router->get('/livraisons', [L::class, 'index'], 'livraisons.view');
$router->post('/livraisons', [L::class, 'store'], 'livraisons.manage');
$router->post('/livraisons/{id}/status', [L::class, 'status'], 'livraisons.manage');
```

- Le 3ᵉ argument est la permission exigée (contrôlée par le serveur à chaque requête).
- Toutes les routes POST sont protégées contre le CSRF : mettre `<?= csrf_field() ?>` dans les formulaires.
- Paramètre d'URL : toujours `{id}` (nombre) et un paramètre `int $id` dans la méthode.
- Préfixer les adresses par le code du module (`/livraisons/...`) pour éviter les conflits.

### Classes (src/)

Espace de noms `Addons\<Code en CamelCase>\` : `mes_notes` → `Addons\MesNotes\`.
Les contrôleurs héritent de `App\Controllers\Controller` (méthodes `attempt()`, `fail()`,
`findOr404()`, `user()`…) et utilisent les outils du noyau : `DB` (requêtes préparées,
`DB::transaction()`), `Audit::log()`, `BusinessException`, `can()`, `setting()`, `money()`,
`view()`, `flash()`, `redirect()`, `t()`.

### Vues

`view('livraisons::index', [...])` affiche `addons/livraisons/views/index.php` dans la mise
en page de POS Wan (menu, en-tête). Toujours échapper les données avec `e()`.

### Tables (migrations/)

- Fichiers `0001_nom.sql`, `0002_nom.sql`… appliqués une seule fois, dans l'ordre
  (enregistrés comme `addon:<code>:<fichier>` dans `schema_migrations`).
- Une instruction par bloc terminée par `;` en fin de ligne.
- Nommer les tables avec le code du module (`livraisons`, `livraisons_zones`…).
- Pour une nouvelle version : ajouter un nouveau fichier (ne jamais modifier un fichier déjà livré).

### Traductions (lang/)

`lang/en.php` retourne `['Texte français' => 'English text']` : fusionné avec les
dictionnaires de POS Wan quand le module est actif.

### Fichiers publics (public/)

Copiés à l'installation dans `public/assets/addons/<code>/` (CSS, JS, images, polices ;
jamais de PHP). Les inclure avec `asset('addons/<code>/fichier.css')`. Le JavaScript doit
être dans un fichier (pas de script dans la page : politique de sécurité CSP).

## 3. Règles de sécurité pour les modules

- Requêtes SQL uniquement préparées (`DB::all('... WHERE id = ?', [$id])`).
- Chaque route déclare sa permission ; vérifier aussi `can()` dans les vues pour masquer les boutons.
- Contrôler toutes les saisies côté serveur (comme `LivraisonService::create()`).
- Toute modification importante : `Audit::log()`.
- Ne jamais supprimer de données sans confirmation ; préférer un statut (annulé, archivé).
