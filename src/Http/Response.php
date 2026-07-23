<?php
/**
 * SnackQuest — HTTP response helpers (HTML, JSON, redirect) with security headers.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Http;

use SnackQuest\App;

final class Response
{
    public static function securityHeaders(string $basePath): void
    {
        if (App::$requestId !== '') {
            header('X-Request-ID: ' . App::$requestId);
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Origin-Agent-Cluster: ?1');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "img-src 'self' data: blob: https://images.openfoodfacts.org https://world.openfoodfacts.org https://lh3.googleusercontent.com; "
            . "style-src 'self' 'unsafe-inline'; "
            . "script-src 'self'; "
            . "connect-src 'self'; worker-src 'self' blob:; "
            . "font-src 'self'; "
            . "frame-src https://www.youtube-nocookie.com; "
            . "object-src 'none'; manifest-src 'self'; media-src 'self' blob:; "
            . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
        );
        if (($_SERVER['HTTPS'] ?? '') !== '') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function html(string $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        if (Session::userId() !== null) {
            header('Cache-Control: private, no-store');
            header('Vary: Cookie');
        }
        echo $body;
        exit;
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function jsonDownload(array $data, string $filename): never
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'export.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Redirect only to internal targets. External or protocol-relative URLs are rejected
     * to prevent open-redirect abuse.
     */
    public static function redirect(string $basePath, string $target, int $status = 302): never
    {
        if (!preg_match('#^/(?!/)#', $target)) {
            $target = '/';
        }
        http_response_code($status);
        header('Location: ' . $basePath . $target);
        exit;
    }
}
