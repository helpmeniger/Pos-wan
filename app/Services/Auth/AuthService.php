<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Request;
use App\Core\Session;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\SecurityLogger;
use App\Support\Phone;

/**
 * Connexion / déconnexion / utilisateur courant.
 * - Messages d'erreur génériques (pas d'énumération de comptes), temps de réponse égalisé.
 * - Limitation par IP + verrouillage temporaire du compte après N échecs.
 * - Régénération de l'identifiant de session après connexion.
 * - Les sessions ouvertes sont invalidées après un changement de mot de passe.
 */
class AuthService
{
    /** @var array<string, mixed>|null|false false = pas encore chargé */
    private array|null|false $current = false;

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly SettingRepository $settings,
        private readonly SecurityLogger $security,
        private readonly PermissionService $permissions,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->current !== false) {
            return $this->current;
        }
        $id = $this->session->get('auth_user_id');
        if (!is_int($id)) {
            return $this->current = null;
        }
        $user = $this->users->find($id);
        // Compte désactivé ou mot de passe changé depuis l'ouverture de la session → déconnexion
        if ($user === null || $user['status'] !== 'ACTIVE'
            || (string) ($user['password_changed_at'] ?? '') !== (string) $this->session->get('auth_pwd_stamp', '')) {
            $this->session->forget('auth_user_id');
            return $this->current = null;
        }
        return $this->current = $user;
    }

    public function id(): ?int
    {
        $u = $this->user();
        return $u !== null ? (int) $u['id'] : null;
    }

    /**
     * @return array{ok: bool, error?: string, user?: array<string, mixed>}
     */
    public function attempt(string $login, string $password, Request $request): array
    {
        $generic = 'Identifiants incorrects. Vérifiez votre numéro (ou e-mail) et votre mot de passe.';
        $ipKey = 'login_ip:ip:' . $request->ip();
        if (!$this->limiter->attempt($ipKey, 'login_ip')) {
            $this->security->log('login.rate_limited', null, $request, ['login' => self::maskLogin($login)], 'AVERTISSEMENT');
            return ['ok' => false, 'error' => 'Trop de tentatives depuis cette connexion. Réessayez dans quelques minutes.'];
        }

        $normalized = str_contains($login, '@') ? mb_strtolower(trim($login)) : (Phone::normalize($login) ?? '');
        $user = $normalized !== '' ? $this->users->findForAuth($normalized) : null;
        if ($user === null) {
            $this->hasher->dummyVerify($password);
            $this->security->log('login.failed', null, $request, ['login' => self::maskLogin($login), 'reason' => 'unknown']);
            return ['ok' => false, 'error' => $generic];
        }

        $userId = (int) $user['id'];
        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            $this->hasher->dummyVerify($password);
            $this->security->log('login.locked', $userId, $request, [], 'AVERTISSEMENT');
            return ['ok' => false, 'error' => 'Ce compte est temporairement verrouillé après plusieurs tentatives. Réessayez plus tard ou réinitialisez votre mot de passe.'];
        }

        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->users->registerFailedLogin(
                $userId,
                max(1, (int) $this->settings->get('auth.max_login_attempts', 5)),
                (int) $this->settings->get('auth.lockout_minutes', 15)
            );
            $this->security->log('login.failed', $userId, $request, ['reason' => 'password']);
            return ['ok' => false, 'error' => $generic];
        }

        if ($user['status'] !== 'ACTIVE') {
            $this->security->log('login.blocked_status', $userId, $request, ['status' => $user['status']], 'AVERTISSEMENT');
            $message = match ($user['status']) {
                'SUSPENDED' => 'Votre compte est suspendu. Contactez le support pour connaître la procédure de recours.',
                'BANNED' => 'Votre compte a été fermé. Contactez le support pour toute réclamation.',
                'DEACTIVATED' => 'Ce compte a été désactivé.',
                default => 'Votre compte n\'est pas encore activé.',
            };
            return ['ok' => false, 'error' => $message];
        }

        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->users->updatePassword($userId, $this->hasher->hash($password), (bool) $user['must_change_password']);
            $user = $this->users->findWithHash($userId) ?? $user;
        }

        $this->users->registerSuccessfulLogin($userId, $request->ip());
        $this->login($user);
        $this->security->log('login.success', $userId, $request);
        return ['ok' => true, 'user' => $this->user() ?? []];
    }

    /** @param array<string, mixed> $user */
    public function login(array $user): void
    {
        $this->session->regenerate();
        $this->session->put('auth_user_id', (int) $user['id']);
        $this->session->put('auth_pwd_stamp', (string) ($user['password_changed_at'] ?? ''));
        $this->current = false;
        $this->permissions->flush((int) $user['id']);
    }

    public function loginById(int $userId): void
    {
        $user = $this->users->findWithHash($userId) ?? throw new \RuntimeException('Utilisateur introuvable');
        $this->login($user);
    }

    /** Après un changement de mot de passe par l'utilisateur lui-même : garder sa session. */
    public function refreshPasswordStamp(): void
    {
        $id = $this->session->get('auth_user_id');
        if (is_int($id)) {
            $row = $this->users->findWithHash($id);
            $this->session->regenerate();
            $this->session->put('auth_pwd_stamp', (string) ($row['password_changed_at'] ?? ''));
            $this->current = false;
        }
    }

    public function logout(?Request $request = null): void
    {
        $id = $this->id();
        if ($id !== null) {
            $this->security->log('logout', $id, $request);
        }
        $this->session->invalidate();
        $this->current = null;
    }

    /** Page d'accueil de l'utilisateur selon ses rôles. */
    public function homePath(int $userId): string
    {
        $map = (array) config('permissions.home_by_role', []);
        $roles = $this->permissions->roles($userId);
        foreach ($map as $role => $path) {
            if (in_array($role, $roles, true)) {
                return $path;
            }
        }
        return '/';
    }

    private static function maskLogin(string $login): string
    {
        $phone = Phone::normalize($login);
        if ($phone !== null) {
            return Phone::mask($phone);
        }
        return mb_substr($login, 0, 2) . '***';
    }
}
