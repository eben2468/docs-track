<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\HttpException;
use Core\Middleware;
use Core\Request;
use Core\Response;
use Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        TraceMiddleware::$calls = [];
    }

    public function testMatchesStaticRoute(): void
    {
        $router = new Router();
        $router->get('/', static fn (): string => 'home');

        $this->assertSame('home', $router->dispatch(new Request('GET', '/'))->body());
    }

    public function testPassesRouteParamsInOrderAndOnRequest(): void
    {
        $router = new Router();
        $router->get('/documents/{id}/attachments/{file}', static fn (Request $r, string $id, string $file): string =>
            "$id|$file|" . $r->param('id'));

        $response = $router->dispatch(new Request('GET', '/documents/42/attachments/7'));

        $this->assertSame('42|7|42', $response->body());
    }

    public function testRegexConstraintRejectsNonMatchingParam(): void
    {
        $router = new Router();
        $router->get('/documents/{id:\d+}', static fn (): string => 'doc');

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $router->dispatch(new Request('GET', '/documents/abc'));
    }

    public function testUnknownPathIs404(): void
    {
        $router = new Router();
        $router->get('/', static fn (): string => 'home');

        try {
            $router->dispatch(new Request('GET', '/missing'));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status());
        }
    }

    public function testWrongMethodIs405(): void
    {
        $router = new Router();
        $router->get('/login', static fn (): string => 'form');

        try {
            $router->dispatch(new Request('DELETE', '/login'));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status());
        }
    }

    public function testHeadIsServedByGetRoute(): void
    {
        $router = new Router();
        $router->get('/track', static fn (): string => 'track');

        $this->assertSame(200, $router->dispatch(new Request('HEAD', '/track'))->status());
    }

    public function testGroupPrefixAndMiddlewareOrderWithArgsAndDedup(): void
    {
        $router = new Router();
        $router->aliasMiddleware('trace', TraceMiddleware::class);
        $router->middlewareGroup('web', ['trace:a']);
        $router->middlewareGroup('admin', ['web', 'trace:b,c']);

        $router->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
            $r->get('/users', static fn (): string => 'users', ['trace:a', 'trace:d']);
        });

        $response = $router->dispatch(new Request('GET', '/admin/users'));

        $this->assertSame('users', $response->body());
        $this->assertSame([['a'], ['b', 'c'], ['d']], TraceMiddleware::$calls);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $router = new Router();
        $router->aliasMiddleware('block', BlockMiddleware::class);
        $router->get('/secret', static fn (): string => 'secret', ['block']);

        $response = $router->dispatch(new Request('GET', '/secret'));

        $this->assertSame(403, $response->status());
        $this->assertSame('blocked', $response->body());
    }

    public function testNormalisePathStripsBasePathQueryAndTrailingSlash(): void
    {
        $this->assertSame('/', Request::normalisePath('/docs-track/', '/docs-track'));
        $this->assertSame('/documents/5', Request::normalisePath('/docs-track/documents/5/?tab=all', '/docs-track'));
        $this->assertSame('/docs-trackers', Request::normalisePath('/docs-trackers', '/docs-track'));
        $this->assertSame('/login', Request::normalisePath('/index.php/login'));
    }
}

final class TraceMiddleware implements Middleware
{
    /** @var list<list<string>> */
    public static array $calls = [];

    public function handle(Request $request, callable $next, array $args = []): Response
    {
        self::$calls[] = $args;

        return $next($request);
    }
}

final class BlockMiddleware implements Middleware
{
    public function handle(Request $request, callable $next, array $args = []): Response
    {
        return new Response('blocked', 403);
    }
}
