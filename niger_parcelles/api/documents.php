<?php
/**
 * Contrôleur des documents privés.
 *   GET  ?action=download&id=[&inline=1]  -> téléchargement après contrôle des droits
 *   POST ?action=upload (multipart)       -> ajout (champ file + parcel_id/owner_id)
 *   POST ?action=status   {id, status}
 *   POST ?action=archive  {id}
 */
declare(strict_types=1);

define('NP_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();

switch (api_action('download')) {
    case 'download':
        require_permission('document.view');
        $doc = DocumentService::get(input_int('id', 0));
        if (!$doc) {
            json_error('Document introuvable.', 404);
        }
        // Documents rattachés à un propriétaire ou pièces d'identité : données personnelles
        if (($doc['owner_id'] || $doc['document_type'] === 'piece_identite') && !can('owner.view_personal')) {
            require_permission('owner.view_personal');
        }
        audit_log('document_telechargement', 'document', (int) $doc['id']);
        DocumentService::stream($doc, !empty($_GET['inline']));

    case 'upload':
        api_require_post();
        require_permission('document.upload');
        $id = DocumentService::store($_FILES['file'] ?? [], $_POST, (int) $user['id']);
        json_response(['ok' => true, 'id' => $id], 201);

    case 'status':
        api_require_post();
        require_permission('document.verify');
        DocumentService::setStatus((int) input('id', 0), (string) input('status', ''), (int) $user['id']);
        json_response(['ok' => true]);

    case 'archive':
        api_require_post();
        require_permission('document.verify');
        DocumentService::archive((int) input('id', 0), (int) $user['id']);
        json_response(['ok' => true]);
}
json_error('Action inconnue.', 400);
