<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Csrf;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Settings;
use App\Support\Totp;
use Tests\TestCase;

/** Double authentification, mots de passe faibles, déconnexion globale, garde de l'administration. */
final class SecurityTest extends TestCase
{
    private function csrf(): array
    {
        return ['X-CSRF-Token' => Csrf::token()];
    }

    public function testWeakPasswordsRefused(): void
    {
        foreach (['azerty123', 'Niamey2026', 'Marwa2026!', '12345678a'] as $pwd) {
            $r = $this->call('POST', '/api/auth/register', ['full_name' => 'Test Mdp', 'email' => 'mdp' . random_int(1, 999999) . '@test.invalid', 'password' => $pwd, 'role' => 'client', 'accept_terms' => true], null, $this->csrf());
            $this->assertStatus(422, $r, $pwd);
        }
        $r = $this->call('POST', '/api/auth/register', ['full_name' => 'Halima Souley', 'email' => 'h' . random_int(1, 999999) . '@test.invalid', 'password' => 'Halima2026x', 'role' => 'client', 'accept_terms' => true], null, $this->csrf());
        $this->assertStatus(422, $r, 'Mot de passe contenant le nom');
    }

    public function testTotpEnrollmentLoginReplayAndRecovery(): void
    {
        $u = $this->user('admin');
        $t = $this->token((int) $u['id']);
        $b = $this->data($this->call('POST', '/api/account/2fa/begin', [], $t));
        $this->assertTrue(str_starts_with($b['uri'], 'otpauth://totp/'));
        $secret = str_replace(' ', '', $b['secret']);
        $this->assertTrue(Crypto::decrypt((string) Database::value('SELECT totp_secret_enc FROM users WHERE id = :id', ['id' => $u['id']])) === $secret, 'Secret chiffré en base');
        $this->assertStatus(422, $this->call('POST', '/api/account/2fa/confirm', ['code' => '000000'], $t));
        $now = time();
        $codes = $this->data($this->call('POST', '/api/account/2fa/confirm', ['code' => Totp::code($secret, intdiv($now, 30))], $t))['recovery_codes'];
        $this->assertSame(8, count($codes));

        // Connexion : le mot de passe seul ne suffit plus
        $login = $this->call('POST', '/api/auth/login', ['identifier' => $u['email'], 'password' => 'Secret123'], null, $this->csrf());
        $this->assertStatus(200, $login);
        $this->assertTrue($this->data($login)['two_factor'] === true);
        $this->assertStatus(401, $this->call('GET', '/api/auth/me'), 'Pas de session avant le second facteur');
        $this->assertStatus(422, $this->call('POST', '/api/auth/2fa', ['code' => '123456'], null, $this->csrf()));
        // Le code déjà utilisé pour l'activation est refusé (anti-rejeu) ; le suivant est accepté
        $this->assertStatus(422, $this->call('POST', '/api/auth/2fa', ['code' => Totp::code($secret, intdiv($now, 30))], null, $this->csrf()), 'Rejeu refusé');
        $ok = $this->call('POST', '/api/auth/2fa', ['code' => Totp::code($secret, intdiv($now, 30) + 1)], null, $this->csrf());
        $this->assertStatus(200, $ok);

        // Jeton mobile : code exigé ; code de secours utilisable une seule fois
        $this->assertStatus(401, $this->call('POST', '/api/auth/token', ['identifier' => $u['email'], 'password' => 'Secret123']));
        $this->assertStatus(200, $this->call('POST', '/api/auth/token', ['identifier' => $u['email'], 'password' => 'Secret123', 'code' => $codes[0]]));
        $this->assertStatus(422, $this->call('POST', '/api/auth/token', ['identifier' => $u['email'], 'password' => 'Secret123', 'code' => $codes[0]]), 'Code de secours à usage unique');
    }

    public function testLogoutEverywhereRevokesTokens(): void
    {
        $u = $this->user('client');
        $t1 = $this->token((int) $u['id']);
        $t2 = $this->token((int) $u['id']);
        $this->assertStatus(200, $this->call('POST', '/api/account/logout-everywhere', [], $t1));
        $this->assertStatus(401, $this->call('GET', '/api/auth/me', [], $t2));
        $this->assertSame(1, (int) Database::value('SELECT session_version FROM users WHERE id = :id', ['id' => $u['id']]));
    }

    public function testStaffGuard(): void
    {
        $admin = $this->user('admin');
        $t = $this->token((int) $admin['id']);
        $this->assertStatus(200, $this->call('GET', '/api/admin/dashboard', [], $t));
        Settings::set('security.staff_2fa_required', true);
        try {
            $r = $this->call('GET', '/api/admin/dashboard', [], $t);
            $this->assertStatus(403, $r);
            $this->assertSame('two_factor_setup_required', json_decode($r->body, true)['error']['code']);
            $this->assertStatus(200, $this->call('GET', '/api/account/security', [], $t), 'Le profil reste accessible pour activer la double authentification');
        } finally {
            Settings::set('security.staff_2fa_required', false);
        }
        Env::set('ADMIN_ALLOWED_IPS', '10.0.0.0/8, 192.168.1.20');
        try {
            $this->assertStatus(403, $this->call('GET', '/api/admin/dashboard', [], $t), 'IP non autorisée');
            Env::set('ADMIN_ALLOWED_IPS', '127.0.0.0/8');
            $this->assertStatus(200, $this->call('GET', '/api/admin/dashboard', [], $t), 'IP autorisée (CIDR)');
        } finally {
            Env::set('ADMIN_ALLOWED_IPS', '');
        }
    }
}
