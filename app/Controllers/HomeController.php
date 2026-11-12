<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DocumentMovement;
use Core\Auth;
use Core\Database;
use Core\Logger;
use Core\Request;
use Core\Response;
use Throwable;

/**
 * Temporary home page until the dashboards are built: documents awaiting the user's
 * department (with bulk receive) and a system check.
 */
final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();

        return $this->view('home', [
            'title' => 'Home',
            'incoming' => (new DocumentMovement())->incomingFor((int) $user['department_id']),
            'checks' => [
                'PHP version' => [true, PHP_VERSION],
                'Environment' => [true, (string) config('app.env')],
                'Timezone' => [true, now()->format('D, j M Y H:i T')],
                'Database' => $this->databaseStatus(),
            ],
        ]);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function databaseStatus(): array
    {
        try {
            $db = Database::instance();
            $db->ping();
            $version = (string) $db->fetchValue('SELECT VERSION()');

            return [true, sprintf('Connected to "%s" (server %s)', config('database.database'), $version)];
        } catch (Throwable $e) {
            Logger::exception($e);
            $detail = config('app.debug') ? ': ' . $e->getMessage() : '.';

            return [false, 'Not connected' . $detail];
        }
    }
}
