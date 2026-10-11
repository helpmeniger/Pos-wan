<?php
/**
 * API Propriétaires (JSON).
 *   GET  ?action=search&q=       -> autocomplétion (profils autorisés)
 *   GET  ?action=duplicates&...  -> doublons potentiels pour un formulaire
 */
declare(strict_types=1);

define('NP_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

require_login();
require_permission('owner.view_personal');

switch (api_action('search')) {
    case 'search':
        $q = input_str('q', 100);
        if ($q === null || mb_strlen($q) < 2) {
            json_response(['ok' => true, 'owners' => []]);
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $rows = db_all("SELECT id, owner_type, first_name, last_name, organization_name, phone, file_reference FROM owners
                        WHERE is_archived = 0 AND (CONCAT_WS(' ', last_name, first_name) LIKE ? OR CONCAT_WS(' ', first_name, last_name) LIKE ?
                          OR organization_name LIKE ? OR file_reference LIKE ? OR phone LIKE ?)
                        ORDER BY last_name, organization_name LIMIT 15", [$like, $like, $like, $like, $like]);
        json_response(['ok' => true, 'owners' => array_map(static fn ($o) => ['id' => (int) $o['id'], 'name' => owner_display_name($o),
            'sub' => trim(label('owner_type', $o['owner_type']) . ($o['file_reference'] ? ' · ' . $o['file_reference'] : '') . ($o['phone'] ? ' · ' . $o['phone'] : ''))], $rows)]);

    case 'duplicates':
        $d = array_intersect_key($_GET, array_flip(['phone', 'last_name', 'first_name', 'organization_name', 'id_document_number']));
        $rows = OwnerService::findDuplicates(array_map('strval', $d), input_int('exclude'));
        json_response(['ok' => true, 'duplicates' => array_map(static fn ($o) => ['id' => (int) $o['id'], 'name' => owner_display_name($o),
            'phone' => $o['phone'], 'archived' => (int) $o['is_archived'] === 1], $rows)]);
}
json_error('Action inconnue.', 400);
