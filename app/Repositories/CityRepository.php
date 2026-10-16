<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class CityRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        return $this->db->fetchAll('SELECT id, slug, name, region FROM cities WHERE is_active = 1 ORDER BY sort_order, name');
    }

    /** @return list<array<string, mixed>> */
    public function neighborhoods(int $cityId): array
    {
        return $this->db->fetchAll('SELECT id, name FROM neighborhoods WHERE city_id = ? AND is_active = 1 ORDER BY name', [$cityId]);
    }

    public function exists(int $cityId): bool
    {
        return (bool) $this->db->fetchValue('SELECT COUNT(*) FROM cities WHERE id = ? AND is_active = 1', [$cityId]);
    }

    public function neighborhoodBelongsTo(int $neighborhoodId, int $cityId): bool
    {
        return (bool) $this->db->fetchValue('SELECT COUNT(*) FROM neighborhoods WHERE id = ? AND city_id = ?', [$neighborhoodId, $cityId]);
    }
}
