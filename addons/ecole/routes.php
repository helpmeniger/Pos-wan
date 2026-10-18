<?php
/** Routes du module Établissement (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Ecole\CaisseController as K;
use Addons\Ecole\EcoleController as E;
use Addons\Ecole\PersonnesController as P;
use Addons\Ecole\ScolariteController as S;

// Tableau de bord, paramètres, classes
$router->get('/ecole', [E::class, 'dashboard'], 'ecole.view');
$router->get('/ecole/parametres', [E::class, 'parametres'], 'ecole.manage');
$router->post('/ecole/parametres', [E::class, 'sauverParametres'], 'ecole.manage');
$router->post('/ecole/annees', [E::class, 'creerAnnee'], 'ecole.manage');
$router->post('/ecole/annees/{id}/courante', [E::class, 'anneeCourante'], 'ecole.manage');
$router->post('/ecole/periodes/{id}', [E::class, 'sauverPeriode'], 'ecole.manage');
$router->post('/ecole/points', [E::class, 'creerPoint'], 'ecole.manage');
$router->post('/ecole/points/{id}', [E::class, 'sauverPoint'], 'ecole.manage');
$router->post('/ecole/points/{id}/delete', [E::class, 'supprimerPoint'], 'ecole.manage');
$router->get('/ecole/classes', [E::class, 'classes'], 'ecole.manage');
$router->post('/ecole/classes', [E::class, 'creerClasse'], 'ecole.manage');
$router->get('/ecole/classes/{id}', [E::class, 'classe'], 'ecole.view');
$router->post('/ecole/classes/{id}', [E::class, 'sauverClasse'], 'ecole.manage');
$router->post('/ecole/classes/{id}/programme', [E::class, 'programme'], 'ecole.manage');
$router->post('/ecole/matieres', [E::class, 'creerMatiere'], 'ecole.manage');
$router->get('/ecole/photo/eleve/{id}', [E::class, 'photoEleve'], null);
$router->get('/ecole/photo/personnel/{id}', [E::class, 'photoPersonnel'], null);

// Élèves / étudiants, personnel, cartes, données faciales
$router->get('/ecole/eleves', [P::class, 'eleves'], 'ecole.view');
$router->get('/ecole/eleves/nouveau', [P::class, 'nouvelEleve'], 'ecole.manage');
$router->get('/ecole/eleves/recherche', [P::class, 'recherche'], null);
$router->post('/ecole/eleves', [P::class, 'creerEleve'], 'ecole.manage');
$router->get('/ecole/eleves/{id}', [P::class, 'eleve'], 'ecole.view');
$router->get('/ecole/eleves/{id}/modifier', [P::class, 'modifierEleve'], 'ecole.manage');
$router->post('/ecole/eleves/{id}', [P::class, 'sauverEleve'], 'ecole.manage');
$router->post('/ecole/eleves/{id}/inscrire', [P::class, 'inscrire'], 'ecole.caisse');
$router->get('/ecole/eleves/{id}/carte', [P::class, 'carteEleve'], 'ecole.view');
$router->get('/ecole/classes/{id}/cartes', [P::class, 'cartesClasse'], 'ecole.view');
$router->post('/ecole/inscriptions/{id}/frais', [P::class, 'frais'], 'ecole.caisse');
$router->post('/ecole/inscriptions/{id}/classe', [P::class, 'changerClasse'], 'ecole.manage');
$router->post('/ecole/inscriptions/{id}/abandon', [P::class, 'abandon'], 'ecole.manage');
$router->get('/ecole/personnel', [P::class, 'personnelListe'], 'ecole.view');
$router->get('/ecole/personnel/nouveau', [P::class, 'nouveauPersonnel'], 'ecole.manage');
$router->post('/ecole/personnel', [P::class, 'creerPersonnel'], 'ecole.manage');
$router->get('/ecole/personnel/{id}', [P::class, 'personnelFiche'], 'ecole.view');
$router->get('/ecole/personnel/{id}/modifier', [P::class, 'modifierPersonnel'], 'ecole.manage');
$router->post('/ecole/personnel/{id}', [P::class, 'sauverPersonnel'], 'ecole.manage');
$router->get('/ecole/personnel/{id}/carte', [P::class, 'cartePersonnel'], 'ecole.view');
$router->post('/ecole/visages', [P::class, 'enregistrerVisages'], 'ecole.biometrie');
$router->post('/ecole/visages/supprimer', [P::class, 'supprimerVisages'], 'ecole.biometrie');
$router->get('/ecole/visages/galerie', [P::class, 'galerie'], 'ecole.presence');
$router->get('/ecole/personne/eleve/{id}', [P::class, 'personneEleve'], 'ecole.presence');
$router->get('/ecole/personne/personnel/{id}', [P::class, 'personnePersonnel'], 'ecole.presence');

// Caisse, reçus, comptabilité, salaires
$router->get('/ecole/caisse', [K::class, 'caisse'], 'ecole.caisse');
$router->get('/ecole/caisse/eleve/{id}', [K::class, 'dossier'], 'ecole.caisse');
$router->post('/ecole/caisse/paiement', [K::class, 'payer'], 'ecole.caisse');
$router->post('/ecole/caisse/inscription', [K::class, 'inscription'], 'ecole.caisse');
$router->get('/ecole/recus/{id}', [K::class, 'recu'], 'ecole.caisse');
$router->post('/ecole/recus/{id}/annuler', [K::class, 'annulerRecu'], 'ecole.finance');
$router->get('/ecole/comptabilite', [K::class, 'comptabilite'], 'ecole.finance');
$router->get('/ecole/impayes', [K::class, 'impayes'], 'ecole.finance');
$router->get('/ecole/salaires', [K::class, 'salaires'], 'ecole.finance');
$router->post('/ecole/salaires', [K::class, 'payerSalaire'], 'ecole.finance');
$router->post('/ecole/salaires/{id}/annuler', [K::class, 'annulerSalaire'], 'ecole.finance');
$router->get('/ecole/salaires/{id}', [K::class, 'bulletinPaie'], 'ecole.finance');

// Notes, bulletins, présences, pointage
$router->get('/ecole/notes', [S::class, 'notes'], 'ecole.notes');
$router->post('/ecole/evaluations', [S::class, 'creerEvaluation'], 'ecole.notes');
$router->get('/ecole/evaluations/{id}', [S::class, 'evaluation'], 'ecole.notes');
$router->post('/ecole/evaluations/{id}', [S::class, 'saisirNotes'], 'ecole.notes');
$router->post('/ecole/evaluations/{id}/delete', [S::class, 'supprimerEvaluation'], 'ecole.notes');
$router->get('/ecole/bulletins', [S::class, 'bulletins'], 'ecole.bulletins');
$router->post('/ecole/bulletins/appreciations', [S::class, 'appreciations'], 'ecole.bulletins');
$router->get('/ecole/bulletins/imprimer', [S::class, 'imprimer'], 'ecole.bulletins');
$router->get('/ecole/presences', [S::class, 'presences'], 'ecole.presence');
$router->get('/ecole/presences/classe/{id}', [S::class, 'appelClasse'], 'ecole.presence');
$router->post('/ecole/presences/classe/{id}', [S::class, 'enregistrerAppelClasse'], 'ecole.presence');
$router->get('/ecole/presences/personnel', [S::class, 'appelPersonnel'], 'ecole.presence');
$router->post('/ecole/presences/personnel', [S::class, 'enregistrerAppelPersonnel'], 'ecole.presence');
$router->post('/ecole/presences/cloturer', [S::class, 'cloturer'], 'ecole.presence');
$router->get('/ecole/presences/rapport', [S::class, 'rapportPresences'], 'ecole.presence');
$router->get('/ecole/pointage', [S::class, 'pointage'], 'ecole.presence');
$router->post('/ecole/pointage/evenement', [S::class, 'evenement'], 'ecole.presence');
$router->get('/ecole/points/{id}/image', [S::class, 'imagePoint'], 'ecole.presence');
$router->get('/ecole/passages', [S::class, 'passages'], 'ecole.biometrie');
$router->post('/ecole/passages/{id}', [S::class, 'revuePassage'], 'ecole.biometrie');
