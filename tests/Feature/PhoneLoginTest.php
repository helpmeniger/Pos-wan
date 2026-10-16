<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth;
use App\Core\Cookie;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\Notification\HttpSmsGateway;
use App\Services\RememberService;
use Tests\TestCase;

/** Connexion des clients par code SMS (sans mot de passe) et « se souvenir de moi ». */
final class PhoneLoginTest extends TestCase
{
    private function csrf(): array
    {
        return ['X-CSRF-Token' => Csrf::token()];
    }

    private function newBrowser(): void
    {
        Session::reset();
        Auth::actingAs(null);
        Cookie::$sent = [];
    }

    public function testNewClientSignsUpWithPhoneOnlyAndIsRemembered(): void
    {
        $this->newBrowser();
        $phone = '9' . random_int(1000000, 9999999);
        $send = $this->call('POST', '/api/auth/phone/send', ['phone' => $phone], null, $this->csrf());
        $this->assertStatus(200, $send);
        $code = $this->data($send)['debug_code'];
        $this->assertSame(6, strlen((string) $code), 'Code visible uniquement en démonstration (aucun SMS réel)');
        $this->assertStatus(422, $this->call('POST', '/api/auth/phone/verify', ['phone' => $phone, 'code' => '000000'], null, $this->csrf()), 'Mauvais code');
        $v = $this->data($this->call('POST', '/api/auth/phone/verify', ['phone' => $phone, 'code' => $code, 'remember' => true], null, $this->csrf()));
        $this->assertTrue($v['needs_profile'] === true);
        $this->assertStatus(422, $this->call('POST', '/api/auth/phone/register', ['phone' => $phone, 'signup_token' => $v['signup_token'], 'full_name' => 'Hadiza Test'], null, $this->csrf()), 'Conditions obligatoires');
        $this->assertStatus(422, $this->call('POST', '/api/auth/phone/register', ['phone' => '96000000', 'signup_token' => $v['signup_token'], 'full_name' => 'Pirate', 'accept_terms' => true], null, $this->csrf()), 'Jeton lié au numéro vérifié');
        $reg = $this->call('POST', '/api/auth/phone/register', ['phone' => $phone, 'signup_token' => $v['signup_token'], 'full_name' => 'Hadiza Test', 'accept_terms' => true, 'remember' => true], null, $this->csrf());
        $this->assertStatus(200, $reg);
        $u = Database::first('SELECT u.*, r.code role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.phone = :p', ['p' => '+227' . $phone]);
        $this->assertSame('client', $u['role']);
        $this->assertTrue($u['phone_verified_at'] !== null, 'Numéro vérifié');
        $cookie = Cookie::$sent[RememberService::COOKIE] ?? null;
        $this->assertTrue(is_string($cookie) && str_contains($cookie, ':'), 'Cookie « se souvenir de moi » émis');

        // Nouveau navigateur (session fermée) : reconnexion automatique grâce au cookie, puis rotation
        $this->newBrowser();
        $req = new Request('GET', '/api/auth/me', [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'tests'], [RememberService::COOKIE => $cookie]);
        $res = $this->kernel()->handle($req);
        $this->assertStatus(200, $res, 'Connecté par le cookie');
        $rotated = Cookie::$sent[RememberService::COOKIE] ?? null;
        $this->assertTrue($rotated !== null && $rotated !== $cookie, 'Jeton renouvelé');

        // Réutilisation de l'ancien jeton (vol) : tous les jetons du compte sont révoqués
        $this->newBrowser();
        $req = new Request('GET', '/api/auth/me', [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'tests'], [RememberService::COOKIE => $cookie]);
        $this->assertStatus(401, $this->kernel()->handle($req));
        $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM remember_tokens WHERE user_id = :u', ['u' => $u['id']]));
        $this->newBrowser();
    }

    public function testExistingClientLogsInAndProfessionalsNeedPassword(): void
    {
        $this->newBrowser();
        $client = $this->user('client');
        $local = substr((string) $client['phone'], 4);
        $code = $this->data($this->call('POST', '/api/auth/phone/send', ['phone' => $local], null, $this->csrf()))['debug_code'];
        $r = $this->call('POST', '/api/auth/phone/verify', ['phone' => $local, 'code' => $code], null, $this->csrf());
        $this->assertStatus(200, $r);
        $this->assertTrue(str_ends_with($this->data($r)['redirect'], '/client'));
        $this->assertStatus(422, $this->call('POST', '/api/auth/phone/verify', ['phone' => $local, 'code' => $code], null, $this->csrf()), 'Code à usage unique');

        $this->newBrowser();
        $driver = $this->user('driver');
        $d = $this->data($this->call('POST', '/api/auth/phone/send', ['phone' => $driver['phone']], null, $this->csrf()));
        $this->assertTrue($d['password_required'] === true, 'Les chauffeurs gardent leur mot de passe');
        $this->assertFalse(isset($d['debug_code']), 'Aucun code envoyé aux comptes professionnels');
        $this->newBrowser();
    }

    public function testHttpSmsGatewayBuildsProviderRequest(): void
    {
        \App\Core\Config::set('services.sms.http', ['url' => 'https://sms.example.invalid/send', 'format' => 'json', 'body' => '{"to":"{to}","text":"{message}","from":"{sender}"}',
            'headers' => 'Authorization: Bearer abc', 'phone_format' => 'digits']);
        $seen = null;
        HttpSmsGateway::$transport = function ($method, $url, $headers, $body) use (&$seen) { $seen = compact('method', 'url', 'headers', 'body'); return 202; };
        try {
            (new HttpSmsGateway())->send('+22790000001', 'Code "123456" é');
            $this->assertSame('POST', $seen['method']);
            $this->assertSame(['to' => '22790000001', 'text' => 'Code "123456" é', 'from' => 'MARWA'], json_decode($seen['body'], true), 'JSON correctement échappé');
            $this->assertTrue(in_array('Authorization: Bearer abc', $seen['headers'], true));
            HttpSmsGateway::$transport = fn () => 500;
            $this->assertThrows(fn () => (new HttpSmsGateway())->send('+22790000001', 'x'));
        } finally {
            HttpSmsGateway::$transport = null;
        }
    }
}
