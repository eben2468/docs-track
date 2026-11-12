<?php

declare(strict_types=1);

namespace Core;

use Closure;
use InvalidArgumentException;

/**
 * GET/POST router with {param} placeholders, prefix groups and named middleware.
 *
 * Placeholders: "{id}" matches one path segment; "{id:\d+}" adds a regex constraint.
 * Middleware is referenced by alias ("csrf", "role:admin,hod") or by group name ("web").
 * Handlers are [ControllerClass::class, 'method'] or a Closure; they receive the
 * Request followed by the route parameters in order, and return a Response or string.
 */
final class Router
{
    /** @var list<array{method: string, path: string, regex: string, params: list<string>, handler: array{0: class-string, 1: string}|Closure, middleware: list<string>}> */
    private array $routes = [];

    /** @var array<string, class-string<Middleware>> */
    private array $aliases = [];

    /** @var array<string, list<string>> */
    private array $groups = [];

    /** @var list<array{prefix: string, middleware: list<string>}> */
    private array $groupStack = [];

    /**
     * Register a middleware class under a short name.
     *
     * @param class-string<Middleware> $class
     */
    public function aliasMiddleware(string $name, string $class): self
    {
        $this->aliases[$name] = $class;

        return $this;
    }

    /**
     * Register a named list of middleware (which may include other groups).
     *
     * @param list<string> $middleware
     */
    public function middlewareGroup(string $name, array $middleware): self
    {
        $this->groups[$name] = $middleware;

        return $this;
    }

    /**
     * Register a GET route.
     *
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param list<string> $middleware
     */
    public function get(string $path, array|Closure $handler, array $middleware = []): self
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    /**
     * Register a POST route.
     *
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param list<string> $middleware
     */
    public function post(string $path, array|Closure $handler, array $middleware = []): self
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * Group routes under a shared path prefix and/or middleware.
     *
     * @param array{prefix?: string, middleware?: list<string>} $attributes
     * @param callable(self): void $routes
     */
    public function group(array $attributes, callable $routes): void
    {
        $this->groupStack[] = [
            'prefix' => trim($attributes['prefix'] ?? '', '/'),
            'middleware' => $attributes['middleware'] ?? [],
        ];

        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * Find the route for the request, run its middleware chain and handler.
     *
     * @throws HttpException 404 when no route matches, 405 when only the method differs
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name];
            }
            $request->setRouteParams($params);

            return $this->runPipeline($request, $route, array_values($params));
        }

        throw new HttpException($pathMatched ? 405 : 404);
    }

    /**
     * All registered routes (method, path, middleware), for debugging and tests.
     *
     * @return list<array{method: string, path: string, middleware: list<string>}>
     */
    public function routes(): array
    {
        return array_map(
            static fn (array $r): array => ['method' => $r['method'], 'path' => $r['path'], 'middleware' => $r['middleware']],
            $this->routes
        );
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param list<string> $middleware
     */
    private function add(string $method, string $path, array|Closure $handler, array $middleware): self
    {
        $prefixes = array_filter(array_column($this->groupStack, 'prefix'), static fn (string $p): bool => $p !== '');
        $segments = [...$prefixes, trim($path, '/')];
        $fullPath = '/' . trim(implode('/', array_filter($segments, static fn (string $s): bool => $s !== '')), '/');

        $groupMiddleware = array_merge([], ...array_column($this->groupStack, 'middleware'));
        [$regex, $params] = $this->compile($fullPath);

        $this->routes[] = [
            'method' => $method,
            'path' => $fullPath,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'middleware' => [...$groupMiddleware, ...$middleware],
        ];

        return $this;
    }

    /**
     * Turn "/documents/{id:\d+}" into an anchored regex and its parameter names.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function compile(string $path): array
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}|[^{]+/',
            static function (array $m) use (&$params): string {
                if (!isset($m[1]) || $m[1] === '') {
                    return preg_quote($m[0], '#');
                }
                $params[] = $m[1];
                $constraint = $m[2] ?? '[^/]+';

                return '(?P<' . $m[1] . '>' . $constraint . ')';
            },
            $path
        );

        return ['#^' . $regex . '$#u', $params];
    }

    /**
     * @param array{handler: array{0: class-string, 1: string}|Closure, middleware: list<string>} $route
     * @param list<string> $params
     */
    private function runPipeline(Request $request, array $route, array $params): Response
    {
        $core = fn (Request $req): Response => $this->callHandler($route['handler'], $req, $params);

        $pipeline = array_reduce(
            array_reverse($this->resolveMiddleware($route['middleware'])),
            static fn (callable $next, array $mw): callable =>
                static fn (Request $req): Response => (new $mw[0]())->handle($req, $next, $mw[1]),
            $core
        );

        return $pipeline($request);
    }

    /**
     * Expand group names and aliases into [class, args] pairs, keeping the first
     * occurrence of any middleware listed more than once.
     *
     * @param list<string> $names
     * @return list<array{0: class-string<Middleware>, 1: list<string>}>
     */
    private function resolveMiddleware(array $names): array
    {
        $resolved = [];
        foreach (array_unique($this->expandGroups($names)) as $name) {
            [$alias, $argString] = array_pad(explode(':', $name, 2), 2, '');
            $class = $this->aliases[$alias] ?? throw new InvalidArgumentException("Unknown middleware [$alias].");
            $args = $argString === '' ? [] : array_map('trim', explode(',', $argString));
            $resolved[] = [$class, $args];
        }

        return $resolved;
    }

    /**
     * Replace group names with their members, recursively.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function expandGroups(array $names, int $depth = 0): array
    {
        if ($depth > 10) {
            throw new InvalidArgumentException('Middleware groups are nested too deeply (circular reference?).');
        }

        $flat = [];
        foreach ($names as $name) {
            if (isset($this->groups[$name])) {
                array_push($flat, ...$this->expandGroups($this->groups[$name], $depth + 1));
            } else {
                $flat[] = $name;
            }
        }

        return $flat;
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param list<string> $params
     */
    private function callHandler(array|Closure $handler, Request $request, array $params): Response
    {
        if ($handler instanceof Closure) {
            $result = $handler($request, ...$params);
        } else {
            [$class, $method] = $handler;
            $result = (new $class())->{$method}($request, ...$params);
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }

        throw new InvalidArgumentException('Route handlers must return a Response or a string.');
    }
}
