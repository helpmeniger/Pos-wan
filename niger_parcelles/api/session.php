<?php
/**
 * API Session : GET ?action=token -> jeton CSRF courant (utilisé par la
 * synchronisation hors connexion, dont la page d'origine peut être ancienne).
 */
declare(strict_types=1);

define('NP_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
json_response(['ok' => true, 'csrf' => csrf_token(), 'user' => (int) $user['id']]);
