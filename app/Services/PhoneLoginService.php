<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Secrets;
use App\Core\Settings;
use App\Core\Str;

/**
 * Connexion des CLIENTS par numéro de téléphone vérifié (code SMS à usage unique), sans mot de passe.
 * Les chauffeurs, livreurs, commerces, entreprises et le personnel gardent leur mot de passe
 * (comptes donnant accès à de l'argent ou à des données d'autres personnes).
 */
final class PhoneLoginService
{
    public static function enabled(): bool
    {
        return filter_var(Settings::get('auth.client_phone_login', true), FILTER_VALIDATE_BOOLEAN);
    }

    /** Étape 1 : envoi du code. Retourne ['password_required' => bool, 'debug_code' => ?string]. */
    public static function send(string $rawPhone, string $ip): array
    {
        if (!self::enabled()) {
            throw HttpException::forbidden('La connexion par code SMS est désactivée.');
        }
        $phone = Str::normalizePhone($rawPhone);
        if (!$phone) {
            throw \App\Core\ValidationException::field('phone', 'Numéro de téléphone invalide (8 chiffres au Niger, ex. 90 12 34 56).');
        }
        $u = Database::first('SELECT u.status, r.code role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.phone = :p', ['p' => $phone]);
        if ($u && $u['role'] !== 'client') {
            // Comptes professionnels : mot de passe obligatoire (aucun code envoyé)
            return ['password_required' => true, 'debug_code' => null];
        }
        if ($u && in_array($u['status'], ['suspended', 'deleted'], true)) {
            throw HttpException::forbidden('Ce compte est suspendu. Contactez l\'assistance MARWA.');
        }
        return ['password_required' => false, 'debug_code' => AuthService::sendOtp($phone, 'login', $ip)];
    }

    /**
     * Étape 2 : vérification. Compte existant → utilisateur ; nouveau numéro → jeton d'inscription
     * (le nom et l'acceptation des conditions sont demandés ensuite).
     */
    public static function verify(string $rawPhone, string $code): array
    {
        $phone = Str::normalizePhone($rawPhone);
        if (!$phone || !AuthService::verifyOtp($phone, 'login', $code)) {
            throw \App\Core\ValidationException::field('code', 'Code incorrect ou expiré.');
        }
        $u = Database::first("SELECT u.id, r.code role, u.status FROM users u JOIN roles r ON r.id = u.role_id WHERE u.phone = :p", ['p' => $phone]);
        if ($u) {
            if ($u['role'] !== 'client' || !in_array($u['status'], ['active', 'pending'], true)) {
                throw HttpException::forbidden('Connectez-vous avec votre mot de passe.');
            }
            Database::execute('UPDATE users SET phone_verified_at = COALESCE(phone_verified_at, NOW()), failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id', ['id' => $u['id']]);
            return ['user' => AuthService::findUser((int) $u['id'])];
        }
        $exp = time() + 900;
        return ['signup_token' => $exp . '.' . Secrets::hmac('phone-signup', $phone . '|' . $exp)];
    }

    /** Étape 3 (nouveau client) : création du compte avec le numéro déjà vérifié. */
    public static function register(string $rawPhone, string $token, string $fullName, bool $acceptTerms): array
    {
        $phone = Str::normalizePhone($rawPhone);
        [$exp, $sig] = array_pad(explode('.', $token, 2), 2, '');
        if (!$phone || (int) $exp < time() || !hash_equals(Secrets::hmac('phone-signup', $phone . '|' . (int) $exp), $sig)) {
            throw new HttpException(422, 'Vérification expirée : demandez un nouveau code.', 'signup_expired');
        }
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName));
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
            throw \App\Core\ValidationException::field('full_name', 'Indiquez votre nom (2 caractères minimum).');
        }
        if (!$acceptTerms) {
            throw \App\Core\ValidationException::field('accept_terms', 'Vous devez accepter les conditions d\'utilisation.');
        }
        if (Database::value('SELECT 1 FROM users WHERE phone = :p', ['p' => $phone])) {
            throw HttpException::conflict('Un compte existe déjà avec ce numéro : reconnectez-vous.');
        }
        $id = Database::transaction(static function () use ($phone, $fullName) {
            $id = Database::insert('users', [
                'uuid' => Str::uuid(),
                'role_id' => (int) Database::value("SELECT id FROM roles WHERE code = 'client'"),
                'full_name' => $fullName,
                'phone' => $phone,
                // Aucun mot de passe : valeur aléatoire inutilisable (connexion par code SMS uniquement)
                'password_hash' => Auth::hashPassword(bin2hex(random_bytes(32))),
                'status' => 'active',
                'phone_verified_at' => date('Y-m-d H:i:s'),
                'last_login_at' => date('Y-m-d H:i:s'),
            ]);
            Database::insert('customer_profiles', ['user_id' => $id, 'city_id' => (int) Database::value('SELECT id FROM cities WHERE is_active = 1 ORDER BY id LIMIT 1') ?: null]);
            return $id;
        });
        Audit::log('auth.phone_signup', 'user', (int) $id, null, null, (int) $id);
        return AuthService::findUser((int) $id);
    }
}
