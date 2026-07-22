<?php
/**
 * SnackQuest — Google OAuth 2.0 (Authorization Code + PKCE) without external libraries.
 * Scopes: openid email profile — nothing else.
 * Account linking: if a verified Google e-mail matches an existing local account,
 * the Google identity is attached to that account instead of creating a duplicate.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Auth;

use SnackQuest\Config;
use SnackQuest\Database;
use SnackQuest\Support\HttpClient;
use SnackQuest\Support\Logger;

final class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $log,
        private readonly HttpClient $http,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool)$this->config->get('auth.google.enabled', false)
            && $this->config->get('auth.google.client_id', '') !== ''
            && $this->config->get('auth.google.client_secret', '') !== '';
    }

    /** Build the consent URL and stash state + PKCE verifier in the session. */
    public function buildAuthUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $_SESSION['g_state'] = $state;
        $_SESSION['g_verifier'] = $verifier;

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'             => (string)$this->config->get('auth.google.client_id'),
            'redirect_uri'          => (string)$this->config->get('auth.google.redirect_uri'),
            'response_type'         => 'code',
            'scope'                 => 'openid email profile',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'access_type'           => 'online',
            'prompt'                => 'select_account',
        ]);
    }

    /**
     * Handle the callback. Returns the local user id on success.
     * @return array{ok:bool, user_id:?int, error:?string}
     */
    public function handleCallback(?string $code, ?string $state): array
    {
        $fail = static fn (string $msg): array => ['ok' => false, 'user_id' => null, 'error' => $msg];
        $neutral = 'Die Google-Anmeldung konnte nicht abgeschlossen werden. Bitte versuch es erneut.';

        $expectedState = $_SESSION['g_state'] ?? null;
        $verifier = $_SESSION['g_verifier'] ?? null;
        unset($_SESSION['g_state'], $_SESSION['g_verifier']);

        if (!$code || !$state || !is_string($expectedState) || !hash_equals($expectedState, $state) || !is_string($verifier)) {
            $this->log->warning('Google OAuth: state/code validation failed');
            return $fail($neutral);
        }

        $resp = $this->http->request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id'     => (string)$this->config->get('auth.google.client_id'),
            'client_secret' => (string)$this->config->get('auth.google.client_secret'),
            'code'          => $code,
            'code_verifier' => $verifier,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => (string)$this->config->get('auth.google.redirect_uri'),
        ]), 12);

        if ($resp['status'] !== 200) {
            $this->log->error('Google OAuth: token exchange failed', ['status' => $resp['status']]);
            return $fail($neutral);
        }
        $tokens = json_decode($resp['body'], true);
        $accessToken = is_array($tokens) ? ($tokens['access_token'] ?? null) : null;
        if (!is_string($accessToken)) {
            return $fail($neutral);
        }

        $info = $this->http->request('GET', self::USERINFO_URL, [
            'Authorization' => 'Bearer ' . $accessToken,
        ], null, 12);
        if ($info['status'] !== 200) {
            $this->log->error('Google OAuth: userinfo failed', ['status' => $info['status']]);
            return $fail($neutral);
        }
        $profile = json_decode($info['body'], true);
        if (!is_array($profile) || empty($profile['sub'])) {
            return $fail($neutral);
        }

        $sub = (string)$profile['sub'];
        $email = mb_strtolower((string)($profile['email'] ?? ''));
        $emailVerified = ($profile['email_verified'] ?? false) === true;
        $name = mb_substr((string)($profile['name'] ?? ''), 0, 80);
        $picture = (string)($profile['picture'] ?? '');

        if ($email === '' || !$emailVerified) {
            return $fail('Dein Google-Konto hat keine bestätigte E-Mail-Adresse.');
        }

        $userId = $this->findOrCreateUser($sub, $email, $name, $picture);
        return ['ok' => true, 'user_id' => $userId, 'error' => null];
    }

    private function findOrCreateUser(string $sub, string $email, string $name, string $picture): int
    {
        $pdo = Database::pdo();
        $users = Database::table('users');
        $now = gmdate('Y-m-d H:i:s');

        // 1) Existing Google identity
        $stmt = $pdo->prepare("SELECT id FROM {$users} WHERE google_sub = :s");
        $stmt->execute(['s' => $sub]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }

        // 2) Existing local account with the same verified e-mail → link identities
        $stmt = $pdo->prepare("SELECT id FROM {$users} WHERE email = :e");
        $stmt->execute(['e' => $email]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $up = $pdo->prepare(
                "UPDATE {$users} SET google_sub = :s, email_verified_at = COALESCE(email_verified_at, :verified_at),
                 avatar_url = CASE WHEN avatar_url = '' THEN :a ELSE avatar_url END, updated_at = :updated_at WHERE id = :id"
            );
            $up->execute([
                's' => $sub, 'verified_at' => $now, 'updated_at' => $now,
                'a' => $picture, 'id' => $id,
            ]);
            $this->log->info('Google identity linked to existing account', ['user_id' => (int)$id]);
            return (int)$id;
        }

        // 3) New account (e-mail is verified by Google)
        $ins = $pdo->prepare(
            "INSERT INTO {$users} (email, email_verified_at, password_hash, google_sub, display_name, avatar_url,
                                   locale, timezone, country, created_at, updated_at)
             VALUES (:e, :t, NULL, :s, :d, :a, 'de', 'Europe/Berlin', 'DE', :t2, :t3)"
        );
        $ins->execute([
            'e' => $email, 't' => $now, 's' => $sub,
            'd' => $name !== '' ? $name : ucfirst(explode('@', $email)[0]),
            'a' => $picture, 't2' => $now, 't3' => $now,
        ]);
        $newId = (int)$pdo->lastInsertId();
        $this->log->info('User created via Google', ['user_id' => $newId]);
        return $newId;
    }
}

