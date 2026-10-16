<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class UserRepository
{
    private const PUBLIC_COLUMNS = 'u.id, u.uuid, u.primary_type, u.first_name, u.last_name, u.phone, u.phone_verified_at,
        u.email, u.email_verified_at, u.must_change_password, u.password_changed_at, u.birth_date, u.city_id,
        u.neighborhood_id, u.locale, u.status, u.status_reason, u.suspended_until, u.last_login_at, u.is_demo, u.created_at';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null (sans le hachage du mot de passe) */
    public function find(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::PUBLIC_COLUMNS . ', p.display_name, p.verification_level, p.avatar_file_id, c.name AS city_name
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN cities c ON c.id = u.city_id
             WHERE u.id = ?',
            [$id]
        );
    }

    /** Ligne complète, y compris password_hash : réservé au service d'authentification. @return array<string, mixed>|null */
    public function findForAuth(string $login): ?array
    {
        $column = str_contains($login, '@') ? 'email' : 'phone';
        return $this->db->fetch("SELECT * FROM users WHERE {$column} = ?", [$login]);
    }

    /** @return array<string, mixed>|null */
    public function findWithHash(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function phoneExists(string $phone): bool
    {
        return (bool) $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE phone = ?', [$phone]);
    }

    public function emailExists(string $email): bool
    {
        return (bool) $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE email = ?', [$email]);
    }

    public function updatePassword(int $userId, string $hash, bool $mustChange = false): void
    {
        $this->db->query(
            'UPDATE users SET password_hash = ?, must_change_password = ?, password_changed_at = NOW(3), failed_login_count = 0, locked_until = NULL WHERE id = ?',
            [$hash, $mustChange ? 1 : 0, $userId]
        );
    }

    /** Verrouille le compte au N-ième échec consécutif (MySQL évalue SET de gauche à droite : le compteur est déjà incrémenté). */
    public function registerFailedLogin(int $userId, int $maxAttempts, int $lockMinutes): void
    {
        $this->db->query(
            'UPDATE users SET failed_login_count = failed_login_count + 1,
                locked_until = IF(failed_login_count >= ?, NOW() + INTERVAL ? MINUTE, locked_until)
             WHERE id = ?',
            [$maxAttempts, $lockMinutes, $userId]
        );
    }

    public function registerSuccessfulLogin(int $userId, string $ip): void
    {
        $this->db->query(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [$ip, $userId]
        );
    }

    public function markPhoneVerified(int $userId): void
    {
        $this->db->query('UPDATE users SET phone_verified_at = NOW() WHERE id = ? AND phone_verified_at IS NULL', [$userId]);
        // Niveau 1 = téléphone vérifié (ne fait jamais baisser un niveau supérieur)
        $this->db->query('UPDATE user_profiles SET verification_level = GREATEST(verification_level, 1) WHERE user_id = ?', [$userId]);
    }
}
