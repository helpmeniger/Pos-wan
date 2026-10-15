<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Settings;
use App\Core\Str;
use App\Support\Totp;

/**
 * Sécurité des comptes : double authentification (TOTP) et codes de secours,
 * déconnexion de tous les appareils, alerte de connexion depuis un nouvel appareil,
 * refus des mots de passe trop courants.
 */
final class SecurityService
{
    public static function totpEnabled(array $user): bool
    {
        return !empty($user['totp_enabled_at']) && !empty($user['totp_secret_enc']);
    }

    /** La double authentification est-elle obligatoire pour ce compte ? */
    public static function totpRequired(array $user): bool
    {
        return !empty($user['is_staff']) && filter_var(Settings::get('security.staff_2fa_required', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Étape 1 : secret provisoire (non actif tant qu'un code n'a pas été confirmé). */
    public static function beginTotp(array $user): array
    {
        if (self::totpEnabled($user)) {
            throw HttpException::conflict('La double authentification est déjà activée.');
        }
        $secret = Totp::newSecret();
        Database::update('users', ['totp_secret_enc' => Crypto::encrypt($secret), 'totp_enabled_at' => null], 'id = :id', ['id' => $user['id']]);
        $account = $user['email'] ?: ($user['phone'] ?: 'compte-' . $user['id']);
        return ['secret' => trim(chunk_split($secret, 4, ' ')), 'uri' => Totp::uri($secret, (string) $account)];
    }

    /** Étape 2 : confirmation par un premier code ; renvoie les codes de secours (affichés une seule fois). */
    public static function confirmTotp(array $user, string $code): array
    {
        $u = Database::first('SELECT totp_secret_enc, totp_enabled_at FROM users WHERE id = :id', ['id' => $user['id']]);
        $secret = $u && $u['totp_secret_enc'] ? Crypto::decrypt($u['totp_secret_enc']) : null;
        if (!$secret || $u['totp_enabled_at']) {
            throw HttpException::conflict('Recommencez l\'activation de la double authentification.');
        }
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            throw \App\Core\ValidationException::field('code', 'Code incorrect. Vérifiez l\'heure du téléphone et réessayez.');
        }
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(substr(Str::token(8), 0, 4) . '-' . substr(Str::token(8), 0, 4));
        }
        Database::update('users', [
            'totp_enabled_at' => date('Y-m-d H:i:s'), 'totp_last_step' => $step,
            'totp_recovery_json' => json_encode(array_map(fn ($c) => password_hash($c, PASSWORD_DEFAULT), $codes)),
        ], 'id = :id', ['id' => $user['id']]);
        Audit::log('auth.2fa_enabled', 'user', (int) $user['id']);
        NotificationService::notify((int) $user['id'], 'security', 'Double authentification activée', 'Un code de votre application d\'authentification sera demandé à chaque connexion.');
        return $codes;
    }

    /** Vérifie un code TOTP ou un code de secours (consommé). */
    public static function checkSecondFactor(int $userId, string $code): bool
    {
        $u = Database::first('SELECT totp_secret_enc, totp_last_step, totp_recovery_json FROM users WHERE id = :id', ['id' => $userId]);
        $secret = $u && $u['totp_secret_enc'] ? Crypto::decrypt($u['totp_secret_enc']) : null;
        if (!$secret) {
            return false;
        }
        $step = Totp::verify($secret, $code, $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null);
        if ($step !== null) {
            Database::update('users', ['totp_last_step' => $step], 'id = :id', ['id' => $userId]);
            return true;
        }
        $norm = strtoupper(trim($code));
        $hashes = json_decode((string) $u['totp_recovery_json'], true) ?: [];
        foreach ($hashes as $i => $h) {
            if (preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $norm) && password_verify($norm, $h)) {
                unset($hashes[$i]);
                Database::update('users', ['totp_recovery_json' => json_encode(array_values($hashes))], 'id = :id', ['id' => $userId]);
                Audit::log('auth.2fa_recovery_used', 'user', $userId, null, ['remaining' => count($hashes)], $userId);
                return true;
            }
        }
        return false;
    }

    public static function disableTotp(array $user, string $password, string $code): void
    {
        $u = AuthService::findUser((int) $user['id']);
        if (!password_verify($password, $u['password_hash'])) {
            throw \App\Core\ValidationException::field('password', 'Mot de passe incorrect.');
        }
        if (!self::checkSecondFactor((int) $u['id'], $code)) {
            throw \App\Core\ValidationException::field('code', 'Code incorrect.');
        }
        if (self::totpRequired($u)) {
            throw HttpException::forbidden('La double authentification est obligatoire pour les comptes de l\'équipe MARWA.');
        }
        Database::update('users', ['totp_secret_enc' => null, 'totp_enabled_at' => null, 'totp_last_step' => null, 'totp_recovery_json' => null], 'id = :id', ['id' => $u['id']]);
        Audit::log('auth.2fa_disabled', 'user', (int) $u['id']);
    }

    /** Déconnecte toutes les sessions et révoque tous les jetons du compte. */
    public static function logoutEverywhere(int $userId): void
    {
        Database::execute('UPDATE users SET session_version = session_version + 1 WHERE id = :id', ['id' => $userId]);
        Database::execute('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = :u AND revoked_at IS NULL', ['u' => $userId]);
        Database::execute('DELETE FROM push_subscriptions WHERE user_id = :u', ['u' => $userId]);
        Audit::log('auth.logout_everywhere', 'user', $userId, null, null, $userId);
    }

    /** Enregistre l'appareil et prévient l'utilisateur s'il est nouveau. */
    public static function recordDevice(array $user, Request $r): void
    {
        $ua = $r->userAgent();
        $id = substr(hash('sha256', $ua), 0, 16);
        $known = json_decode((string) ($user['known_devices_json'] ?? ''), true) ?: [];
        $isNew = !isset($known[$id]);
        $known[$id] = date('Y-m-d H:i:s');
        arsort($known);
        $known = array_slice($known, 0, 10, true);
        Database::update('users', ['known_devices_json' => json_encode($known)], 'id = :id', ['id' => $user['id']]);
        if ($isNew && count($known) > 1) {
            NotificationService::notify((int) $user['id'], 'security', 'Nouvelle connexion à votre compte',
                'Connexion depuis un nouvel appareil (' . self::deviceLabel($ua) . ') le ' . date('d/m/Y à H:i') . '. Si ce n\'est pas vous, changez votre mot de passe et déconnectez tous les appareils.',
                [], !empty($user['email']) ? ['email'] : []);
        }
    }

    public static function deviceLabel(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android', (bool) preg_match('/iPhone|iPad/', $ua) => 'iPhone/iPad',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => 'appareil inconnu',
        };
        $br = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox', str_contains($ua, 'Safari/') => 'Safari', default => 'navigateur',
        };
        return $br . ', ' . $os;
    }

    /** Mots de passe trop courants ou liés à MARWA / au Niger, refusés à l'inscription et au changement. */
    public static function weakPassword(string $password, array $context = []): ?string
    {
        $p = mb_strtolower($password);
        $common = ['password', 'motdepasse', 'azerty', 'qwerty', '123456', '12345678', '123456789', '1234567890', 'abc123', 'admin', 'administrateur',
            'bienvenue', 'welcome', 'marwa', 'niger', 'niamey', 'zinder', 'maradi', 'tahoua', 'agadez', 'dosso', 'tillaberi', 'diffa', 'soleil', 'bonjour',
            'iloveyou', 'jetaime', 'football', 'password1', 'azerty123', 'qwerty123', 'passw0rd', 'p@ssw0rd', 'demo2026', 'test1234', 'changeme', 'allah', 'bismillah'];
        $stripped = preg_replace('/[^a-z]/', '', $p);
        foreach ($common as $c) {
            if ($p === $c || $stripped === preg_replace('/[^a-z]/', '', $c) && $stripped !== '' && strlen($stripped) >= 4 && preg_match('/^' . preg_quote($stripped, '/') . '[\d!@#.]*$/', $p)) {
                return 'Ce mot de passe est trop courant. Choisissez-en un plus original.';
            }
        }
        if (preg_match('/^(.)\1+$/', $password) || preg_match('/^(0123|1234|2345|3456|4567|5678|6789|9876|8765)/', $password) && ctype_digit(substr($password, 0, 8))) {
            return 'Ce mot de passe est trop facile à deviner.';
        }
        foreach ($context as $c) {
            $c = mb_strtolower((string) $c);
            $digits = preg_replace('/\D/', '', $c);
            $tokens = strlen($digits) >= 8 ? [substr($digits, -8)] : preg_split('/[^\p{L}\d]+/u', explode('@', $c)[0]);
            foreach ($tokens as $t) {
                if (mb_strlen($t) >= 4 && str_contains($p, $t)) {
                    return 'Le mot de passe ne doit pas contenir votre nom, votre e-mail ou votre numéro de téléphone.';
                }
            }
        }
        return null;
    }

    /** Liste d'adresses IP autorisées pour l'administration (ADMIN_ALLOWED_IPS, vide = toutes). */
    public static function adminIpAllowed(string $ip): bool
    {
        $list = array_filter(array_map('trim', explode(',', (string) \App\Core\Env::get('ADMIN_ALLOWED_IPS', ''))));
        if (!$list) {
            return true;
        }
        foreach ($list as $rule) {
            if ($rule === $ip || (str_contains($rule, '/') && self::inCidr($ip, $rule))) {
                return true;
            }
        }
        return false;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr, 2);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        return $rem === 0 || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xff << (8 - $rem)) & 0xff) === 0;
    }
}
