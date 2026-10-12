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
        $v = $this->validate($r, ['identifier' => 'required|string|max:190', 'password' => 'required|string|max:200']);
        $user = AuthService::attempt($v['identifier'], $v['password'], $r);
        Auth::login($user, $r);
        return $this->json(['user' => AuthService::present($user), 'csrf' => Csrf::token(), 'redirect' => self::home($user)]);
    }

    /** Jeton Bearer pour les futures applications mobiles (pas de cookie, pas de CSRF). */
    public function token(Request $r): Response
    {
        $v = $this->validate($r, ['identifier' => 'required|string|max:190', 'password' => 'required|string|max:200', 'device_name' => 'nullable|string|max:80']);
        $user = AuthService::attempt($v['identifier'], $v['password'], $r);
        $token = Auth::issueToken((int) $user['id'], $v['device_name'] ?? 'mobile');
        Auth::actingAs((int) $user['id']);
        return $this->json(['token' => $token, 'token_type' => 'Bearer', 'expires_in_days' => 30, 'user' => AuthService::present($user)]);
    }

    public function logout(Request $r): Response
    {
        $token = $r->bearerToken();
        if ($token) {
            Database::execute('UPDATE api_tokens SET revoked_at = NOW() WHERE token_hash = :h', ['h' => hash('sha256', $token)]);
        }
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
        Database::update('users', ['password_hash' => Auth::hashPassword($v['password'])], 'id = :id', ['id' => $u['id']]);
        \App\Core\Session::regenerate();
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
