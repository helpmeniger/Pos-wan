<?php
/** Routes du module Comptabilité générale (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Comptabilite\ComptaController as C;

$router->get('/compta', [C::class, 'index'], 'compta.view');
$router->post('/compta/mise-en-place', [C::class, 'setup'], 'compta.admin');

$router->get('/compta/saisie', [C::class, 'saisie'], 'compta.saisie');
$router->post('/compta/ecritures', [C::class, 'store'], 'compta.saisie');
$router->get('/compta/ecritures', [C::class, 'ecritures'], 'compta.view');
$router->post('/compta/ecritures/valider', [C::class, 'validateMany'], 'compta.valider');
$router->get('/compta/ecritures/{id}', [C::class, 'show'], 'compta.view');
$router->post('/compta/ecritures/{id}', [C::class, 'update'], 'compta.saisie');
$router->post('/compta/ecritures/{id}/valider', [C::class, 'validate'], 'compta.valider');
$router->post('/compta/ecritures/{id}/supprimer', [C::class, 'delete'], 'compta.saisie');
$router->post('/compta/ecritures/{id}/extourner', [C::class, 'extourne'], 'compta.valider');

$router->get('/compta/lettrage', [C::class, 'lettrage'], 'compta.view');
$router->post('/compta/lettrage', [C::class, 'lettrer'], 'compta.valider');
$router->post('/compta/lettrage/delettrer', [C::class, 'delettrer'], 'compta.valider');

$router->get('/compta/etats', [C::class, 'etats'], 'compta.view');
$router->get('/compta/etats/journal', [C::class, 'etatJournal'], 'compta.view');
$router->get('/compta/etats/centralisateur', [C::class, 'etatCentralisateur'], 'compta.view');
$router->get('/compta/etats/grand-livre', [C::class, 'etatGrandLivre'], 'compta.view');
$router->get('/compta/etats/balance', [C::class, 'etatBalance'], 'compta.view');
$router->get('/compta/etats/balance-tiers', [C::class, 'etatBalanceTiers'], 'compta.view');
$router->get('/compta/etats/bilan', [C::class, 'etatBilan'], 'compta.view');
$router->get('/compta/etats/resultat', [C::class, 'etatResultat'], 'compta.view');

$router->get('/compta/generation', [C::class, 'generation'], 'compta.valider');
$router->post('/compta/generation', [C::class, 'generate'], 'compta.valider');

$router->get('/compta/comptes', [C::class, 'comptes'], 'compta.view');
$router->post('/compta/comptes', [C::class, 'compteStore'], 'compta.admin');
$router->post('/compta/comptes/plan', [C::class, 'planLoad'], 'compta.admin');
$router->post('/compta/comptes/import', [C::class, 'planImport'], 'compta.admin');
$router->post('/compta/comptes/{id}', [C::class, 'compteUpdate'], 'compta.admin');
$router->post('/compta/comptes/{id}/supprimer', [C::class, 'compteDelete'], 'compta.admin');

$router->get('/compta/tiers', [C::class, 'tiers'], 'compta.view');
$router->post('/compta/tiers', [C::class, 'tiersStore'], 'compta.saisie');
$router->post('/compta/tiers/importer', [C::class, 'tiersImport'], 'compta.saisie');
$router->post('/compta/tiers/{id}', [C::class, 'tiersUpdate'], 'compta.saisie');
$router->post('/compta/tiers/{id}/supprimer', [C::class, 'tiersDelete'], 'compta.admin');

$router->get('/compta/journaux', [C::class, 'journaux'], 'compta.admin');
$router->post('/compta/journaux', [C::class, 'journalStore'], 'compta.admin');
$router->post('/compta/journaux/{id}', [C::class, 'journalUpdate'], 'compta.admin');

$router->get('/compta/exercices', [C::class, 'exercices'], 'compta.admin');
$router->post('/compta/exercices', [C::class, 'exerciceStore'], 'compta.admin');
$router->post('/compta/exercices/{id}', [C::class, 'exerciceUpdate'], 'compta.admin');
$router->post('/compta/exercices/{id}/verrou', [C::class, 'verrou'], 'compta.admin');
$router->post('/compta/exercices/{id}/cloturer', [C::class, 'cloturer'], 'compta.admin');

$router->get('/compta/parametres', [C::class, 'parametres'], 'compta.admin');
$router->post('/compta/parametres', [C::class, 'parametresSave'], 'compta.admin');
