<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Database;

/** Limitation de débit persistée en base (table rate_limits), fenêtre fixe. */
class RateLimiter
{
    public function __construct(private readonly Database $db, private readonly Config $config)
    {
    }

    /** Incrémente le compteur et indique si la limite est respectée. */
    public function attempt(string $key, string $limitName): bool
    {
        [$max, $window] = $this->limits($limitName);
        $this->db->query(
            'INSERT INTO rate_limits (bucket_key, hits, window_start) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start < NOW() - INTERVAL ? SECOND, 1, hits + 1),
               window_start = IF(window_start < NOW() - INTERVAL ? SECOND, NOW(), window_start)',
            [$key, $window, $window]
        );
        $hits = (int) $this->db->fetchValue('SELECT hits FROM rate_limits WHERE bucket_key = ?', [$key]);
        return $hits <= $max;
    }

    public function tooManyAttempts(string $key, string $limitName): bool
    {
        [$max, $window] = $this->limits($limitName);
        $hits = $this->db->fetchValue(
            'SELECT hits FROM rate_limits WHERE bucket_key = ? AND window_start >= NOW() - INTERVAL ? SECOND',
            [$key, $window]
        );
        return $hits !== null && (int) $hits >= $max;
    }

    public function retryAfter(string $key): int
    {
        $row = $this->db->fetch('SELECT window_start FROM rate_limits WHERE bucket_key = ?', [$key]);
        if ($row === null) {
            return 0;
        }
        [, $window] = $this->limits(explode(':', $key)[0]);
        return max(1, strtotime((string) $row['window_start']) + $window - time());
    }

    public function clear(string $key): void
    {
        $this->db->query('DELETE FROM rate_limits WHERE bucket_key = ?', [$key]);
    }

    /** @return array{0: int, 1: int} */
    private function limits(string $name): array
    {
        $l = $this->config->get('security.rate_limits.' . $name, [60, 60]);
        return [(int) $l[0], (int) $l[1]];
    }
}
