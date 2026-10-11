<?php
/**
 * API Importation (JSON).
 *   POST ?action=analyze (multipart : file, crs)  -> analyse et aperçu (jeton)
 *   GET  ?action=preview&token=                   -> résultat d'analyse
 *   POST ?action=commit {token, area_id, survey_level, category, include_warnings}
 */
declare(strict_types=1);

define('NP_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
require_permission('import.run');
$uid = (int) $user['id'];

switch (api_action()) {
    case 'analyze':
        api_require_post();
        $crs = (string) ($_POST['crs'] ?? 'auto');
        if ($crs !== 'auto' && !isset(Crs::SUPPORTED[$crs])) {
            json_error('Système de coordonnées invalide.', 422);
        }
        [$path, $format] = ImportService::acceptUpload($_FILES['file'] ?? []);
        try {
            $state = ImportService::analyze($path, $format, (string) ($_FILES['file']['name'] ?? 'fichier'), $crs, $uid);
        } finally {
            @unlink($path);
        }
        json_response(['ok' => true, 'token' => $state['token'], 'crs' => $state['crs'], 'summary' => $state['summary'], 'warnings' => $state['warnings']]);

    case 'preview':
        $state = ImportService::loadState((string) ($_GET['token'] ?? ''), $uid);
        json_response(['ok' => true] + $state);

    case 'commit':
        api_require_post();
        $body = json_input();
        json_response(['ok' => true] + ImportService::commit((string) ($body['token'] ?? ''), $body, $uid));
}
json_error('Action inconnue.', 400);
