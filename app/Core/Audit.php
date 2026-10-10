<?php
declare(strict_types=1);

namespace App\Core;

/** Journal d'audit : actions sensibles, connexions, modifications. Jamais modifié par l'application. */
final class Audit
{
    public static function log(string $action, ?string $entity = null, ?int $entityId = null, ?array $details = null, ?int $userId = -1): void
    {
        try {
            if ($userId === -1) {
                $userId = Session::get('uid') ? (int) Session::get('uid') : null;
            }
            DB::insert('audit_logs', [
                'user_id' => $userId,
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                'ip' => PHP_SAPI === 'cli' ? 'cli' : Request::ip(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Audit impossible : ' . $e->getMessage());
        }
    }
}
