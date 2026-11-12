<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\AuthService;
use App\Services\ServiceException;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Sign in / sign out (FR-1).
 */
final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login', ['title' => 'Sign in'], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'login' => 'required|max:150',
            'password' => 'required|max:255',
        ], ['login' => 'Email or staff ID']);

        try {
            $user = (new AuthService())->attempt((string) $data['login'], (string) $request->input('password'), $request->ip());
        } catch (ServiceException $e) {
            return Response::redirect('/login')->withInput(['login' => $data['login']])->with('danger', $e->getMessage());
        }

        $intended = Session::get(AuthMiddleware::INTENDED_KEY);
        Session::forget(AuthMiddleware::INTENDED_KEY);
        $target = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : '/';

        return Response::redirect($target)->with('success', 'Welcome back, ' . $user['full_name'] . '.');
    }

    public function logout(Request $request): Response
    {
        (new AuthService())->logout();

        return Response::redirect('/login')->with('success', 'You have been signed out.');
    }
}
