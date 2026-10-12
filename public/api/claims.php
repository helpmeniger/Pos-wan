<?php
declare(strict_types=1);
// Vérification des affirmations : sources saisies par un analyste, verdict, méthode, limites.
require __DIR__ . '/_api.php';

use Verifia\Auth;
use Verifia\Database;
use Verifia\EventLog;
use Verifia\Http;

$user = Auth::require('analyst', true);
api_post_only();
$in = Http::input();

switch ((string) ($in['op'] ?? '')) {
    case 'add_evidence':
        $url = trim((string) ($in['url'] ?? ''));
        if (!preg_match('#^https?://#i', $url) || strlen($url) > 2048) {
            throw new InvalidArgumentException('URL de source invalide.');
        }
        $claimId = !empty($in['claim_id']) ? (int) $in['claim_id'] : null;
        $imageId = !empty($in['image_asset_id']) ? (int) $in['image_asset_id'] : null;
        if (!$claimId && !$imageId) {
            throw new InvalidArgumentException('Affirmation ou image requise.');
        }
        $date = (string) ($in['source_published_at'] ?? '');
        $id = Database::insert('evidence_sources', [
            'claim_id'       => $claimId,
            'image_asset_id' => $imageId,
            'url'            => $url,
            'title'          => mb_substr(trim((string) ($in['title'] ?? '')), 0, 500) ?: null,
            'publisher'      => mb_substr(trim((string) ($in['publisher'] ?? '')), 0, 255) ?: null,
            'stance'         => in_array($in['stance'] ?? '', ['corroborates', 'contradicts', 'context', 'neutral'], true) ? $in['stance'] : 'neutral',
            'source_published_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            'independence'   => in_array($in['independence'] ?? '', ['independent', 'related', 'same_origin', 'unknown'], true) ? $in['independence'] : 'unknown',
            'method'         => mb_substr(trim((string) ($in['method'] ?? '')), 0, 255) ?: null,
            'notes'          => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 5000) ?: null,
            'added_by'       => $user['id'],
        ]);
        if ($claimId) {
            Database::run('UPDATE claims SET external_search_done = 1 WHERE id = ?', [$claimId]);
        }
        EventLog::audit($user['id'], 'evidence_add', 'evidence', $id, ['claim' => $claimId, 'image' => $imageId]);
        Http::json(['ok' => true, 'id' => $id]);
    case 'verdict':
        $verdict = (string) ($in['verdict'] ?? '');
        if (!in_array($verdict, ['unverified', 'corroborated', 'contradicted', 'mixed', 'unprovable'], true)) {
            throw new InvalidArgumentException('Verdict invalide.');
        }
        Database::run(
            'UPDATE claims SET verdict = ?, verification_method = ?, limitations = ?, verified_by = ?, verified_at = ? WHERE id = ?',
            [$verdict, mb_substr((string) ($in['method'] ?? ''), 0, 5000) ?: null, mb_substr((string) ($in['limitations'] ?? ''), 0, 5000) ?: null, $user['id'], Database::now(), (int) ($in['claim_id'] ?? 0)]
        );
        EventLog::audit($user['id'], 'claim_verdict', 'claim', (int) ($in['claim_id'] ?? 0), ['verdict' => $verdict]);
        Http::json(['ok' => true]);
    case 'delete_evidence':
        Database::run('DELETE FROM evidence_sources WHERE id = ?', [(int) ($in['id'] ?? 0)]);
        EventLog::audit($user['id'], 'evidence_delete', 'evidence', (int) ($in['id'] ?? 0));
        Http::json(['ok' => true]);
    default:
        Http::json(['error' => 'Opération inconnue'], 400);
}
