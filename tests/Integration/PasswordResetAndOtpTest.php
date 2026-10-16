<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Request;
use App\Services\Auth\AuthService;
use App\Services\Auth\OtpService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\RegistrationService;
use Tests\TestCase;

final class PasswordResetAndOtpTest extends TestCase
{
    private string $smsLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smsLog = dirname(__DIR__, 2) . '/storage/logs/sms.log';
        @unlink($this->smsLog);
    }

    private function request(): Request
    {
        return new Request('POST', '/', server: ['REMOTE_ADDR' => '10.5.5.5']);
    }

    private function register(): array
    {
        $phone = self::uniquePhone();
        $id = $this->service(RegistrationService::class)->register([
            'account_type' => 'CLIENT', 'first_name' => 'Aïcha', 'last_name' => 'Garba', 'phone' => $phone,
            'email' => null, 'city_id' => 2, 'birth_date' => null, 'password' => 'motdepasse1',
        ], $this->request());
        return ['id' => $id, 'phone' => $phone];
    }

    private function lastSmsMatch(string $pattern): ?string
    {
        $content = (string) @file_get_contents($this->smsLog);
        return preg_match_all($pattern, $content, $m) ? end($m[1]) : null;
    }

    public function testResetTokenIsHashedSingleUseAndChangesPassword(): void
    {
        $u = $this->register();
        $resets = $this->service(PasswordResetService::class);
        $resets->request($u['phone'], $this->request());
        $token = $this->lastSmsMatch('#/mot-de-passe/reinitialiser/([A-Za-z0-9_-]+)#');
        $this->assertNotNull($token);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [$token]), 'Le jeton brut ne doit pas être stocké');
        $this->assertNotNull($resets->findValid($token));
        $this->assertTrue($resets->reset($token, 'nouveauPass9', $this->request()));
        $this->assertFalse($resets->reset($token, 'encoreAutre9', $this->request()), 'Jeton à usage unique');
        $this->assertTrue($this->service(AuthService::class)->attempt($u['phone'], 'nouveauPass9', $this->request())['ok']);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $u = $this->register();
        $resets = $this->service(PasswordResetService::class);
        $resets->request($u['phone'], $this->request());
        $token = $this->lastSmsMatch('#/mot-de-passe/reinitialiser/([A-Za-z0-9_-]+)#');
        $this->db()->query('UPDATE password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE user_id = ?', [$u['id']]);
        $this->assertNull($resets->findValid((string) $token));
        $this->assertFalse($resets->reset((string) $token, 'nouveauPass9', $this->request()));
    }

    public function testUnknownAccountProducesNoMessage(): void
    {
        $this->service(PasswordResetService::class)->request('+22799000111', $this->request());
        $this->assertNull($this->lastSmsMatch('#(reinitialiser)#'));
    }

    public function testOtpVerificationRaisesVerificationLevel(): void
    {
        $u = $this->register();
        $otp = $this->service(OtpService::class);
        $this->assertTrue($otp->sendPhoneVerification($u['id'], $u['phone'], $this->request())['ok']);
        $code = (string) $this->lastSmsMatch('#code de vérification est (\d{6})#');
        $this->assertFalse($otp->verifyPhone($u['id'], $code === '000000' ? '111111' : '000000', $this->request())['ok']);
        $this->assertTrue($otp->verifyPhone($u['id'], $code, $this->request())['ok']);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT verification_level FROM user_profiles WHERE user_id = ?', [$u['id']]));
        $this->assertFalse($otp->verifyPhone($u['id'], $code, $this->request())['ok'], 'Code à usage unique');
    }

    public function testOtpAttemptsAreLimited(): void
    {
        $u = $this->register();
        $otp = $this->service(OtpService::class);
        $otp->sendPhoneVerification($u['id'], $u['phone'], $this->request());
        $code = (string) $this->lastSmsMatch('#code de vérification est (\d{6})#');
        $wrong = $code === '123456' ? '654321' : '123456';
        for ($i = 0; $i < 5; $i++) {
            $otp->verifyPhone($u['id'], $wrong, $this->request());
        }
        $this->assertFalse($otp->verifyPhone($u['id'], $code, $this->request())['ok'], 'Bloqué après 5 essais même avec le bon code');
    }
}
