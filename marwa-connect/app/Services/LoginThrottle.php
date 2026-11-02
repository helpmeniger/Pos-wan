<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Limitation des tentatives de connexion (par identifiant et par IP) et journal des connexions.
 */
final class LoginThrottle
{
    public static function tooManyAttempts(string $identifier, string $ip): bool
    {
        return self::secondsUntilUnlock($identifier, $ip) > 0;
    }

    public static function secondsUntilUnlock(string $identifier, string $ip): int
    {
        $decay = (int) config('security.login_decay_minutes', 15);
        $since = date('Y-m-d H:i:s', time() - $decay * 60);
        $identifier = mb_strtolower($identifier);

        // Ne compte que les échecs survenus après la dernière connexion réussie.
        $byId = Database::fetch(
            'SELECT COUNT(*) AS n, MAX(created_at) AS last FROM login_attempts
              WHERE identifier = :i AND success = 0 AND created_at >= :s
                AND created_at > COALESCE((SELECT MAX(created_at) FROM login_attempts WHERE identifier = :i2 AND success = 1), \'1970-01-01\')',
            ['i' => $identifier, 's' => $since, 'i2' => $identifier]
        );
        $byIp = Database::fetch(
            'SELECT COUNT(*) AS n, MAX(created_at) AS last FROM login_attempts WHERE ip_address = :ip AND success = 0 AND created_at >= :s',
            ['ip' => $ip, 's' => $since]
        );
        $wait = 0;
        foreach ([[$byId, (int) config('security.login_max_attempts', 5)], [$byIp, (int) config('security.login_max_attempts_ip', 20)]] as [$row, $max]) {
            if ((int) $row['n'] >= $max && $row['last']) {
                $wait = max($wait, strtotime($row['last']) + $decay * 60 - time());
            }
        }
        return max(0, $wait);
    }

    public static function record(string $identifier, string $ip, string $userAgent, bool $success, ?int $userId = null, ?string $reason = null): void
    {
        Database::insert('login_attempts', [
            'identifier' => mb_substr(mb_strtolower($identifier), 0, 190),
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'success' => $success ? 1 : 0,
            'reason' => $reason,
        ]);
    }
}
