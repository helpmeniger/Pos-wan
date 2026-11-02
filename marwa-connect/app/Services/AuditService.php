<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;

/**
 * Journal d'audit des actions sensibles (qui, quoi, quand, d'où, avant/après).
 */
final class AuditService
{
    private const HIDDEN = ['password', 'password_hash', 'api_password', 'api_password_enc', 'password_enc', 'config_enc', '_token'];

    public static function log(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        string $description = '',
        ?array $old = null,
        ?array $new = null,
        ?int $userId = null
    ): void {
        try {
            Database::insert('audit_logs', [
                'user_id' => $userId ?? Auth::id(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId !== null ? (int) $entityId : null,
                'description' => mb_substr($description, 0, 500),
                'old_values' => $old !== null ? json_encode(self::clean($old), JSON_UNESCAPED_UNICODE) : null,
                'new_values' => $new !== null ? json_encode(self::clean($new), JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : null),
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? PHP_SAPI), 0, 255),
            ]);
        } catch (\Throwable $e) {
            // L'échec de l'audit ne doit jamais être silencieux.
            Logger::critical('Échec écriture audit : ' . $e->getMessage(), ['action' => $action], 'audit');
        }
    }

    private static function clean(array $values): array
    {
        foreach ($values as $k => $v) {
            if (in_array($k, self::HIDDEN, true)) {
                $values[$k] = '***';
            }
        }
        return $values;
    }

    /** Retourne uniquement les champs modifiés entre deux états. */
    public static function diff(array $before, array $after): array
    {
        $old = $new = [];
        foreach ($after as $k => $v) {
            if (array_key_exists($k, $before) && (string) $before[$k] !== (string) $v) {
                $old[$k] = $before[$k];
                $new[$k] = $v;
            }
        }
        return [$old, $new];
    }
}
