<?php
declare(strict_types=1);

namespace App\Core;

/** Limiteur à fenêtre fixe persisté en base (fonctionne sans Redis sur WampServer). */
final class RateLimiter
{
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $now = time();
        $window = $now - ($now % $windowSeconds);
        $k = substr(hash('sha256', $key), 0, 40) . ':' . $windowSeconds;
        Database::execute(
            'INSERT INTO rate_limits (k, window_start, hits) VALUES (:k, :w, 1)
             ON DUPLICATE KEY UPDATE hits = IF(window_start = VALUES(window_start), hits + 1, 1), window_start = VALUES(window_start)',
            ['k' => $k, 'w' => $window]
        );
        $hits = (int) Database::value('SELECT hits FROM rate_limits WHERE k = :k', ['k' => $k]);
        return $hits <= $max;
    }

    public static function enforce(string $key, int $max, int $windowSeconds, ?string $message = null): void
    {
        if (!self::hit($key, $max, $windowSeconds)) {
            throw HttpException::tooMany($message ?? 'Trop de tentatives. Veuillez patienter avant de réessayer.');
        }
    }

    public static function clear(string $key, int $windowSeconds): void
    {
        Database::execute('DELETE FROM rate_limits WHERE k = :k', ['k' => substr(hash('sha256', $key), 0, 40) . ':' . $windowSeconds]);
    }
}
