<?php
declare(strict_types=1);

namespace Addons\Fiscal;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;

/** Traçabilité des changements de configuration fiscale (secrets toujours masqués). */
final class FiscalAudit
{
    public static function log(string $action, string $entity, ?int $id, ?array $before = null, ?array $after = null): void
    {
        $enc = fn(?array $a) => $a === null ? null : mb_substr((string) json_encode(Secrets::redact($a), JSON_UNESCAPED_UNICODE), 0, 60000);
        DB::insert('fiscal_audit_logs', ['user_id' => Auth::id(), 'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'before_json' => $enc($before), 'after_json' => $enc($after), 'ip' => PHP_SAPI === 'cli' ? 'cli' : Request::ip()]);
    }
}
