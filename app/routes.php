<?php

declare(strict_types=1);

use App\Controllers\Admin\DepartmentController;
use App\Controllers\Admin\DocumentTypeController;
use App\Controllers\Admin\HolidayController;
use App\Controllers\Admin\SettingController;
use App\Controllers\Admin\UserController;
use App\Controllers\Admin\UserImportController;
use App\Controllers\Api\DepartmentUserController;
use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\BrandingController;
use App\Controllers\DocumentController;
use App\Controllers\DocumentListController;
use App\Controllers\ScanController;
use App\Controllers\SearchController;
use App\Controllers\TrackController;
use App\Controllers\HomeController;
use App\Controllers\MovementController;
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

        // Public tracking (FR-14) — also the URL encoded in every QR label.
        $router->get('/track', [TrackController::class, 'index']);
        $router->get('/track/{trackingNo:[A-Za-z0-9-]+}', [TrackController::class, 'show']);

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

            // Documents.
            $router->get('/documents', [DocumentListController::class, 'index']);
            $router->get('/documents/export', [DocumentListController::class, 'export']);
            $router->get('/search', [SearchController::class, 'go']);
            $router->get('/scan', [ScanController::class, 'index']);
            $router->get('/documents/create', [DocumentController::class, 'create']);
            $router->post('/documents/create', [DocumentController::class, 'store']);
            $router->get('/documents/{id:\d+}', [DocumentController::class, 'show']);
            $router->get('/documents/{id:\d+}/registered', [DocumentController::class, 'registered']);
            $router->get('/documents/{id:\d+}/label', [DocumentController::class, 'label']);
            $router->post('/documents/{id:\d+}/cancel', [DocumentController::class, 'cancel']);
            $router->post('/documents/{id:\d+}/send', [MovementController::class, 'send']);
            $router->post('/documents/{id:\d+}/receive', [MovementController::class, 'receive']);
            $router->post('/documents/receive-bulk', [MovementController::class, 'receiveBulk']);
            $router->post('/documents/{id:\d+}/return', [MovementController::class, 'returnToSender']);
            $router->post('/documents/{id:\d+}/recall', [MovementController::class, 'recall']);
            $router->post('/documents/{id:\d+}/complete', [MovementController::class, 'complete']);
            $router->get('/api/departments/{id:\d+}/users', [DepartmentUserController::class, 'index']);
            $router->post('/documents/{id:\d+}/attachments', [DocumentController::class, 'attachments']);
            $router->get('/attachments/{id:\d+}/download', [AttachmentController::class, 'download']);
            $router->post('/attachments/{id:\d+}/visibility', [AttachmentController::class, 'visibility'], ['role:admin']);

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
