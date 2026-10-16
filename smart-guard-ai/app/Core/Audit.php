<?php
declare(strict_types=1);

namespace App\Core;

/** Journal des actions sensibles (table audit_logs). */
final class Audit
{
    public static function log(string $action, ?string $entityType = null, int|string|null $entityId = null, array $details = []): void
    {
        try {
            Database::insert('audit_logs', [
                'user_id' => Auth::id(),
                'store_id' => Auth::check() ? Auth::storeId() : null,
                'action' => mb_substr($action, 0, 80),
                'entity_type' => $entityType,
                'entity_id' => $entityId === null ? null : (string) $entityId,
                'details_json' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent' => PHP_SAPI === 'cli' ? 'cli' : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Échec écriture audit : ' . $e->getMessage());
        }
    }
}
