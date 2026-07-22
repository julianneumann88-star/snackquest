<?php
/**
 * SnackQuest — CSRF token generation and verification (session-bound, constant-time compare).
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Http;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function verify(Request $request): bool
    {
        $sent = $request->p('_csrf') ?? ($request->server['HTTP_X_CSRF_TOKEN'] ?? '');
        $stored = $_SESSION['_csrf'] ?? '';
        return is_string($sent) && is_string($stored) && $stored !== '' && hash_equals($stored, $sent);
    }
}

