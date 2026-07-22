<?php
/**
 * SnackQuest — HTTP request wrapper (path resolution relative to base_path, safe input access).
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,        // path relative to base_path, always starts with '/'
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        // normalize: no trailing slash except root, collapse duplicate slashes
        $path = preg_replace('#/{2,}#', '/', $path);
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $_SERVER,
        );
    }

    public function q(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    /** Query-string value as array (for visible multi-select filters). */
    public function qArray(string $key): array
    {
        $v = $this->query[$key] ?? [];
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    public function p(string $key, ?string $default = null): ?string
    {
        $v = $this->post[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    /** POST value as array (for multi-selects). */
    public function pArray(string $key): array
    {
        $v = $this->post[$key] ?? [];
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    public function ip(): string
    {
        return (string)($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function wantsJson(): bool
    {
        return str_contains((string)($this->server['HTTP_ACCEPT'] ?? ''), 'application/json')
            || str_starts_with($this->path, '/api/');
    }
}

