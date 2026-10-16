<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\MiddlewareInterface;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router(new Container());
        $r->get('/missions/{uuid:[0-9a-f-]{36}}', fn (Request $q) => Response::html('mission ' . $q->param('uuid')), 'missions.show');
        $r->group('/admin', ['tag:A'], function (Router $r) {
            $r->get('/', fn () => Response::html('admin'));
        });
        $r->post('/form', fn () => Response::html('posted'));
        $r->aliasMiddleware(['tag' => TagMiddleware::class]);
        return $r;
    }

    public function testParametersAndConstraints(): void
    {
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        $res = $this->router()->dispatch(new Request('GET', "/missions/{$uuid}"));
        $this->assertSame("mission {$uuid}", $res->content());
    }

    public function testConstraintMismatchIs404(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $this->router()->dispatch(new Request('GET', '/missions/42'));
    }

    public function testWrongMethodIs405(): void
    {
        try {
            $this->router()->dispatch(new Request('GET', '/form'));
            $this->fail('405 attendu');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status());
            $this->assertSame('POST', $e->headers()['Allow']);
        }
    }

    public function testGroupMiddlewareWithArguments(): void
    {
        $res = $this->router()->dispatch(new Request('GET', '/admin'));
        $this->assertSame('admin', $res->content());
        $this->assertSame('A', $res->header('X-Tag'));
    }

    public function testNamedRouteGeneration(): void
    {
        $this->assertSame('/missions/abc?page=2', $this->router()->url('missions.show', ['uuid' => 'abc', 'page' => 2]));
    }
}

final class TagMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, string ...$args): Response
    {
        return $next($request)->withHeader('X-Tag', implode(',', $args));
    }
}
