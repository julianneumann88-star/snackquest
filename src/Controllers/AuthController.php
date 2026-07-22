<?php
/**
 * SnackQuest — auth controller: login, register, verify, reset, Google OAuth, logout.
 * All POSTs are CSRF-checked in the front controller; rate limits are applied here.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Controllers;

use SnackQuest\App;
use SnackQuest\Http\RateLimiter;
use SnackQuest\Http\Request;
use SnackQuest\Http\Response;
use SnackQuest\Http\Session;

final class AuthController extends BaseController
{
    public function loginForm(Request $r, array $p): never
    {
        if (Session::userId() !== null) {
            $this->redirect('/app');
        }
        $this->render('auth/login', [
            'title' => 'Anmelden',
            'googleEnabled' => $this->google->isEnabled(),
            'error' => $r->q('error'),
        ]);
    }

    public function loginSubmit(Request $r, array $p): never
    {
        if (!$this->rateOk('login', $r)) {
            Session::flash('error', 'Zu viele Versuche. Bitte warte ein paar Minuten.');
            $this->redirect('/login');
        }
        $result = $this->auth->login($r->p('email', '') ?? '', $r->p('password', '') ?? '');
        if (!$result['ok']) {
            Session::flash('error', $result['error']);
            $this->redirect('/login');
        }
        Session::login((int)$result['user_id']);
        $user = $this->auth->findUserById((int)$result['user_id']);
        $this->redirect(($user['onboarding_completed'] ?? 0) ? '/app' : '/app/onboarding');
    }

    public function registerForm(Request $r, array $p): never
    {
        if (Session::userId() !== null) {
            $this->redirect('/app');
        }
        $this->render('auth/register', [
            'title' => 'Konto erstellen',
            'googleEnabled' => $this->google->isEnabled(),
        ]);
    }

    public function registerSubmit(Request $r, array $p): never
    {
        if (!$this->rateOk('register', $r)) {
            Session::flash('error', 'Zu viele Versuche. Bitte warte ein paar Minuten.');
            $this->redirect('/register');
        }
        $result = $this->auth->register(
            $r->p('email', '') ?? '',
            $r->p('password', '') ?? '',
            $r->p('display_name', '') ?? ''
        );
        if (!$result['ok']) {
            Session::flash('error', (string)$result['error']);
            $this->redirect('/register');
        }
        $this->render('auth/check-mail', [
            'title' => 'Fast geschafft',
            'email' => $r->p('email', '') ?? '',
        ]);
    }

    public function verify(Request $r, array $p): never
    {
        $result = $this->auth->verifyEmail($r->q('token', '') ?? '');
        if ($result['ok']) {
            Session::flash('success', 'E-Mail bestätigt! Du kannst dich jetzt anmelden.');
            $this->redirect('/login');
        }
        Session::flash('error', (string)$result['error']);
        $this->redirect('/login');
    }

    public function forgotForm(Request $r, array $p): never
    {
        $this->render('auth/forgot', ['title' => 'Passwort vergessen']);
    }

    public function forgotSubmit(Request $r, array $p): never
    {
        if (!$this->rateOk('forgot', $r)) {
            Session::flash('error', 'Zu viele Versuche. Bitte warte ein paar Minuten.');
            $this->redirect('/forgot-password');
        }
        $this->auth->requestPasswordReset($r->p('email', '') ?? '');
        // Same message regardless of whether the account exists:
        $this->render('auth/check-mail', [
            'title' => 'E-Mail unterwegs',
            'email' => $r->p('email', '') ?? '',
            'resetMode' => true,
        ]);
    }

    public function resetForm(Request $r, array $p): never
    {
        $token = $r->q('token', '') ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            Session::flash('error', 'Ungültiger Link.');
            $this->redirect('/forgot-password');
        }
        $this->render('auth/reset', ['title' => 'Neues Passwort setzen', 'token' => $token]);
    }

    public function resetSubmit(Request $r, array $p): never
    {
        $token = $r->p('token', '') ?? '';
        $pw = $r->p('password', '') ?? '';
        $pw2 = $r->p('password_confirm', '') ?? '';
        if ($pw !== $pw2) {
            Session::flash('error', 'Die Passwörter stimmen nicht überein.');
            $this->redirect('/reset-password?token=' . urlencode($token));
        }
        $result = $this->auth->resetPassword($token, $pw);
        if (!$result['ok']) {
            Session::flash('error', (string)$result['error']);
            $this->redirect('/forgot-password');
        }
        Session::flash('success', 'Passwort geändert. Melde dich jetzt mit dem neuen Passwort an.');
        $this->redirect('/login');
    }

    public function googleStart(Request $r, array $p): never
    {
        if (!$this->google->isEnabled()) {
            Session::flash('info', 'Google-Anmeldung ist noch nicht aktiviert. Nutze bitte E-Mail und Passwort.');
            $this->redirect('/login');
        }
        header('Location: ' . $this->google->buildAuthUrl());
        exit;
    }

    public function googleCallback(Request $r, array $p): never
    {
        if (!$this->google->isEnabled()) {
            $this->redirect('/login');
        }
        if ($r->q('error') !== null) {
            // user cancelled at Google
            Session::flash('info', 'Google-Anmeldung abgebrochen.');
            $this->redirect('/login');
        }
        $result = $this->google->handleCallback($r->q('code'), $r->q('state'));
        if (!$result['ok']) {
            Session::flash('error', (string)$result['error']);
            $this->redirect('/login?error=oauth_callback');
        }
        Session::login((int)$result['user_id']);
        $user = $this->auth->findUserById((int)$result['user_id']);
        App::$log->info('Login via Google', ['user_id' => $result['user_id']]);
        $this->redirect(($user['onboarding_completed'] ?? 0) ? '/app' : '/app/onboarding');
    }

    public function logout(Request $r, array $p): never
    {
        Session::logout();
        $this->redirect('/');
    }

    private function rateOk(string $action, Request $r): bool
    {
        try {
            $window = (int)App::$config->get('auth.rate_limit_window_s', 900);
            $max = (int)App::$config->get('auth.rate_limit_max_attempts', 8);
            $ipOk = RateLimiter::allow($action . ':ip:' . $r->ip(), $max * 3, $window);
            $idOk = RateLimiter::allow($action . ':id:' . mb_strtolower($r->p('email', '') ?? ''), $max, $window);
            return $ipOk && $idOk;
        } catch (\Throwable $e) {
            App::$log->error('Rate limiter unavailable, denying auth action: ' . $e->getMessage());
            return false; // fail closed on auth endpoints
        }
    }
}

