<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\Auth\AuthService;
use App\Services\Auth\PermissionService;
use App\Services\Auth\RegistrationService;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    private function request(string $ip = '10.0.0.1'): Request
    {
        return new Request('POST', '/connexion', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'phpunit']);
    }

    /** @return array{id: int, phone: string} */
    private function registerWorker(string $password = 'motdepasse1', array $extra = []): array
    {
        $phone = self::uniquePhone();
        $id = $this->service(RegistrationService::class)->register(array_merge([
            'account_type' => 'WORKER', 'first_name' => 'Moussa', 'last_name' => 'Issa', 'phone' => $phone,
            'email' => null, 'city_id' => 1, 'birth_date' => '2002-01-15', 'password' => $password,
        ], $extra), $this->request());
        return ['id' => $id, 'phone' => $phone];
    }

    public function testRegistrationCreatesAccountRoleProfileAndConsents(): void
    {
        $u = $this->registerWorker();
        $db = $this->db();
        $this->assertSame('ACTIVE', $db->fetchValue('SELECT status FROM users WHERE id = ?', [$u['id']]));
        $this->assertTrue($this->service(PermissionService::class)->hasRole($u['id'], 'WORKER'));
        $this->assertFalse($this->service(PermissionService::class)->hasRole($u['id'], 'CLIENT'));
        $this->assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM user_profiles WHERE user_id = ?', [$u['id']]));
        $this->assertSame(3, (int) $db->fetchValue('SELECT COUNT(*) FROM user_consents WHERE user_id = ?', [$u['id']]));
        $this->assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$u['id']]));
        $hash = (string) $db->fetchValue('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        $this->assertStringStartsWith('$argon2id$', $hash, 'Mot de passe haché en Argon2id, jamais en clair');
    }

    public function testBusinessRegistrationCreatesOrganisationAndFreePlan(): void
    {
        $phone = self::uniquePhone();
        $id = $this->service(RegistrationService::class)->register([
            'account_type' => 'BUSINESS', 'first_name' => 'Hadiza', 'last_name' => 'Moussa', 'phone' => $phone,
            'email' => 'contact@resto-test.ne', 'city_id' => 1, 'birth_date' => null, 'password' => 'motdepasse1',
            'business_name' => 'Restaurant Le Fleuve', 'business_kind' => 'RESTAURANT',
        ], $this->request());
        $b = $this->db()->fetch('SELECT b.id, b.name, bm.member_role FROM businesses b JOIN business_members bm ON bm.business_id = b.id WHERE b.owner_user_id = ?', [$id]);
        $this->assertSame('Restaurant Le Fleuve', $b['name']);
        $this->assertSame('OWNER', $b['member_role']);
        $this->assertSame('ACTIF', $this->db()->fetchValue('SELECT status FROM business_subscriptions WHERE business_id = ?', [$b['id']]));
        $this->assertTrue($this->service(PermissionService::class)->hasRole($id, 'CLIENT'));
    }

    public function testDuplicatePhoneAndUnderageAreRejected(): void
    {
        $u = $this->registerWorker();
        try {
            $this->registerWorker('motdepasse1', ['phone' => $u['phone']]);
            $this->fail('Doublon accepté');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
        }
        try {
            $this->registerWorker('motdepasse1', ['birth_date' => date('Y-m-d', strtotime('-15 years'))]);
            $this->fail('Mineur de moins de 16 ans accepté');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('birth_date', $e->errors());
        }
    }

    public function testLoginSuccessByPhoneInAnyFormat(): void
    {
        $u = $this->registerWorker();
        $local = substr($u['phone'], 4, 2) . ' ' . substr($u['phone'], 6, 2) . ' ' . substr($u['phone'], 8, 2) . ' ' . substr($u['phone'], 10, 2);
        $result = $this->service(AuthService::class)->attempt($local, 'motdepasse1', $this->request());
        $this->assertTrue($result['ok']);
        $this->assertSame($u['id'], $this->service(AuthService::class)->id());
    }

    public function testWrongPasswordAndUnknownAccountGiveTheSameMessage(): void
    {
        $u = $this->registerWorker();
        $auth = $this->service(AuthService::class);
        $bad = $auth->attempt($u['phone'], 'mauvais123', $this->request('10.0.0.2'));
        $unknown = $auth->attempt('+22799999999', 'mauvais123', $this->request('10.0.0.2'));
        $this->assertFalse($bad['ok']);
        $this->assertSame($bad['error'], $unknown['error'], 'Pas d\'énumération des comptes');
    }

    public function testAccountLocksAfterFiveFailures(): void
    {
        $u = $this->registerWorker();
        $auth = $this->service(AuthService::class);
        for ($i = 0; $i < 5; $i++) {
            $auth->attempt($u['phone'], 'mauvais' . $i, $this->request('10.0.1.' . $i));
        }
        $this->assertNotNull($this->db()->fetchValue('SELECT locked_until FROM users WHERE id = ?', [$u['id']]));
        // Même le bon mot de passe est refusé pendant le verrouillage
        $result = $auth->attempt($u['phone'], 'motdepasse1', $this->request('10.0.2.1'));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('verrouillé', $result['error']);
    }

    public function testIpRateLimitBlocksBruteForce(): void
    {
        $auth = $this->service(AuthService::class);
        $last = null;
        for ($i = 0; $i < 21; $i++) {
            $last = $auth->attempt('+22798' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'x', $this->request('10.9.9.9'));
        }
        $this->assertStringContainsString('Trop de tentatives', (string) $last['error']);
    }

    public function testSuspendedAccountCannotLogIn(): void
    {
        $u = $this->registerWorker();
        $this->db()->query("UPDATE users SET status = 'SUSPENDED' WHERE id = ?", [$u['id']]);
        $result = $this->service(AuthService::class)->attempt($u['phone'], 'motdepasse1', $this->request());
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('suspendu', $result['error']);
    }

    public function testSessionIsInvalidatedWhenPasswordChangesElsewhere(): void
    {
        $u = $this->registerWorker();
        $auth = $this->service(AuthService::class);
        $this->assertTrue($auth->attempt($u['phone'], 'motdepasse1', $this->request())['ok']);
        // Changement du mot de passe depuis un autre appareil (ex : réinitialisation)
        usleep(5000);
        $this->db()->query('UPDATE users SET password_changed_at = NOW(3) WHERE id = ?', [$u['id']]);
        $fresh = new AuthService(
            $this->service(Session::class),
            $this->service(\App\Repositories\UserRepository::class),
            $this->service(\App\Services\Auth\PasswordHasher::class),
            $this->service(\App\Services\Auth\RateLimiter::class),
            $this->service(\App\Repositories\SettingRepository::class),
            $this->service(\App\Services\Audit\SecurityLogger::class),
            $this->service(PermissionService::class),
        );
        $this->assertNull($fresh->user(), 'La session doit être fermée après un changement de mot de passe');
    }

    public function testLoginEventsAreLogged(): void
    {
        $u = $this->registerWorker();
        $this->service(AuthService::class)->attempt($u['phone'], 'motdepasse1', $this->request('10.3.3.3'));
        $this->assertSame(1, (int) $this->db()->fetchValue(
            "SELECT COUNT(*) FROM security_events WHERE user_id = ? AND event_type = 'login.success' AND ip_address = '10.3.3.3'",
            [$u['id']]
        ));
    }
}
