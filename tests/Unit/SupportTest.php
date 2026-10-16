<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use App\Support\Logger;
use App\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testUuidV4(): void
    {
        $a = Uuid::v4();
        $this->assertTrue(Uuid::isValid($a));
        $this->assertNotSame($a, Uuid::v4());
        $this->assertFalse(Uuid::isValid('42'));
    }

    public function testLoggerRedactsSecrets(): void
    {
        $out = Logger::redact(['login' => 'x', 'password' => 'secret', 'nested' => ['token' => 'abc', 'code' => '123456']]);
        $this->assertSame('[masqué]', $out['password']);
        $this->assertSame('[masqué]', $out['nested']['token']);
        $this->assertSame('[masqué]', $out['nested']['code']);
        $this->assertSame('x', $out['login']);
    }

    public function testRedirectRefusesExternalTargets(): void
    {
        require_once dirname(__DIR__, 2) . '/app/Core/helpers.php';
        \App\Core\Container::setInstance($c = new \App\Core\Container());
        $config = new \App\Core\Config(dirname(__DIR__, 2) . '/config');
        $c->instance(\App\Core\Config::class, $config);
        foreach (['https://evil.example', '//evil.example', "/ok\nLocation: x", 'javascript:alert(1)'] as $target) {
            $this->assertSame('/', Response::redirect($target)->header('Location'), $target);
        }
        $this->assertSame('/espace/prestataire', Response::redirect('/espace/prestataire')->header('Location'));
    }

    public function testEscapingHelper(): void
    {
        $this->assertSame('&lt;script&gt;alert(&apos;x&apos;)&lt;/script&gt;', e("<script>alert('x')</script>"));
        $this->assertSame('', e(null));
        $this->assertSame('', e(['array']));
    }
}
