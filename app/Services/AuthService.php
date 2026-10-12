<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Str;
use App\Core\Validator;
use App\Services\Notification\SmsSender;

final class AuthService
{
    public const SELF_SERVICE_ROLES = ['client', 'driver', 'courier', 'merchant', 'business'];

    public static function register(array $input): array
    {
        $v = Validator::make($input, [
            'full_name' => 'required|string|min:2|max:120',
            'phone' => 'nullable|phone',
            'email' => 'nullable|email',
            'password' => 'required|password',
            'role' => 'required|in:' . implode(',', self::SELF_SERVICE_ROLES),
            'accept_terms' => 'required|bool',
            // Données complémentaires selon le profil
            'business_name' => 'nullable|string|max:150',
            'merchant_kind' => 'nullable|in:restaurant,shop',
            'merchant_address' => 'nullable|string|max:255',
            'merchant_lat' => 'nullable|lat',
            'merchant_lng' => 'nullable|lng',
        ]);
        if (!$v['accept_terms']) {
            throw \App\Core\ValidationException::field('accept_terms', 'Vous devez accepter les conditions d\'utilisation.');
        }
        if (empty($v['phone']) && empty($v['email'])) {
            throw \App\Core\ValidationException::field('phone', 'Indiquez un numéro de téléphone ou une adresse e-mail.');
        }
        if (in_array($v['role'], ['driver', 'courier'], true) && empty($v['phone'])) {
            throw \App\Core\ValidationException::field('phone', 'Le numéro de téléphone est obligatoire pour les prestataires.');
        }
        if (in_array($v['role'], ['merchant', 'business'], true) && empty($v['business_name'])) {
            throw \App\Core\ValidationException::field('business_name', 'Indiquez le nom commercial.');
        }
        if ($v['role'] === 'merchant' && (empty($v['merchant_address']) || !isset($v['merchant_lat'], $v['merchant_lng']))) {
            throw \App\Core\ValidationException::field('merchant_address', 'Indiquez l\'adresse et placez le commerce sur la carte.');
        }
        if (!empty($v['phone']) && Database::value('SELECT 1 FROM users WHERE phone = :p', ['p' => $v['phone']])) {
            throw \App\Core\ValidationException::field('phone', 'Ce numéro est déjà associé à un compte.');
        }
        if (!empty($v['email']) && Database::value('SELECT 1 FROM users WHERE email = :e', ['e' => $v['email']])) {
            throw \App\Core\ValidationException::field('email', 'Cette adresse e-mail est déjà associée à un compte.');
        }

        $userId = Database::transaction(static function () use ($v) {
            $roleId = (int) Database::value('SELECT id FROM roles WHERE code = :c', ['c' => $v['role']]);
            $id = Database::insert('users', [
                'uuid' => Str::uuid(),
                'role_id' => $roleId,
                'full_name' => $v['full_name'],
                'phone' => $v['phone'] ?? null,
                'email' => $v['email'] ?? null,
                'password_hash' => Auth::hashPassword($v['password']),
                'status' => 'active',
            ]);
            $cityId = (int) Database::value('SELECT id FROM cities WHERE is_active = 1 ORDER BY id LIMIT 1') ?: null;
            switch ($v['role']) {
                case 'client':
                    Database::insert('customer_profiles', ['user_id' => $id, 'city_id' => $cityId]);
                    break;
                case 'driver':
                case 'courier':
                    Database::insert('provider_profiles', ['user_id' => $id, 'provider_type' => $v['role'], 'city_id' => $cityId]);
                    break;
                case 'merchant':
                    Database::insert('merchants', [
                        'owner_user_id' => $id, 'kind' => $v['merchant_kind'] ?? 'restaurant', 'name' => $v['business_name'],
                        'phone' => $v['phone'] ?? null, 'address_text' => $v['merchant_address'], 'lat' => $v['merchant_lat'], 'lng' => $v['merchant_lng'],
                        'city_id' => $cityId ?? 1,
                    ]);
                    break;
                case 'business':
                    $bid = Database::insert('businesses', ['owner_user_id' => $id, 'name' => $v['business_name'], 'billing_email' => $v['email'] ?? null]);
                    Database::insert('business_members', ['business_id' => $bid, 'user_id' => $id, 'member_role' => 'owner']);
                    Database::insert('customer_profiles', ['user_id' => $id, 'city_id' => $cityId]);
                    break;
            }
            return $id;
        });
        $user = self::findUser($userId);
        NotificationService::notify($userId, 'welcome', 'Bienvenue sur MARWA',
            in_array($v['role'], ['driver', 'courier', 'merchant', 'business'], true)
                ? 'Votre compte a été créé. Complétez votre profil et vos documents : l\'équipe MARWA les vérifiera avant activation.'
                : 'Votre compte a été créé. Vous pouvez commander dès maintenant.', [], ['email']);
        if ($v['role'] !== 'client') {
            NotificationService::staff('partners.validate', 'partner_signup', 'Nouveau partenaire à valider', $v['full_name'] . ' (' . $v['role'] . ') s\'est inscrit.', ['user_id' => $userId]);
        }
        return $user;
    }

    public static function attempt(string $identifier, string $password, Request $request): array
    {
        $identifier = trim($identifier);
        $ipKey = 'login_ip:' . $request->ip();
        RateLimiter::enforce($ipKey, 30, 900, 'Trop de tentatives de connexion depuis cette adresse. Réessayez dans 15 minutes.');
        $phone = Str::normalizePhone($identifier);
        $user = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? Database::first('SELECT * FROM users WHERE email = :e', ['e' => mb_strtolower($identifier)])
            : ($phone ? Database::first('SELECT * FROM users WHERE phone = :p', ['p' => $phone]) : null);

        $generic = new HttpException(401, 'Identifiant ou mot de passe incorrect.', 'invalid_credentials');
        if (!$user) {
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$RdescudvJCsgt3ub+b+dWRWJTmaaJObG'); // temps constant
            throw $generic;
        }
        if ($user['locked_until'] && $user['locked_until'] > date('Y-m-d H:i:s')) {
            throw new HttpException(423, 'Compte temporairement verrouillé après plusieurs échecs. Réessayez plus tard ou réinitialisez votre mot de passe.', 'account_locked');
        }
        if (!password_verify($password, $user['password_hash'])) {
            $fails = (int) $user['failed_logins'] + 1;
            $max = (int) Config::get('security.login_max_attempts', 5);
            Database::update('users', [
                'failed_logins' => $fails >= $max ? 0 : $fails,
                'locked_until' => $fails >= $max ? date('Y-m-d H:i:s', time() + 60 * (int) Config::get('security.login_lock_minutes', 15)) : null,
            ], 'id = :id', ['id' => $user['id']]);
            if ($fails >= $max) {
                Audit::log('auth.locked', 'user', (int) $user['id'], null, null, (int) $user['id']);
            }
            throw $generic;
        }
        if (in_array($user['status'], ['suspended', 'deleted'], true)) {
            throw HttpException::forbidden($user['status'] === 'suspended' ? 'Votre compte est suspendu. Contactez l\'assistance MARWA.' : 'Ce compte n\'existe plus.');
        }
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Database::update('users', ['password_hash' => Auth::hashPassword($password)], 'id = :id', ['id' => $user['id']]);
        }
        Database::update('users', ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $user['id']]);
        $staff = (bool) Database::value('SELECT is_staff FROM roles WHERE id = :r', ['r' => $user['role_id']]);
        if ($staff) {
            Audit::log('auth.admin_login', 'user', (int) $user['id'], null, ['ip' => $request->ip()], (int) $user['id']);
        }
        return self::findUser((int) $user['id']);
    }

    public static function findUser(int $id): array
    {
        $u = Database::first('SELECT u.*, r.code AS role, r.is_staff FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id', ['id' => $id]);
        if (!$u) {
            throw HttpException::notFound();
        }
        return $u;
    }

    /** Représentation publique de l'utilisateur courant (aucun secret). */
    public static function present(array $u): array
    {
        $out = [
            'id' => (int) $u['id'],
            'uuid' => $u['uuid'],
            'full_name' => $u['full_name'],
            'phone' => $u['phone'],
            'email' => $u['email'],
            'role' => $u['role'],
            'is_staff' => (bool) $u['is_staff'],
            'status' => $u['status'],
            'phone_verified' => $u['phone_verified_at'] !== null,
            'photo_url' => $u['photo_file_id'] ? url('fichiers/' . $u['photo_file_id']) : null,
            'permissions' => Auth::id() === (int) $u['id'] ? Auth::permissions() : [],
        ];
        if (in_array($u['role'], ['driver', 'courier'], true)) {
            $p = Database::first('SELECT verification_status, verification_note, is_online, current_order_id, rating_avg, rating_count FROM provider_profiles WHERE user_id = :id', ['id' => $u['id']]);
            $out['provider'] = $p ? ['verification_status' => $p['verification_status'], 'verification_note' => $p['verification_note'], 'is_online' => (bool) $p['is_online'],
                'current_order_id' => $p['current_order_id'] ? (int) $p['current_order_id'] : null, 'rating' => (float) $p['rating_avg'], 'rating_count' => (int) $p['rating_count']] : null;
        }
        if ($u['role'] === 'merchant') {
            $m = Database::first('SELECT id, name, status, is_open, delivery_mode FROM merchants WHERE owner_user_id = :id ORDER BY id LIMIT 1', ['id' => $u['id']]);
            $out['merchant'] = $m ? ['id' => (int) $m['id'], 'name' => $m['name'], 'status' => $m['status'], 'is_open' => (bool) $m['is_open'], 'delivery_mode' => $m['delivery_mode']] : null;
        }
        $biz = Database::select("SELECT b.id, b.name, b.status, b.postpaid_enabled, m.member_role FROM business_members m JOIN businesses b ON b.id = m.business_id WHERE m.user_id = :u AND m.status = 'active'", ['u' => $u['id']]);
        $out['businesses'] = array_map(static fn ($b) => ['id' => (int) $b['id'], 'name' => $b['name'], 'status' => $b['status'], 'postpaid_enabled' => (bool) $b['postpaid_enabled'], 'member_role' => $b['member_role']], $biz);
        return $out;
    }

    // ------------------------------------------------------------------
    // OTP téléphone et réinitialisation du mot de passe
    // ------------------------------------------------------------------

    /** Envoie un code à usage unique. En mode « log », le code est écrit dans storage/logs/outbox.log. */
    public static function sendOtp(string $target, string $purpose, string $ip): ?string
    {
        RateLimiter::enforce('otp_target:' . $target, 3, 900, 'Trop de codes demandés. Patientez 15 minutes.');
        RateLimiter::enforce('otp_ip:' . $ip, 10, 3600);
        $code = Str::digits(6);
        Database::execute("UPDATE otp_codes SET consumed_at = NOW() WHERE target = :t AND purpose = :p AND consumed_at IS NULL", ['t' => $target, 'p' => $purpose]);
        Database::insert('otp_codes', ['target' => $target, 'purpose' => $purpose, 'code_hash' => password_hash($code, PASSWORD_BCRYPT), 'expires_at' => date('Y-m-d H:i:s', time() + 600)]);
        SmsSender::send($target, "MARWA : votre code est $code. Il expire dans 10 minutes. Ne le communiquez à personne.");
        // En développement uniquement (SMS non réels), le code est renvoyé pour faciliter les tests.
        return (!SmsSender::isReal() && Config::get('app.env') !== 'production') ? $code : null;
    }

    public static function verifyOtp(string $target, string $purpose, string $code): bool
    {
        $row = Database::first('SELECT * FROM otp_codes WHERE target = :t AND purpose = :p AND consumed_at IS NULL ORDER BY id DESC LIMIT 1', ['t' => $target, 'p' => $purpose]);
        if (!$row || $row['expires_at'] < date('Y-m-d H:i:s') || (int) $row['attempts'] >= 5) {
            return false;
        }
        if (!password_verify(preg_replace('/\D/', '', $code) ?? '', $row['code_hash'])) {
            Database::execute('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id', ['id' => $row['id']]);
            return false;
        }
        Database::execute('UPDATE otp_codes SET consumed_at = NOW() WHERE id = :id', ['id' => $row['id']]);
        return true;
    }

    /** Demande de réinitialisation : réponse identique que le compte existe ou non. */
    public static function forgotPassword(string $identifier, string $ip): ?string
    {
        RateLimiter::enforce('forgot:' . $ip, 5, 3600);
        $phone = Str::normalizePhone($identifier);
        $email = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? mb_strtolower($identifier) : null;
        $user = $email ? Database::first('SELECT * FROM users WHERE email = :e AND status IN (\'active\',\'pending\')', ['e' => $email])
            : ($phone ? Database::first('SELECT * FROM users WHERE phone = :p AND status IN (\'active\',\'pending\')', ['p' => $phone]) : null);
        if (!$user) {
            return null;
        }
        if ($email) {
            $token = Str::token(32);
            Database::insert('password_resets', ['user_id' => $user['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
            NotificationService::queue('email', $email, 'MARWA — Réinitialisation du mot de passe',
                "Bonjour,\n\nPour choisir un nouveau mot de passe, ouvrez ce lien (valable 1 heure) :\n" . Config::get('app.url') . '/reinitialiser?jeton=' . $token .
                "\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message.");
            return null;
        }
        return self::sendOtp($phone, 'reset_password', $ip);
    }

    public static function resetPassword(array $input): void
    {
        $v = Validator::make($input, ['token' => 'nullable|string|max:100', 'phone' => 'nullable|phone', 'code' => 'nullable|string|max:10', 'password' => 'required|password']);
        $userId = null;
        if (!empty($v['token'])) {
            $row = Database::first('SELECT * FROM password_resets WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()', ['h' => hash('sha256', $v['token'])]);
            if ($row) {
                $userId = (int) $row['user_id'];
                Database::execute('UPDATE password_resets SET used_at = NOW() WHERE id = :id', ['id' => $row['id']]);
            }
        } elseif (!empty($v['phone']) && !empty($v['code']) && self::verifyOtp($v['phone'], 'reset_password', $v['code'])) {
            $userId = (int) Database::value('SELECT id FROM users WHERE phone = :p', ['p' => $v['phone']]);
        }
        if (!$userId) {
            throw new HttpException(422, 'Lien ou code invalide ou expiré.', 'invalid_reset');
        }
        Database::update('users', ['password_hash' => Auth::hashPassword($v['password']), 'failed_logins' => 0, 'locked_until' => null], 'id = :id', ['id' => $userId]);
        Database::execute('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = :u AND revoked_at IS NULL', ['u' => $userId]);
        Audit::log('auth.password_reset', 'user', $userId, null, null, $userId);
        NotificationService::notify($userId, 'security', 'Mot de passe modifié', 'Votre mot de passe vient d\'être modifié. Si ce n\'est pas vous, contactez immédiatement l\'assistance.', [], ['sms', 'email']);
    }

    /** Suppression de compte : anonymisation des données personnelles, conservation des écritures financières. */
    public static function deleteAccount(int $userId, string $password): void
    {
        $u = Database::first('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            throw \App\Core\ValidationException::field('password', 'Mot de passe incorrect.');
        }
        $open = (int) Database::value("SELECT COUNT(*) FROM orders WHERE (customer_id = :u OR provider_id = :u2) AND status IN ('" . implode("','", OrderService::OPEN_STATUSES) . "')", ['u' => $userId, 'u2' => $userId]);
        if ($open > 0) {
            throw HttpException::conflict('Terminez ou annulez vos commandes en cours avant de supprimer votre compte.');
        }
        $p = Database::first('SELECT provider_type FROM provider_profiles WHERE user_id = :u', ['u' => $userId]);
        if ($p && LedgerService::partnerBalance('provider', $userId) !== 0) {
            throw HttpException::conflict('Votre solde doit être soldé (versement ou reversement) avant la suppression du compte.');
        }
        Database::transaction(static function () use ($userId) {
            Database::update('users', [
                'full_name' => 'Utilisateur supprimé', 'phone' => null, 'email' => null, 'photo_file_id' => null,
                'password_hash' => Auth::hashPassword(Str::token(24)), 'status' => 'deleted', 'deleted_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $userId]);
            Database::execute('DELETE FROM addresses WHERE user_id = :u', ['u' => $userId]);
            Database::execute('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = :u', ['u' => $userId]);
            Database::execute('UPDATE provider_profiles SET is_online = 0, id_number = NULL, license_number = NULL WHERE user_id = :u', ['u' => $userId]);
            Database::execute('DELETE FROM provider_locations WHERE provider_id = :u', ['u' => $userId]);
        });
        Audit::log('account.deleted', 'user', $userId, null, null, $userId);
    }
}
