<?php

declare(strict_types=1);

namespace Core;

use Dotenv\Dotenv;
use ErrorException;
use Throwable;

/**
 * Application kernel: loads environment and config, installs error handling,
 * and turns an HTTP request into a response.
 *
 * Web:  (new App($base))->bootstrap()->run();
 * CLI:  (new App($base))->bootstrap();   // config, DB, logging without HTTP
 */
final class App
{
    private static ?self $instance = null;

    private bool $booted = false;

    public function __construct(private readonly string $basePath)
    {
        self::$instance = $this;
    }

    /**
     * The current application instance.
     */
    public static function instance(): self
    {
        return self::$instance ?? throw new \LogicException('The application has not been created.');
    }

    /**
     * Absolute path inside the project root.
     */
    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
    }

    /**
     * Load .env and config, set timezone, logging, views and PHP error handling.
     */
    public function bootstrap(): self
    {
        if ($this->booted) {
            return $this;
        }

        Dotenv::createImmutable($this->basePath)->safeLoad();
        Config::load($this->basePath('config'));

        date_default_timezone_set((string) Config::get('app.timezone', 'Africa/Accra'));
        mb_internal_encoding('UTF-8');

        Logger::setPath($this->basePath((string) Config::get('app.log_path', 'storage/logs/app.log')));
        View::setPath($this->basePath('app/Views'));

        $this->registerErrorHandling();
        $this->booted = true;

        return $this;
    }

    /**
     * Handle the current HTTP request and send the response.
     */
    public function run(): void
    {
        $request = Request::capture((string) parse_url((string) Config::get('app.url', ''), PHP_URL_PATH));

        Session::start((array) Config::get('app.session', []));

        $this->handle($request)
            ->withSecurityHeaders($request->isSecure())
            ->send();
    }

    /**
     * Dispatch a request through the router, converting exceptions into error responses.
     */
    public function handle(Request $request): Response
    {
        try {
            $router = new Router();
            (require $this->basePath('app/routes.php'))($router);

            return $router->dispatch($request);
        } catch (ValidationException $e) {
            if ($request->isAjax()) {
                return Response::json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422);
            }

            return $e->response();
        } catch (HttpException $e) {
            return $this->errorResponse($e->status(), $request, $e->getMessage());
        } catch (Throwable $e) {
            Logger::exception($e);

            return $this->errorResponse(500, $request, '', $e);
        }
    }

    /**
     * Build the error page (or JSON body for AJAX requests) for a status code.
     */
    public function errorResponse(int $status, ?Request $request = null, string $message = '', ?Throwable $e = null): Response
    {
        $debug = (bool) Config::get('app.debug', false);

        if ($request !== null && $request->isAjax()) {
            $body = ['error' => $message !== '' ? $message : self::statusText($status)];
            if ($debug && $e !== null) {
                $body['exception'] = $e::class . ': ' . $e->getMessage();
            }

            return Response::json($body, $status);
        }

        try {
            $view = View::exists("errors/$status") ? "errors/$status" : 'errors/500';
            $html = View::render($view, [
                'status' => $status,
                'title' => self::statusText($status),
                'message' => $message,
                'exception' => $debug ? $e : null,
            ], 'layouts/public');
        } catch (Throwable $renderError) {
            Logger::exception($renderError);
            $html = '<!doctype html><title>Error</title><h1>' . $status . ' ' . e(self::statusText($status)) . '</h1>';
        }

        return Response::html($html, $status);
    }

    /**
     * Standard reason phrase for the error statuses the app uses.
     */
    public static function statusText(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            413 => 'Upload Too Large',
            419 => 'Page Expired',
            429 => 'Too Many Requests',
            422 => 'Unprocessable Request',
            default => 'Server Error',
        };
    }

    private function registerErrorHandling(): void
    {
        $debug = (bool) Config::get('app.debug', false);

        error_reporting(E_ALL);
        ini_set('display_errors', $debug && PHP_SAPI === 'cli' ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        if (PHP_SAPI === 'cli') {
            return;
        }

        set_exception_handler(function (Throwable $e): void {
            Logger::exception($e);
            $this->errorResponse(500, null, '', $e)->withSecurityHeaders()->send();
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $e = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            Logger::exception($e);
            if (!headers_sent()) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $this->errorResponse(500, null, '', $e)->withSecurityHeaders()->send();
            }
        });
    }
}
