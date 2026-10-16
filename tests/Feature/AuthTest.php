<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    public function testRegistrationValidatesAndHashesPassword(): void
    {
        $r = $this->call('POST', '/api/auth/register', ['full_name' => 'A', 'password' => 'abc', 'role' => 'client', 'accept_terms' => true], null, ['X-CSRF-Token' => \App\Core\Csrf::token()]);
        $this->assertStatus(422, $r);
        $this->assertTrue(isset(json_decode($r->body, true)['error']['fields']['password']), 'Erreur de champ explicite');
        // Inscription valide (via jeton : pas de session dans ce test)
        $email = 'nouveau' . random_int(1, 99999) . '@test.invalid';
        $r = $this->call('POST', '/api/auth/register', ['full_name' => 'Nouvel Utilisateur', 'email' => $email, 'phone' => '9' . random_int(1000000, 9999999), 'password' => 'Sahel#Kora77', 'role' => 'client', 'accept_terms' => true], null, ['X-CSRF-Token' => \App\Core\Csrf::token()]);
        $this->assertStatus(201, $r);
        $hash = Database::value('SELECT password_hash FROM users WHERE email = :e', ['e' => $email]);
        $this->assertTrue(str_starts_with((string) $hash, '$argon2id$') || str_starts_with((string) $hash, '$2y$'), 'Mot de passe haché (Argon2id ou bcrypt)');
        $this->assertFalse(str_contains((string) $hash, 'Sahel#Kora77'));
        // Doublon refusé
        $r = $this->call('POST', '/api/auth/register', ['full_name' => 'Doublon', 'email' => $email, 'password' => 'Sahel#Kora77', 'role' => 'client', 'accept_terms' => true], null, ['X-CSRF-Token' => \App\Core\Csrf::token()]);
        $this->assertStatus(422, $r);
    }

    public function testProviderRegistrationStartsPending(): void
    {
        $r = $this->call('POST', '/api/auth/register', ['full_name' => 'Chauffeur Nouveau', 'phone' => '9' . random_int(1000000, 9999999), 'password' => 'Sahel#Kora77', 'role' => 'driver', 'accept_terms' => true], null, ['X-CSRF-Token' => \App\Core\Csrf::token()]);
        $this->assertStatus(201, $r);
        $id = $this->data($r)['user']['id'];
        $this->assertSame('pending', Database::value('SELECT verification_status FROM provider_profiles WHERE user_id = :u', ['u' => $id]));
        $token = $this->token($id);
        $this->assertStatus(403, $this->call('POST', '/api/provider/online', ['online' => true], $token), 'Un prestataire non validé ne peut pas passer en ligne');
    }

    public function testTokenLoginAndGenericErrors(): void
    {
        $u = $this->user('client');
        $ok = $this->call('POST', '/api/auth/token', ['identifier' => $u['email'], 'password' => 'Secret123']);
        $this->assertStatus(200, $ok);
        $token = $this->data($ok)['token'];
        $me = $this->call('GET', '/api/auth/me', [], $token);
        $this->assertStatus(200, $me);
        $this->assertSame($u['email'], $this->data($me)['user']['email']);
        $bad = $this->call('POST', '/api/auth/token', ['identifier' => $u['email'], 'password' => 'mauvais']);
        $unknown = $this->call('POST', '/api/auth/token', ['identifier' => 'inconnu@test.invalid', 'password' => 'mauvais']);
        $this->assertStatus(401, $bad);
        $this->assertStatus(401, $unknown);
        $this->assertSame(json_decode($bad->body, true)['error']['message'], json_decode($unknown->body, true)['error']['message'], 'Pas d\'énumération des comptes');
    }

    public function testAccountLocksAfterRepeatedFailures(): void
    {
        $u = $this->user('client');
        for ($i = 0; $i < 5; $i++) {
            $this->call('POST', '/api/auth/token', ['identifier' => $u['email'], 'password' => 'faux' . $i]);
        }
        $r = $this->call('POST', '/api/auth/token', ['identifier' => $u['email'], 'password' => 'Secret123']);
        $this->assertStatus(423, $r, 'Compte verrouillé même avec le bon mot de passe');
    }

    public function testSessionRequestsWithoutCsrfAreRejected(): void
    {
        $r = $this->call('POST', '/api/auth/login', ['identifier' => 'x@test.invalid', 'password' => 'y']);
        $this->assertStatus(419, $r);
    }

    public function testSuspendedUserCannotUseToken(): void
    {
        $u = $this->user('client');
        $t = $this->token((int) $u['id']);
        Database::execute("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => $u['id']]);
        $this->assertStatus(401, $this->call('GET', '/api/auth/me', [], $t));
    }

    public function testPasswordResetWithOtp(): void
    {
        $u = $this->user('client');
        $r = $this->call('POST', '/api/auth/password/forgot', ['identifier' => $u['phone']], null, ['X-CSRF-Token' => \App\Core\Csrf::token()]);
        $this->assertStatus(200, $r);
        $code = json_decode($r->body, true)['debug_code'] ?? null;
        $this->assertTrue(is_string($code) && strlen($code) === 6, 'Code renvoyé uniquement en mode développement sans SMS réel');
        $bad = $this->call('POST', '/api/auth/password/reset', ['phone' => $u['phone'], 'code' => '000000', 'password' => 'Nouveau123'], null, ['X-CSRF-Token' => \App\Core\Csrf::token()]);
        $this->assertStatus(422, $bad);
        $ok = $this->call('POST', '/api/auth/password/reset', ['phone' => $u['phone'], 'code' => $code, 'password' => 'Nouveau123'], null, ['X-CSRF-Token' => \App\Core\Csrf::token()]);
        $this->assertStatus(200, $ok);
        $this->assertStatus(200, $this->call('POST', '/api/auth/token', ['identifier' => $u['phone'], 'password' => 'Nouveau123']));
    }
}
