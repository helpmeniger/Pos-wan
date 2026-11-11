<?php
/** Routes du module Boutique en ligne (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Boutique\ShopAdminController as A;
use Addons\Boutique\ShopController as S;

// Pages publiques : catalogue, panier, commande, reçu (aucune connexion)
$router->get('/boutique', [S::class, 'index'], null, true);
$router->get('/boutique/panier', [S::class, 'checkout'], null, true);
$router->get('/boutique/stock', [S::class, 'stock'], null, true);
$router->post('/boutique/commande', [S::class, 'order'], null, true);
$router->get('/boutique/commande/{id}', [S::class, 'receipt'], null, true);

// Compte client : coordonnées, historique des commandes, reçus (session séparée de celle du personnel)
$router->get('/boutique/compte', [S::class, 'account'], null, true);
$router->post('/boutique/compte/inscription', [S::class, 'accountRegister'], null, true);
$router->post('/boutique/compte/connexion', [S::class, 'accountLogin'], null, true);
$router->post('/boutique/compte/deconnexion', [S::class, 'accountLogout'], null, true);
$router->post('/boutique/compte/profil', [S::class, 'accountUpdate'], null, true);
$router->post('/boutique/compte/supprimer', [S::class, 'accountDelete'], null, true);

// Traitement des commandes dans POS Marwa
$router->get('/boutique-admin', [A::class, 'index'], 'boutique.orders');
$router->get('/boutique-admin/feed', [A::class, 'feed'], 'boutique.orders');
$router->get('/boutique-admin/clients', [A::class, 'accounts'], 'boutique.orders');
$router->get('/boutique-admin/reglages', [A::class, 'settings'], 'boutique.manage');
$router->post('/boutique-admin/reglages', [A::class, 'settingsSave'], 'boutique.manage');
$router->post('/boutique-admin/articles/{id}', [A::class, 'product'], 'boutique.manage');
$router->get('/boutique-admin/{id}', [A::class, 'show'], 'boutique.orders');
$router->post('/boutique-admin/{id}/valider', [A::class, 'confirm'], 'boutique.orders');
$router->post('/boutique-admin/{id}/encaisser', [A::class, 'pay'], 'boutique.orders');
$router->post('/boutique-admin/{id}/livrer', [A::class, 'deliver'], 'boutique.orders');
$router->post('/boutique-admin/{id}/annuler', [A::class, 'cancel'], 'boutique.orders');
