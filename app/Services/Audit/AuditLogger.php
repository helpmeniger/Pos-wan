<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Core\Database;
use App\Core\Request;

/**
 * Journal d'audit des actions sensibles (ajout seul, protégé par trigger).
 * Chaque ligne est chaînée à la précédente par une empreinte SHA-256 :
 * toute altération ou suppression ultérieure devient détectable (verifyChain()).
 */
class AuditLogger
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        ?int $actorId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?Request $request = null,
    ): void {
        $this->db->transaction(function (Database $db) use ($actorId, $action, $entityType, $entityId, $before, $after, $reason, $request) {
            // Verrouille la dernière ligne pour sérialiser le chaînage
            $prev = $db->fetchValue('SELECT row_hash FROM admin_audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
            $row = [
                'actor_user_id' => $actorId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                'after_json' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                'reason' => $reason,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'prev_hash' => $prev !== null ? (string) $prev : null,
                'created_at' => $createdAt,
            ];
            $row['row_hash'] = self::hash($row);
            $db->insert('admin_audit_logs', $row);
        });
    }

    /** @return array{ok: bool, checked: int, broken_at: ?int} */
    public function verifyChain(): array
    {
        $prev = null;
        $count = 0;
        foreach ($this->db->query('SELECT * FROM admin_audit_logs ORDER BY id') as $row) {
            $count++;
            $expected = self::hash([
                'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
                'action' => $row['action'],
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
                'before_json' => $row['before_json'],
                'after_json' => $row['after_json'],
                'reason' => $row['reason'],
                'ip_address' => $row['ip_address'],
                'user_agent' => $row['user_agent'],
                'prev_hash' => $row['prev_hash'],
                'created_at' => $row['created_at'],
            ]);
            if ($row['prev_hash'] !== $prev || $row['row_hash'] !== $expected) {
                return ['ok' => false, 'checked' => $count, 'broken_at' => (int) $row['id']];
            }
            $prev = $row['row_hash'];
        }
        return ['ok' => true, 'checked' => $count, 'broken_at' => null];
    }

    /** @param array<string, mixed> $row */
    private static function hash(array $row): string
    {
        unset($row['row_hash']);
        $row['actor_user_id'] = $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null;
        $row['entity_id'] = $row['entity_id'] !== null ? (int) $row['entity_id'] : null;
        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
