<?php
/**
 * SnackQuest — session handling with secure cookie params and login helpers.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Http;

final class Session
{
    public static function start(string $name, string $basePath): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        session_name($name);
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $secure = ($https !== '' && $https !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => ($basePath === '' ? '/' : $basePath . '/'),
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::maintainLogin();
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** One-shot flash messages: [['type' => 'success|error|info', 'text' => ...]] */
    public static function flash(string $type, string $text): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'text' => $text];
    }

    public static function pullFlashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($f) ? $f : [];
    }

    public static function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return is_int($id) ? $id : null;
    }

    public static function login(int $userId): void
    {
        unset($_SESSION['_csrf']);
        self::regenerate();
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_at'] = time();
        $_SESSION['last_activity_at'] = time();
        $_SESSION['regenerated_at'] = time();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    private static function maintainLogin(): void
    {
        if (!isset($_SESSION['user_id']) || !is_int($_SESSION['user_id'])) {
            return;
        }
        $now = time();
        $loginAt = (int)($_SESSION['login_at'] ?? $now);
        $lastActivity = (int)($_SESSION['last_activity_at'] ?? $now);
        if ($now - $loginAt > 7 * 24 * 60 * 60 || $now - $lastActivity > 12 * 60 * 60) {
            $_SESSION = [];
            self::regenerate();
            return;
        }
        if ($now - (int)($_SESSION['regenerated_at'] ?? 0) > 30 * 60) {
            self::regenerate();
            $_SESSION['regenerated_at'] = $now;
        }
        $_SESSION['last_activity_at'] = $now;
    }

}
