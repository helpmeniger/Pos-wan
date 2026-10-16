<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Services\AuthService;
use App\Services\SecurityService;
use App\Services\UploadService;

final class AuthController extends Controller
{
    public function csrf(Request $r): Response
    {
        return $this->json(['csrf' => Csrf::token()]);
    }

    public function register(Request $r): Response
    {
        $user = AuthService::register($r->all());
        if (Auth::via() !== 'token') {
            Auth::login($user, $r);
        }
        return $this->json(['user' => AuthService::present($user), 'csrf' => Csrf::token(), 'redirect' => self::home($user)], 201);
    }

    public function login(Request $r): Response
    {
        $v = $this->validate($r, ['identifier' => 'required|string|max:190', 'password' => 'required|string|max:200', 'remember' => 'nullable|bool']);
        $user = AuthService::attempt($v['identifier'], $v['password'], $r);
        return $this->secondStepOrLogin($user, $r, !empty($v['remember']));
    }

    private function secondStepOrLogin(array $user, Request $r, bool $remember): Response
    {
        if (SecurityService::totpEnabled($user)) {
            // Deuxième étape : le code de l'application d'authentification est exigé avant d'ouvrir la session
            \App\Core\Session::regenerate();
            \App\Core\Session::set('2fa', ['uid' => (int) $user['id'], 'exp' => time() + 300, 'tries' => 0, 'ua' => hash('sha256', $r->userAgent()), 'remember' => $remember]);
            return $this->json(['two_factor' => true, 'csrf' => Csrf::token()]);
        }
        return $this->completeLogin($user, $r, $remember);
    }

    // ------------------------------------------------------------------ Clients : connexion par code SMS

    /** POST /api/auth/phone/send — envoie un code de connexion au numéro (clients uniquement). */
    public function phoneSend(Request $r): Response
    {
        $v = $this->validate($r, ['phone' => 'required|string|max:30']);
        $res = \App\Services\PhoneLoginService::send($v['phone'], $r->ip());
        if ($res['password_required']) {
            return $this->json(['password_required' => true, 'message' => 'Ce numéro correspond à un compte professionnel : connectez-vous avec votre mot de passe.']);
        }
        return $this->json(['sent' => true, 'message' => 'Code envoyé par SMS. Il expire dans 10 minutes.'] + ($res['debug_code'] ? ['debug_code' => $res['debug_code']] : []));
    }

    /** POST /api/auth/phone/verify — vérifie le code ; connecte le client ou demande son nom (nouveau compte). */
    public function phoneVerify(Request $r): Response
    {
        $v = $this->validate($r, ['phone' => 'required|string|max:30', 'code' => 'required|string|max:10', 'remember' => 'nullable|bool']);
        $res = \App\Services\PhoneLoginService::verify($v['phone'], $v['code']);
        if (isset($res['signup_token'])) {
            return $this->json(['needs_profile' => true, 'signup_token' => $res['signup_token']]);
        }
        return $this->secondStepOrLogin($res['user'], $r, !empty($v['remember']));
    }

    /** POST /api/auth/phone/register — nouveau client : nom + acceptation des conditions. */
    public function phoneRegister(Request $r): Response
    {
        $v = $this->validate($r, ['phone' => 'required|string|max:30', 'signup_token' => 'required|string|max:120', 'full_name' => 'required|string|max:120',
            'accept_terms' => 'nullable|bool', 'remember' => 'nullable|bool']);
        $user = \App\Services\PhoneLoginService::register($v['phone'], $v['signup_token'], $v['full_name'], !empty($v['accept_terms']));
        return $this->completeLogin($user, $r, !empty($v['remember']));
    }

    /** POST /api/auth/2fa — code à 6 chiffres ou code de secours. */
    public function twoFactor(Request $r): Response
    {
        $v = $this->validate($r, ['code' => 'required|string|max:20']);
        $c = \App\Core\Session::get('2fa');
        if (!is_array($c) || $c['exp'] < time() || $c['ua'] !== hash('sha256', $r->userAgent())) {
            \App\Core\Session::set('2fa', null);
            throw new \App\Core\HttpException(401, 'Délai dépassé. Reconnectez-vous.', 'two_factor_expired');
        }
        if (!SecurityService::checkSecondFactor((int) $c['uid'], $v['code'])) {
            $c['tries']++;
            \App\Core\Session::set('2fa', $c['tries'] >= 5 ? null : $c);
            Audit::log('auth.2fa_failed', 'user', (int) $c['uid'], null, ['ip' => $r->ip()], (int) $c['uid']);
            throw \App\Core\ValidationException::field('code', $c['tries'] >= 5 ? 'Trop d\'essais. Reconnectez-vous.' : 'Code incorrect.');
        }
        \App\Core\Session::set('2fa', null);
        return $this->completeLogin(AuthService::findUser((int) $c['uid']), $r, !empty($c['remember']));
    }

    private function completeLogin(array $user, Request $r, bool $remember = false): Response
    {
        Auth::login($user, $r);
        if ($remember) {
            \App\Services\RememberService::issue((int) $user['id'], $r);
        }
        SecurityService::recordDevice($user, $r);
        // La langue choisie sur l'appareil devient celle du compte (notifications push comprises)
        $lang = (string) ($r->cookies['mw_lang'] ?? '');
        if ($lang !== '' && $lang !== ($user['locale'] ?? 'fr') && \App\Support\I18n::valid($lang)) {
            Database::update('users', ['locale' => $lang], 'id = :id', ['id' => $user['id']]);
        }
        $out = ['user' => AuthService::present($user), 'csrf' => Csrf::token(), 'redirect' => self::home($user)];
        if (SecurityService::totpRequired($user) && !SecurityService::totpEnabled($user)) {
            $out['redirect'] = url('admin') . '#/profil';
            $out['two_factor_setup_required'] = true;
        }
        return $this->json($out);
    }

    /** Jeton Bearer pour les futures applications mobiles (pas de cookie, pas de CSRF). */
    public function token(Request $r): Response
    {
        $v = $this->validate($r, ['identifier' => 'required|string|max:190', 'password' => 'required|string|max:200', 'device_name' => 'nullable|string|max:80', 'code' => 'nullable|string|max:20']);
        $user = AuthService::attempt($v['identifier'], $v['password'], $r);
        if (SecurityService::totpEnabled($user)) {
            if (empty($v['code'])) {
                return $this->json(['two_factor' => true, 'message' => 'Renvoyez la requête avec « code » (application d\'authentification).'], 401);
            }
            if (!SecurityService::checkSecondFactor((int) $user['id'], $v['code'])) {
                throw \App\Core\ValidationException::field('code', 'Code incorrect.');
            }
        }
        SecurityService::recordDevice($user, $r);
        $token = Auth::issueToken((int) $user['id'], $v['device_name'] ?? 'mobile');
        Auth::actingAs((int) $user['id']);
        return $this->json(['token' => $token, 'token_type' => 'Bearer', 'expires_in_days' => 30, 'user' => AuthService::present($user)]);
    }

    // ------------------------------------------------------------------ Sécurité du compte

    public function security(Request $r): Response
    {
        $u = AuthService::findUser((int) $this->user()['id']);
        $devices = json_decode((string) ($u['known_devices_json'] ?? ''), true) ?: [];
        return $this->json([
            'two_factor' => SecurityService::totpEnabled($u),
            'two_factor_required' => SecurityService::totpRequired($u),
            'recovery_codes_left' => count(json_decode((string) $u['totp_recovery_json'], true) ?: []),
            'password_changed_at' => $u['password_changed_at'],
            'devices_count' => count($devices),
            'last_login_at' => $u['last_login_at'],
        ]);
    }

    public function totpBegin(Request $r): Response
    {
        return $this->json(SecurityService::beginTotp(AuthService::findUser((int) $this->user()['id'])));
    }

    public function totpConfirm(Request $r): Response
    {
        $v = $this->validate($r, ['code' => 'required|string|max:10']);
        return $this->json(['recovery_codes' => SecurityService::confirmTotp(AuthService::findUser((int) $this->user()['id']), $v['code'])]);
    }

    public function totpDisable(Request $r): Response
    {
        $v = $this->validate($r, ['password' => 'required|string|max:200', 'code' => 'required|string|max:20']);
        SecurityService::disableTotp($this->user(), $v['password'], $v['code']);
        return $this->ok('Double authentification désactivée.');
    }

    /** Déconnecte tous les autres appareils (sessions, jetons, notifications push). */
    public function logoutEverywhere(Request $r): Response
    {
        $u = $this->user();
        SecurityService::logoutEverywhere((int) $u['id']);
        \App\Core\Session::regenerate();
        \App\Core\Session::set('sv', (int) Database::value('SELECT session_version FROM users WHERE id = :id', ['id' => $u['id']]));
        return $this->ok('Tous les autres appareils ont été déconnectés.');
    }

    public function logout(Request $r): Response
    {
        $token = $r->bearerToken();
        if ($token) {
            Database::execute('UPDATE api_tokens SET revoked_at = NOW() WHERE token_hash = :h', ['h' => hash('sha256', $token)]);
        }
        \App\Services\RememberService::forgetCurrent($r);
        Auth::logout();
        return $this->ok('Vous êtes déconnecté.');
    }

    public function me(Request $r): Response
    {
        $u = AuthService::findUser((int) $this->user()['id']);
        return $this->json(['user' => AuthService::present($u), 'csrf' => Auth::via() === 'session' ? Csrf::token() : null]);
    }

    public function sendOtp(Request $r): Response
    {
        $u = $this->user();
        if (!$u['phone']) {
            throw HttpException::badRequest('Ajoutez d\'abord un numéro de téléphone à votre profil.');
        }
        $debug = AuthService::sendOtp($u['phone'], 'verify_phone', $r->ip());
        return $this->ok('Un code a été envoyé par SMS.', $debug ? ['debug_code' => $debug, 'debug_notice' => 'Mode développement : aucun SMS réel n\'est envoyé.'] : []);
    }

    public function verifyOtp(Request $r): Response
    {
        $u = $this->user();
        $v = $this->validate($r, ['code' => 'required|string|max:10']);
        if (!$u['phone'] || !AuthService::verifyOtp($u['phone'], 'verify_phone', $v['code'])) {
            throw \App\Core\ValidationException::field('code', 'Code invalide ou expiré.');
        }
        Database::execute('UPDATE users SET phone_verified_at = NOW() WHERE id = :id', ['id' => $u['id']]);
        return $this->ok('Numéro de téléphone vérifié.');
    }

    public function forgot(Request $r): Response
    {
        $v = $this->validate($r, ['identifier' => 'required|string|max:190']);
        $debug = AuthService::forgotPassword($v['identifier'], $r->ip());
        return $this->ok('Si un compte correspond, vous allez recevoir un lien (e-mail) ou un code (SMS).', $debug ? ['debug_code' => $debug, 'debug_notice' => 'Mode développement : aucun SMS réel n\'est envoyé.'] : []);
    }

    public function reset(Request $r): Response
    {
        AuthService::resetPassword($r->all());
        return $this->ok('Mot de passe modifié. Vous pouvez vous connecter.');
    }

    public function updateProfile(Request $r): Response
    {
        $u = $this->user();
        $v = $this->validate($r, ['full_name' => 'required|string|min:2|max:120', 'email' => 'nullable|email', 'phone' => 'nullable|phone']);
        if (!empty($v['email']) && Database::value('SELECT 1 FROM users WHERE email = :e AND id <> :id', ['e' => $v['email'], 'id' => $u['id']])) {
            throw \App\Core\ValidationException::field('email', 'Adresse déjà utilisée.');
        }
        if (!empty($v['phone']) && Database::value('SELECT 1 FROM users WHERE phone = :p AND id <> :id', ['p' => $v['phone'], 'id' => $u['id']])) {
            throw \App\Core\ValidationException::field('phone', 'Numéro déjà utilisé.');
        }
        if (empty($v['email']) && empty($v['phone'])) {
            throw \App\Core\ValidationException::field('phone', 'Un téléphone ou un e-mail est requis.');
        }
        $data = ['full_name' => $v['full_name'], 'email' => $v['email'] ?? null, 'phone' => $v['phone'] ?? null];
        if (($v['phone'] ?? null) !== $u['phone']) {
            $data['phone_verified_at'] = null;
        }
        Database::update('users', $data, 'id = :id', ['id' => $u['id']]);
        return $this->json(['user' => AuthService::present(AuthService::findUser((int) $u['id']))]);
    }

    public function changePassword(Request $r): Response
    {
        $u = $this->user();
        $v = $this->validate($r, ['current_password' => 'required|string|max:200', 'password' => 'required|password']);
        if (!password_verify($v['current_password'], $u['password_hash'])) {
            throw \App\Core\ValidationException::field('current_password', 'Mot de passe actuel incorrect.');
        }
        if (($weak = SecurityService::weakPassword($v['password'], [$u['full_name'], $u['email'] ?? '', $u['phone'] ?? ''])) !== null) {
            throw \App\Core\ValidationException::field('password', $weak);
        }
        Database::update('users', ['password_hash' => Auth::hashPassword($v['password']), 'password_changed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $u['id']]);
        // Les autres appareils sont déconnectés ; cette session reste ouverte
        SecurityService::logoutEverywhere((int) $u['id']);
        \App\Core\Session::regenerate();
        \App\Core\Session::set('sv', (int) Database::value('SELECT session_version FROM users WHERE id = :id', ['id' => $u['id']]));
        Audit::log('auth.password_changed', 'user', (int) $u['id']);
        return $this->ok('Mot de passe modifié.');
    }

    public function photo(Request $r): Response
    {
        $u = $this->user();
        $f = UploadService::store($r->file('file'), (int) $u['id'], 'avatar', true);
        Database::update('users', ['photo_file_id' => $f['id']], 'id = :id', ['id' => $u['id']]);
        return $this->json(['photo_url' => url('fichiers/' . $f['id'])]);
    }

    public function deleteAccount(Request $r): Response
    {
        $u = $this->user();
        if ((bool) $u['is_staff']) {
            throw HttpException::forbidden('Un compte du personnel est désactivé par un administrateur.');
        }
        AuthService::deleteAccount((int) $u['id'], (string) $r->input('password', ''));
        Auth::logout();
        return $this->ok('Votre compte a été supprimé. Les données financières sont conservées de manière anonymisée selon les obligations légales.');
    }

    public static function home(array $user): string
    {
        return url(match ($user['role']) {
            'driver', 'courier' => 'prestataire',
            'merchant' => 'commerce',
            'business' => 'entreprise',
            'admin', 'support', 'finance' => 'admin',
            default => 'client',
        });
    }
}
