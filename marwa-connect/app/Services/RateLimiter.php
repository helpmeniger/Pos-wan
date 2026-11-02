<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Limiteur de débit à fenêtre fixe, persistant en base (fonctionne avec plusieurs processus Apache).
 */
final class RateLimiter
{
    /** Enregistre une tentative ; retourne false si la limite est dépassée. */
    public static function hit(string $key, int $max, int $decaySeconds): bool
    {
        $key = substr(hash('sha256', $key), 0, 64);
        $expires = date('Y-m-d H:i:s', time() + $decaySeconds);
        $now = date('Y-m-d H:i:s');
        Database::query(
            'INSERT INTO rate_limits (`key`, hits, expires_at) VALUES (:k, 1, :e)
             ON DUPLICATE KEY UPDATE
                hits = IF(expires_at < :now1, 1, hits + 1),
                expires_at = IF(expires_at < :now2, :e2, expires_at)',
            ['k' => $key, 'e' => $expires, 'now1' => $now, 'now2' => $now, 'e2' => $expires]
        );
        $hits = (int) Database::value('SELECT hits FROM rate_limits WHERE `key` = :k', ['k' => $key]);
        if (random_int(1, 100) === 1) {
            Database::query('DELETE FROM rate_limits WHERE expires_at < :now', ['now' => $now]);
        }
        return $hits <= $max;
    }
}
