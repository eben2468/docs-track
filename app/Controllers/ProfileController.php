<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Department;
use App\Services\ProfileService;
use App\Services\ServiceException;
use Core\Auth;
use Core\Request;
use Core\Response;

/**
 * The logged-in user's profile and password (FR-1).
 */
final class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = Auth::user();

        return $this->view('profile/index', [
            'title' => 'My profile',
            'user' => $user,
            'department' => (new Department())->find((int) $user['department_id']),
        ]);
    }

    public function updateDetails(Request $request): Response
    {
        $data = $this->validate($request, [
            'full_name' => 'required|max:150',
            'phone' => ['max:20', 'regex:/^[0-9+()\s-]*$/'],
        ], ['full_name' => 'Full name']);

        try {
            (new ProfileService())->updateDetails((int) Auth::id(), (string) $data['full_name'], $data['phone']);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/profile');
        }

        return Response::redirect('/profile')->with('success', 'Your profile has been updated.');
    }

    public function updatePassword(Request $request): Response
    {
        $this->validate($request, [
            'current_password' => 'required|max:255',
            'password' => 'required|strong_password|max:255|confirmed',
        ], ['current_password' => 'Current password', 'password' => 'New password']);

        try {
            (new ProfileService())->changePassword(
                (int) Auth::id(),
                (string) $request->input('current_password'),
                (string) $request->input('password')
            );
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/profile');
        }

        return Response::redirect('/profile')->with('success', 'Your password has been changed.');
    }
}
