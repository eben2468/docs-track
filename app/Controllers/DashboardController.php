<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DashboardService;
use Core\Auth;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Dashboards (FR-11): university-wide for administrators, department view for staff and HODs.
 * Admins can switch to their own department's view with ?view=department.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user() ?? throw new HttpException(403);
        $alertHours = max(1, (int) setting('unreceived_alert_hours'));
        $service = new DashboardService();

        if ($user['role'] === 'admin' && $request->query('view') !== 'department') {
            return $this->view('dashboard/admin', ['title' => 'Dashboard', 'alertHours' => $alertHours] + $service->admin($alertHours));
        }

        return $this->view('dashboard/department', [
            'title' => 'Dashboard',
            'user' => $user,
            'alertHours' => $alertHours,
        ] + $service->department($user, $alertHours));
    }
}
