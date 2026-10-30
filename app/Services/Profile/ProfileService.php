<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Core\Database;
use App\Core\ValidationException;
use App\Support\Money;

/** Profil public du prestataire (métiers, présentation, tarifs) et informations personnelles. */
class ProfileService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed> */
    public function get(int $userId): array
    {
        $p = $this->db->fetch(
            'SELECT u.first_name, u.last_name, u.email, u.birth_date, u.city_id, u.neighborhood_id, u.phone, u.phone_verified_at,
                    pr.display_name, pr.headline, pr.bio, pr.daily_rate, pr.hourly_rate, pr.is_available, pr.years_experience, pr.verification_level
             FROM users u JOIN user_profiles pr ON pr.user_id = u.id WHERE u.id = ?',
            [$userId]
        ) ?? [];
        $p['trades'] = array_map('intval', array_column($this->db->fetchAll('SELECT subcategory_id FROM worker_trades WHERE user_id = ?', [$userId]), 'subcategory_id'));
        return $p;
    }

    /** @param array<string, mixed> $d @param list<int> $trades */
    public function update(int $userId, array $d, array $trades): void
    {
        if (!empty($d['birth_date'])) {
            $age = (new \DateTimeImmutable((string) $d['birth_date']))->diff(new \DateTimeImmutable('today'))->y;
            if ($age < 16 || $age > 100) {
                throw new ValidationException(['birth_date' => ['Vérifiez la date de naissance (16 ans minimum).']], $d);
            }
        }
        if (!empty($d['email']) && $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$d['email'], $userId])) {
            throw new ValidationException(['email' => ['Cette adresse e-mail est déjà utilisée.']], $d);
        }
        $trades = array_slice(array_values(array_unique(array_map('intval', $trades))), 0, 5);
        if ($trades && (int) $this->db->fetchValue('SELECT COUNT(*) FROM job_subcategories WHERE is_active = 1 AND id IN (' . implode(',', $trades) . ')') !== count($trades)) {
            throw new ValidationException(['trades' => ['Métier inconnu.']], $d);
        }
        $this->db->transaction(function (Database $db) use ($userId, $d, $trades) {
            $db->query(
                'UPDATE users SET first_name = ?, last_name = ?, email = ?, city_id = ?, neighborhood_id = ?,
                        birth_date = COALESCE(birth_date, ?) WHERE id = ?',
                [$d['first_name'], $d['last_name'], $d['email'] ?: null, (int) $d['city_id'], $d['neighborhood_id'] ?: null, $d['birth_date'] ?: null, $userId]
            );
            $db->query(
                'UPDATE user_profiles SET display_name = ?, headline = ?, bio = ?, daily_rate = ?, years_experience = ?, is_available = ? WHERE user_id = ?',
                [
                    trim($d['first_name'] . ' ' . mb_substr((string) $d['last_name'], 0, 1) . '.'),
                    $d['headline'] ?: null, $d['bio'] ?: null,
                    $d['daily_rate'] !== null && $d['daily_rate'] !== '' ? Money::parse((string) $d['daily_rate'])->toDecimal() : null,
                    $d['years_experience'] !== null && $d['years_experience'] !== '' ? (int) $d['years_experience'] : null,
                    !empty($d['is_available']) ? 1 : 0, $userId,
                ]
            );
            $db->query('DELETE FROM worker_trades WHERE user_id = ?', [$userId]);
            foreach ($trades as $i => $sub) {
                $db->insert('worker_trades', ['user_id' => $userId, 'subcategory_id' => $sub, 'is_primary' => $i === 0 ? 1 : 0]);
            }
            if ($trades) {
                // NULL n'est pas couvert par la clé unique : on vérifie l'existence explicitement
                $db->query(
                    'INSERT INTO worker_service_areas (user_id, city_id) SELECT ?, ? FROM DUAL
                     WHERE NOT EXISTS (SELECT 1 FROM worker_service_areas WHERE user_id = ? AND city_id = ? AND neighborhood_id IS NULL)',
                    [$userId, (int) $d['city_id'], $userId, (int) $d['city_id']]
                );
            }
        });
    }
}
