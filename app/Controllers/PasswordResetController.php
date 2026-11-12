<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PasswordResetService;
use App\Services\ServiceException;
use Core\Request;
use Core\Response;

/**
 * Forgot password / reset password (FR-1).
 */
final class PasswordResetController extends Controller
{
    public function showForgot(Request $request): Response
    {
        return $this->view('auth/forgot-password', ['title' => 'Forgot password'], 'layouts/auth');
    }

    public function sendLink(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email|max:150']);

        try {
            (new PasswordResetService())->requestReset((string) $data['email'], $request->ip());
        } catch (ServiceException $e) {
            return Response::redirect('/forgot-password')->withInput($data)->with('danger', $e->getMessage());
        }

        return Response::redirect('/login')->with(
            'success',
            'If an active account uses that email address, a password reset link has been sent to it. The link expires in '
            . PasswordResetService::TOKEN_MINUTES . ' minutes.'
        );
    }

    public function showReset(Request $request, string $token): Response
    {
        if (!(new PasswordResetService())->isTokenValid($token)) {
            return Response::redirect('/forgot-password')
                ->with('danger', 'This password reset link is invalid or has expired. Please request a new one.');
        }

        return $this->view('auth/reset-password', ['title' => 'Set a new password', 'token' => $token], 'layouts/auth');
    }

    public function reset(Request $request, string $token): Response
    {
        $this->validate($request, ['password' => 'required|strong_password|max:255|confirmed'], ['password' => 'New password']);

        try {
            (new PasswordResetService())->reset($token, (string) $request->input('password'));
        } catch (ServiceException $e) {
            return Response::redirect('/forgot-password')->with('danger', $e->getMessage() . ' Please request a new one.');
        }

        return Response::redirect('/login')->with('success', 'Your password has been changed. You can now sign in.');
    }
}
