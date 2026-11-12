<?php

declare(strict_types=1);

use App\Controllers\Admin\DepartmentController;
use App\Controllers\Admin\DocumentTypeController;
use App\Controllers\Admin\HolidayController;
use App\Controllers\Admin\SettingController;
use App\Controllers\Admin\UserController;
use App\Controllers\Admin\UserImportController;
use App\Controllers\AuthController;
use App\Controllers\BrandingController;
use App\Controllers\HomeController;
use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\RoleMiddleware;
use Core\Router;

/*
 * Route table. Every browser route uses the "web" group (CSRF check on POST).
 *   guest  - only when signed out (login, password reset)
 *   auth   - signed-in, active users
 *   role:x - signed-in users with one of the listed roles (after "auth")
 */
return static function (Router $router): void {
    $router->aliasMiddleware('csrf', CsrfMiddleware::class)
        ->aliasMiddleware('auth', AuthMiddleware::class)
        ->aliasMiddleware('guest', GuestMiddleware::class)
        ->aliasMiddleware('role', RoleMiddleware::class);
    $router->middlewareGroup('web', ['csrf']);

    $router->group(['middleware' => ['web']], static function (Router $router): void {
        // Public branding assets (used on the login page too).
        $router->get('/branding/theme', [BrandingController::class, 'theme']);
        $router->get('/branding/logo', [BrandingController::class, 'logo']);

        // Guests only.
        $router->group(['middleware' => ['guest']], static function (Router $router): void {
            $router->get('/login', [AuthController::class, 'showLogin']);
            $router->post('/login', [AuthController::class, 'login']);
            $router->get('/forgot-password', [PasswordResetController::class, 'showForgot']);
            $router->post('/forgot-password', [PasswordResetController::class, 'sendLink']);
            $router->get('/reset-password/{token:[a-f0-9]+}', [PasswordResetController::class, 'showReset']);
            $router->post('/reset-password/{token:[a-f0-9]+}', [PasswordResetController::class, 'reset']);
        });

        // Signed-in users.
        $router->group(['middleware' => ['auth']], static function (Router $router): void {
            $router->get('/', [HomeController::class, 'index']);
            $router->post('/logout', [AuthController::class, 'logout']);
            $router->get('/profile', [ProfileController::class, 'show']);
            $router->post('/profile', [ProfileController::class, 'updateDetails']);
            $router->post('/profile/password', [ProfileController::class, 'updatePassword']);

            // Administrators.
            $router->group(['prefix' => '/admin', 'middleware' => ['role:admin']], static function (Router $router): void {
                $router->get('/departments', [DepartmentController::class, 'index']);
                $router->get('/departments/create', [DepartmentController::class, 'create']);
                $router->post('/departments', [DepartmentController::class, 'store']);
                $router->get('/departments/{id:\d+}/edit', [DepartmentController::class, 'edit']);
                $router->post('/departments/{id:\d+}', [DepartmentController::class, 'update']);
                $router->post('/departments/{id:\d+}/status', [DepartmentController::class, 'status']);

                $router->get('/users', [UserController::class, 'index']);
                $router->get('/users/create', [UserController::class, 'create']);
                $router->post('/users', [UserController::class, 'store']);
                $router->get('/users/import', [UserImportController::class, 'show']);
                $router->post('/users/import', [UserImportController::class, 'import']);
                $router->get('/users/import/template', [UserImportController::class, 'template']);
                $router->get('/users/{id:\d+}/edit', [UserController::class, 'edit']);
                $router->post('/users/{id:\d+}', [UserController::class, 'update']);
                $router->post('/users/{id:\d+}/status', [UserController::class, 'status']);
                $router->post('/users/{id:\d+}/unlock', [UserController::class, 'unlock']);
                $router->post('/users/{id:\d+}/password-link', [UserController::class, 'sendPasswordLink']);

                $router->get('/document-types', [DocumentTypeController::class, 'index']);
                $router->get('/document-types/create', [DocumentTypeController::class, 'create']);
                $router->post('/document-types', [DocumentTypeController::class, 'store']);
                $router->get('/document-types/{id:\d+}/edit', [DocumentTypeController::class, 'edit']);
                $router->post('/document-types/{id:\d+}', [DocumentTypeController::class, 'update']);
                $router->post('/document-types/{id:\d+}/status', [DocumentTypeController::class, 'status']);

                $router->get('/holidays', [HolidayController::class, 'index']);
                $router->get('/holidays/create', [HolidayController::class, 'create']);
                $router->post('/holidays', [HolidayController::class, 'store']);
                $router->get('/holidays/{id:\d+}/edit', [HolidayController::class, 'edit']);
                $router->post('/holidays/{id:\d+}', [HolidayController::class, 'update']);
                $router->post('/holidays/{id:\d+}/delete', [HolidayController::class, 'destroy']);

                $router->get('/settings', [SettingController::class, 'edit']);
                $router->post('/settings', [SettingController::class, 'update']);
            });
        });
    });
};
