<?php
/** Routes du module MARWA AI (ex-MARWA IA). @var App\Core\Router $router */
use Addons\MarwaIa\AiController as A;
use Addons\MarwaIa\IaController as I;

// Assistant (conversation en continu, mémoire, actions confirmées)
$router->get('/ia', [A::class, 'index'], 'ia.use');
$router->post('/ia/v2/stream', [A::class, 'stream'], 'ia.use');
$router->get('/ia/conversations', [A::class, 'conversations'], 'ia.use');
$router->get('/ia/conversations/{id}', [A::class, 'conversation'], 'ia.use');
$router->post('/ia/conversations/{id}/renommer', [A::class, 'rename'], 'ia.use');
$router->post('/ia/conversations/{id}/supprimer', [A::class, 'delete'], 'ia.use');
$router->post('/ia/conversations/supprimer-tout', [A::class, 'deleteAll'], 'ia.use');
$router->post('/ia/avis', [A::class, 'feedback'], 'ia.use');
$router->post('/ia/actions/{id}/confirmer', [A::class, 'confirm'], 'ia.use');
$router->post('/ia/actions/{id}/annuler', [A::class, 'cancelAction'], 'ia.use');

// Bulle d'assistance et compatibilité 1.x (même moteur, réponse en un bloc)
$router->get('/ia/etat', [I::class, 'state'], 'ia.use');
$router->post('/ia/ask', [I::class, 'ask'], 'ia.use');
$router->get('/ia/rapport', [I::class, 'rapport'], 'ia.use');
$router->get('/ia/marketing', [I::class, 'marketing'], 'ia.use');
$router->post('/ia/oubli', [I::class, 'forget'], 'ia.use');

// Administration
$router->get('/ia/reglages', [I::class, 'settings'], 'ia.manage');
$router->post('/ia/reglages', [I::class, 'settingsSave'], 'ia.manage');
$router->get('/ia/admin', [A::class, 'admin'], 'ia.manage');
$router->post('/ia/admin/banc', [A::class, 'bench'], 'ia.manage');
$router->post('/ia/admin/apprentissage/{id}', [A::class, 'teach'], 'ia.manage');
$router->post('/ia/admin/index', [A::class, 'reindex'], 'ia.manage');
$router->get('/ia/admin/intentions', [A::class, 'intents'], 'ia.manage');
$router->post('/ia/admin/intentions/exemple', [A::class, 'addExample'], 'ia.manage');
$router->post('/ia/admin/intentions/exemple/{id}/supprimer', [A::class, 'deleteExample'], 'ia.manage');
$router->get('/ia/admin/connaissances', [A::class, 'knowledge'], 'ia.manage');
$router->post('/ia/admin/connaissances', [A::class, 'knowledgeSave'], 'ia.manage');
$router->get('/ia/admin/modeles', [A::class, 'models'], 'ia.manage');
$router->post('/ia/admin/modeles', [A::class, 'modelSave'], 'ia.manage');
$router->post('/ia/admin/modeles/{id}/role', [A::class, 'modelRole'], 'ia.manage');
$router->post('/ia/admin/modeles/{id}/tester', [A::class, 'modelTest'], 'ia.manage');
$router->get('/ia/admin/dictionnaire', [A::class, 'dictionary'], 'ia.manage');

// MARWA AI TRAINING CENTER (droit ia.manage)
use Addons\MarwaIa\TrainingController as T;

$router->get('/ia/admin/scenarios', [T::class, 'scenarios'], 'ia.manage');
$router->post('/ia/admin/scenarios', [T::class, 'scenarioAdd'], 'ia.manage');
$router->post('/ia/admin/scenarios/{id}/supprimer', [T::class, 'scenarioDelete'], 'ia.manage');
$router->get('/ia/admin/scenarios/export', [T::class, 'scenariosExport'], 'ia.manage');
$router->get('/ia/admin/synonymes', [T::class, 'synonyms'], 'ia.manage');
$router->post('/ia/admin/synonymes', [T::class, 'synonymAdd'], 'ia.manage');
$router->post('/ia/admin/synonymes/{id}/supprimer', [T::class, 'synonymDelete'], 'ia.manage');
$router->post('/ia/admin/intentions/etat', [T::class, 'intentToggle'], 'ia.manage');
$router->post('/ia/admin/intentions/ajouter', [T::class, 'intentAdd'], 'ia.manage');
$router->get('/ia/admin/incomprises', [T::class, 'unresolved'], 'ia.manage');
$router->post('/ia/admin/incomprises/{id}/ecarter', [T::class, 'dismiss'], 'ia.manage');
$router->get('/ia/admin/corrections', [T::class, 'corrections'], 'ia.manage');
$router->post('/ia/admin/corrections/{id}', [T::class, 'correctionHandle'], 'ia.manage');
$router->get('/ia/admin/tests', [T::class, 'tests'], 'ia.manage');
$router->post('/ia/admin/tests', [T::class, 'testsRun'], 'ia.manage');
$router->post('/ia/admin/tests/formulation', [T::class, 'probe'], 'ia.manage');
$router->get('/ia/admin/performance', [T::class, 'performance'], 'ia.manage');
$router->get('/ia/admin/historique', [T::class, 'history'], 'ia.manage');
