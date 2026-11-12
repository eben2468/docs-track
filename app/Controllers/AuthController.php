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
 * Sign in / sign out (FR-1): username first, then a one-time code sent by email.
 */
final class AuthController extends Controller
{
    /** Session key holding the username waiting for its code, and when the last code was sent. */
    private const PENDING_KEY = 'pending_login';

    /** Seconds before "Send a new code" may be used again. */
    public const RESEND_SECONDS = 60;

    /** A pending sign-in is forgotten after this many seconds. */
    private const PENDING_SECONDS = 1800;

    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login', [
            'title' => 'Sign in',
            'username' => (string) ($this->pending()['username'] ?? ''),
        ], 'layouts/auth');
    }

    /**
     * POST /login: step 1, email a code to the account with this username.
     */
    public function sendCode(Request $request): Response
    {
        $data = $this->validate($request, ['username' => 'required|max:50'], ['username' => 'Username']);
        $username = strtolower(trim((string) $data['username']));

        try {
            (new AuthService())->requestCode($username, $request->ip());
        } catch (ServiceException $e) {
            return Response::redirect('/login')->withInput(['username' => $username])->with('danger', $e->getMessage());
        }

        Session::put(self::PENDING_KEY, ['username' => $username, 'sent_at' => time()]);

        return Response::redirect('/login/verify');
    }

    /**
     * GET /login/verify: step 2, the one-time code form.
     */
    public function showVerify(Request $request): Response
    {
        $pending = $this->pending();
        if ($pending === null) {
            return Response::redirect('/login');
        }

        return $this->view('auth/verify', [
            'title' => 'Enter your sign-in code',
            'username' => $pending['username'],
            'minutes' => AuthService::CODE_MINUTES,
            'resendIn' => max(0, self::RESEND_SECONDS - (time() - $pending['sent_at'])),
        ], 'layouts/auth');
    }

    /**
     * POST /login/verify: check the code and sign in.
     */
    public function verify(Request $request): Response
    {
        $pending = $this->pending();
        if ($pending === null) {
            return Response::redirect('/login')->with('warning', 'Your sign-in session expired. Please enter your username again.');
        }
        $data = $this->validate($request, ['code' => 'required|max:32'], ['code' => 'Sign-in code']);

        try {
            $user = (new AuthService())->verifyCode($pending['username'], (string) $data['code'], $request->ip());
        } catch (ServiceException $e) {
            return Response::redirect('/login/verify')->with('danger', $e->getMessage());
        }

        Session::forget(self::PENDING_KEY);
        $intended = Session::get(AuthMiddleware::INTENDED_KEY);
        Session::forget(AuthMiddleware::INTENDED_KEY);
        $target = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : '/';

        return Response::redirect($target)->with('success', 'Welcome back, ' . $user['full_name'] . '.');
    }

    /**
     * POST /login/resend: email a fresh code (at most once a minute).
     */
    public function resend(Request $request): Response
    {
        $pending = $this->pending();
        if ($pending === null) {
            return Response::redirect('/login');
        }
        $wait = self::RESEND_SECONDS - (time() - $pending['sent_at']);
        if ($wait > 0) {
            return Response::redirect('/login/verify')->with('warning', "Please wait $wait second(s) before requesting another code.");
        }

        try {
            (new AuthService())->requestCode($pending['username'], $request->ip());
        } catch (ServiceException $e) {
            return Response::redirect('/login/verify')->with('danger', $e->getMessage());
        }
        Session::put(self::PENDING_KEY, ['username' => $pending['username'], 'sent_at' => time()]);

        return Response::redirect('/login/verify')->with('success', 'A new code is on its way. Only the newest code will work.');
    }

    public function logout(Request $request): Response
    {
        (new AuthService())->logout();

        return Response::redirect('/login')->with('success', 'You have been signed out.');
    }

    /**
     * The pending sign-in from step 1, or null if none (or it is too old).
     *
     * @return array{username: string, sent_at: int}|null
     */
    private function pending(): ?array
    {
        $pending = Session::get(self::PENDING_KEY);
        if (!is_array($pending) || !is_string($pending['username'] ?? null) || !is_int($pending['sent_at'] ?? null)) {
            return null;
        }
        if (time() - $pending['sent_at'] > self::PENDING_SECONDS) {
            Session::forget(self::PENDING_KEY);

            return null;
        }

        return ['username' => $pending['username'], 'sent_at' => $pending['sent_at']];
    }
}
