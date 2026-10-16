<?php
declare(strict_types=1);

namespace App\Core;

/** Journal d'audit immuable des actions sensibles. */
final class Audit
{
    private static ?Request $request = null;

    public static function setRequest(?Request $r): void
    {
        self::$request = $r;
    }

    public static function log(string $action, ?string $entityType = null, ?int $entityId = null, ?array $old = null, ?array $new = null, ?int $userId = null): void
    {
        $strip = static function (?array $a): ?string {
            if ($a === null) {
                return null;
            }
            unset($a['password_hash'], $a['password'], $a['token_hash'], $a['code_hash']);
            return json_encode($a, JSON_UNESCAPED_UNICODE);
        };
        Database::insert('audit_logs', [
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $strip($old),
            'new_values' => $strip($new),
            'ip' => self::$request?->ip(),
            'user_agent' => self::$request?->userAgent(),
        ]);
    }
}
