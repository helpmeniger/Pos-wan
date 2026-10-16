<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Paramètres administrables (platform_settings), typés et mis en cache par requête. */
class SettingRepository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->fetchAll('SELECT setting_key, setting_value, value_type FROM platform_settings') as $row) {
                $this->cache[$row['setting_key']] = self::cast($row['setting_value'], $row['value_type']);
            }
        }
        return $this->cache;
    }

    public function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        $stored = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        $this->db->query(
            'UPDATE platform_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?',
            [$stored, $updatedBy, $key]
        );
        $this->cache = null;
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    private static function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'INT' => (int) $value,
            'DECIMAL' => $value,
            'BOOL' => in_array(strtolower($value), ['1', 'true', 'oui', 'yes'], true),
            'JSON' => json_decode($value, true),
            default => $value,
        };
    }
}
