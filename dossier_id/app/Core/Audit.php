<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Journal d'audit en ajout seul, chaîné par empreintes SHA-256 :
 * toute modification ou suppression d'une ligne casse la chaîne et
 * est détectée par verifyChain().
 *
 * Les détails ne doivent contenir que des métadonnées (champs modifiés,
 * identifiants, motifs) : jamais de mot de passe, de contenu de document
 * ni de valeur sensible.
 */
final class Audit
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Fragments de clés interdits dans les détails (comparaison insensible à la casse). */
    private const FORBIDDEN_KEYS = ['password', 'token', 'csrf', 'secret', 'cipher', 'encryption_key'];

    public static function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = [],
        ?int $userId = null,
        ?string $username = null
    ): void {
        if ($userId === null && $username === null) {
            $user = Auth::user();
            $userId = $user['id'] ?? null;
            $username = $user['username'] ?? null;
        }
        $details = self::sanitize($details);
        $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $ip = PHP_SAPI === 'cli' ? 'cli' : substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $createdAt = date('Y-m-d H:i:s');

        $pdo = Database::pdo();
        $locked = (int) Database::value("SELECT GET_LOCK('dossier_id_audit', 10)");
        if ($locked !== 1) {
            throw new \RuntimeException("Impossible d'écrire dans le journal d'audit (verrou indisponible).");
        }
        try {
            $prev = (string) (Database::value('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1') ?? self::GENESIS);
            $row = [
                'created_at'  => $createdAt,
                'user_id'     => $userId,
                'username'    => $username,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'ip_address'  => $ip,
                'details'     => $json,
                'prev_hash'   => $prev,
            ];
            $row['hash'] = self::computeHash($row);
            Database::insert('audit_log', $row);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('dossier_id_audit')");
        }
    }

    public static function computeHash(array $row): string
    {
        return hash('sha256', implode('|', [
            $row['prev_hash'],
            $row['created_at'],
            (string) ($row['user_id'] ?? ''),
            (string) ($row['username'] ?? ''),
            $row['action'],
            (string) ($row['entity_type'] ?? ''),
            (string) ($row['entity_id'] ?? ''),
            (string) ($row['ip_address'] ?? ''),
            (string) ($row['details'] ?? ''),
        ]));
    }

    /** @return array{ok: bool, count: int, broken_at: ?int} */
    public static function verifyChain(): array
    {
        $prev = self::GENESIS;
        $count = 0;
        $lastId = 0;
        do {
            $rows = Database::all(
                'SELECT * FROM audit_log WHERE id > :id ORDER BY id LIMIT 1000',
                ['id' => $lastId]
            );
            foreach ($rows as $row) {
                $row['created_at'] = substr((string) $row['created_at'], 0, 19);
                if ($row['prev_hash'] !== $prev || self::computeHash($row) !== $row['hash']) {
                    return ['ok' => false, 'count' => $count, 'broken_at' => (int) $row['id']];
                }
                $prev = $row['hash'];
                $lastId = (int) $row['id'];
                $count++;
            }
        } while (count($rows) === 1000);
        return ['ok' => true, 'count' => $count, 'broken_at' => null];
    }

    private static function sanitize(array $details): array
    {
        $out = [];
        foreach ($details as $k => $v) {
            $lk = strtolower((string) $k);
            foreach (self::FORBIDDEN_KEYS as $bad) {
                if (str_contains($lk, $bad)) {
                    continue 2;
                }
            }
            $out[$k] = is_array($v) ? self::sanitize($v) : (is_string($v) ? mb_substr($v, 0, 300) : $v);
        }
        return $out;
    }
}
