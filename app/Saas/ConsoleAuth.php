<?php
declare(strict_types=1);

namespace App\Saas;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\Session;

/**
 * Comptes de l'exploitant du service (console). Distincts des utilisateurs des clients.
 * Aucun compte par défaut : le premier est créé en ligne de commande (php bin/saas.php admin).
 */
final class ConsoleAuth
{
    private const IDLE = 1800;

    public static function id(): ?int
    {
        if (!Saas::isCentral() || session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $id = (int) Session::get('saas_admin_id', 0);
        if ($id && time() - (int) Session::get('saas_admin_seen', 0) > self::IDLE) {
            self::logout();
            return null;
        }
        if ($id) {
            Session::set('saas_admin_seen', time());
        }
        return $id ?: null;
    }

    public static function admin(): ?array
    {
        $id = self::id();
        return $id ? Central::one('SELECT id, username, full_name FROM saas_admins WHERE id = ? AND active = 1', [$id]) : null;
    }

    public static function require(): array
    {
        $a = self::admin();
        if (!$a) {
            flash('warning', 'Connectez-vous à la console.');
            redirect('/console/login');
        }
        return $a;
    }

    public static function attempt(string $username, string $password): array
    {
        if (!Central::throttle('console_login', 20, 15)) {
            throw new BusinessException('Trop de tentatives depuis cette adresse. Réessayez dans 15 minutes.');
        }
        $a = Central::one('SELECT * FROM saas_admins WHERE username = ?', [$username]);
        if ($a && $a['locked_until'] && strtotime($a['locked_until']) > time()) {
            throw new BusinessException('Compte verrouillé temporairement après plusieurs échecs. Réessayez plus tard.');
        }
        if (!$a || !(int) $a['active'] || !password_verify($password, $a['password_hash'])) {
            if ($a) {
                $n = (int) $a['failed_attempts'] + 1;
                Central::update('saas_admins', ['failed_attempts' => $n, 'locked_until' => $n >= 5 ? date('Y-m-d H:i:s', time() + 900) : null], 'id = ?', [$a['id']]);
            }
            Central::audit('console.login_failed', null, ['username' => mb_substr($username, 0, 50)], 0);
            throw new BusinessException('Identifiant ou mot de passe incorrect.');
        }
        Central::update('saas_admins', ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$a['id']]);
        Session::regenerate();
        Session::set('saas_admin_id', (int) $a['id']);
        Session::set('saas_admin_seen', time());
        Central::audit('console.login', null, [], (int) $a['id']);
        return $a;
    }

    public static function logout(): void
    {
        Session::forget('saas_admin_id');
        Session::forget('saas_admin_seen');
        Session::regenerate();
    }

    public static function create(string $username, string $fullName, string $password): int
    {
        if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $username)) {
            throw new BusinessException('Identifiant invalide (3 à 50 caractères : lettres, chiffres, . _ -).');
        }
        if ($err = Auth::passwordPolicyError($password, $username)) {
            throw new BusinessException($err);
        }
        if (Central::value('SELECT 1 FROM saas_admins WHERE username = ?', [$username])) {
            throw new BusinessException('Cet identifiant existe déjà.');
        }
        $id = Central::insert('saas_admins', ['username' => $username, 'full_name' => $fullName ?: $username, 'password_hash' => Auth::hash($password)]);
        Central::audit('console.admin_create', null, ['username' => $username], 0);
        return $id;
    }
}
