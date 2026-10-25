<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Services\Audit\AuditLogger;
use App\Support\Phone;
use App\Support\Uuid;

/** Création des comptes du personnel (console admin:create et assistant d'installation). */
final class StaffAccountService
{
    public function __construct(
        private readonly Database $db,
        private readonly PasswordHasher $hasher,
        private readonly Config $config,
    ) {
    }

    /**
     * Sans mot de passe fourni : un mot de passe temporaire aléatoire est généré et son
     * changement est imposé à la première connexion.
     *
     * @return array{id: int, temporary_password: ?string}
     */
    public function create(string $phone, string $first, string $last, ?string $email, string $role, ?string $password = null): array
    {
        $phone = Phone::normalize($phone) ?? throw new \InvalidArgumentException('Numéro de téléphone invalide.');
        $first = trim($first);
        $last = trim($last);
        if (mb_strlen($first) < 2 || mb_strlen($last) < 2) {
            throw new \InvalidArgumentException('Prénom et nom obligatoires (2 caractères minimum).');
        }
        if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Adresse e-mail invalide.');
        }
        $role = strtoupper($role);
        if (!in_array($role, (array) $this->config->get('permissions.staff_roles'), true)) {
            throw new \InvalidArgumentException("Rôle du personnel inconnu : {$role}");
        }
        if ($this->db->fetchValue('SELECT COUNT(*) FROM users WHERE phone = ?', [$phone])) {
            throw new \RuntimeException('Un compte existe déjà avec ce numéro.');
        }
        $temporary = $password === null ? $this->hasher->temporaryPassword() : null;
        $email = ($email === null || $email === '') ? null : mb_strtolower(trim($email));

        $id = $this->db->transaction(function (Database $db) use ($phone, $first, $last, $email, $role, $password, $temporary) {
            $id = $db->insert('users', [
                'uuid' => Uuid::v4(),
                'primary_type' => 'STAFF',
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'email' => $email,
                'password_hash' => $this->hasher->hash($password ?? (string) $temporary),
                'must_change_password' => $password === null ? 1 : 0,
                'password_changed_at' => date('Y-m-d H:i:s'),
                'status' => 'ACTIVE',
            ]);
            $db->insert('user_profiles', ['user_id' => $id, 'display_name' => $first . ' ' . $last, 'is_public' => 0, 'is_available' => 0]);
            $roleId = (int) $db->fetchValue('SELECT id FROM roles WHERE code = ?', [$role]);
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
            return $id;
        });
        (new AuditLogger($this->db))->record(null, 'staff.created', 'user', $id, null, ['role' => $role, 'phone' => Phone::mask($phone)], 'Création du compte ' . $role);
        return ['id' => $id, 'temporary_password' => $temporary];
    }
}
