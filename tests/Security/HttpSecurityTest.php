<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\HttpTestCase;

/** Tests de sécurité demandés au cahier des charges (section 29). */
final class HttpSecurityTest extends HttpTestCase
{
    public function testSecurityHeaders(): void
    {
        $h = $this->client()->get('/')['headers'];
        $this->assertStringContainsString("default-src 'self'", $h['content-security-policy'] ?? '');
        $this->assertStringContainsString("frame-ancestors 'none'", $h['content-security-policy'] ?? '');
        $this->assertSame('nosniff', $h['x-content-type-options'] ?? '');
        $this->assertSame('DENY', $h['x-frame-options'] ?? '');
        $this->assertArrayNotHasKey('x-powered-by', $h, 'La version de PHP ne doit pas être divulguée');
    }

    public function testSessionCookieIsHttpOnlyAndSameSite(): void
    {
        $c = $this->client();
        $ch = curl_init(self::$base . '/connexion');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
        $this->assertMatchesRegularExpression('/Set-Cookie: JNSESSID=[^;]+;.*HttpOnly/i', $raw);
        $this->assertMatchesRegularExpression('/Set-Cookie: JNSESSID=[^;]+;.*SameSite=Lax/i', $raw);
    }

    public function testForgedRequestWithoutCsrfTokenIsRejected(): void
    {
        $c = $this->client();
        $c->get('/connexion');
        $res = $c->request('POST', '/connexion', ['login' => '+22790000001', 'password' => 'x']);
        $this->assertSame(419, $res['status']);
        $res = $c->request('POST', '/connexion', ['login' => '+22790000001', 'password' => 'x', '_token' => str_repeat('a', 64)]);
        $this->assertSame(419, $res['status']);
    }

    public function testSessionIdIsRegeneratedOnLogin(): void
    {
        [$c, $phone] = $this->registeredClient('WORKER');
        $c->get('/espace/prestataire');
        $c->request('POST', '/deconnexion', ['_token' => $c->csrf()]);
        $c->get('/connexion');
        $before = $c->cookies['JNSESSID'] ?? '';
        $c->request('POST', '/connexion', ['_token' => $c->csrf(), 'login' => $phone, 'password' => 'motdepasse1']);
        $after = $c->cookies['JNSESSID'] ?? '';
        $this->assertNotSame('', $before);
        $this->assertNotSame($before, $after, 'Protection contre la fixation de session');
    }

    public function testAccessToOtherSpacesAndAdminIsDenied(): void
    {
        $anon = $this->client();
        $res = $anon->get('/admin');
        $this->assertSame(302, $res['status']);
        $this->assertStringEndsWith('/connexion', $res['headers']['location']);

        [$worker] = $this->registeredClient('WORKER');
        $this->assertSame(403, $worker->get('/admin')['status']);
        $this->assertSame(403, $worker->get('/espace/demandeur')['status']);
        $this->assertGreaterThan(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM security_events WHERE event_type = 'access.denied'"));
    }

    public function testCannotActOnAnotherUsersNotification(): void
    {
        [$a, $phoneA] = $this->registeredClient('WORKER');
        [$b] = $this->registeredClient('WORKER');
        $notifA = (int) $this->db()->fetchValue('SELECT n.id FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.phone = ? LIMIT 1', [$phoneA]);
        $b->get('/notifications');
        $b->request('POST', "/notifications/{$notifA}/lue", ['_token' => $b->csrf()]);
        $this->assertNull($this->db()->fetchValue('SELECT read_at FROM notifications WHERE id = ?', [$notifA]), 'IDOR : B a modifié la notification de A');
    }

    public function testXssPayloadIsEscaped(): void
    {
        [$c] = $this->registeredClient('WORKER', ['first_name' => '<script>alert(1)</script>']);
        $body = $c->get('/espace/prestataire')['body'];
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testSqlInjectionAttemptsAreHarmless(): void
    {
        $c = $this->client();
        foreach (["' OR '1'='1", "admin@x.ne' -- ", "1; DROP TABLE users; --"] as $payload) {
            $res = $c->follow($c->submit('/connexion', '/connexion', ['login' => $payload, 'password' => "' OR 1=1 #"]));
            $this->assertSame(200, $res['status']);
            $this->assertStringContainsString('Identifiants incorrects', $res['body']);
        }
        $this->assertGreaterThan(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM users'), 'La table users existe toujours');
        $this->assertSame(404, $c->get('/categories/' . rawurlencode("x' OR 1=1"))['status']);
    }

    public function testValidationRedirectNeverLeavesTheSite(): void
    {
        $c = $this->client();
        $res = $c->submit('/connexion', '/connexion', ['login' => '', 'password' => ''], ['Referer' => 'https://site-malveillant.example/piege']);
        $this->assertSame(303, $res['status']);
        $this->assertStringNotContainsString('malveillant', $res['headers']['location']);
    }

    public function testPrivateFilesAreNotServed(): void
    {
        $c = $this->client();
        foreach (['/.env', '/../.env', '/storage/logs/', '/config/database.php', '/vendor/autoload.php', '/composer.json'] as $path) {
            $res = $c->get($path);
            $this->assertNotSame(200, $res['status'], $path);
            $this->assertStringNotContainsString('DB_PASSWORD', $res['body'], $path);
        }
    }

    public function testErrorPagesDoNotLeakInternals(): void
    {
        $res = $this->client()->get('/espace/prestataire/../../.env');
        $this->assertStringNotContainsString('Stack trace', $res['body']);
        $this->assertStringNotContainsString('/home/', $res['body']);
    }
}
