<?php
/**
 * SnackQuest — minimal router: exact and parameterized routes ({id}), method-aware.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest;

use SnackQuest\Http\Request;

final class Router
{
    /** @var array<string, array<string, array>> */
    private array $routes = [];

    public function get(string $pattern, array $handler): void
    {
        $this->routes['GET'][$pattern] = $handler;
    }

    public function post(string $pattern, array $handler): void
    {
        $this->routes['POST'][$pattern] = $handler;
    }

    /** @return array{0: array, 1: array<string,string>}|null */
    public function match(Request $request): ?array
    {
        // HTTP defines HEAD as the metadata-only counterpart of GET. Reuse the
        // GET route table so uptime checks, crawlers and PWA analyzers receive
        // the same status and headers without duplicating every public route.
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $table = $this->routes[$method] ?? [];
        if (isset($table[$request->path])) {
            return [$table[$request->path], []];
        }
        foreach ($table as $pattern => $handler) {
            if (!str_contains($pattern, '{')) {
                continue;
            }
            $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (preg_match($regex, $request->path, $m)) {
                $params = [];
                foreach ($m as $k => $v) {
                    if (is_string($k)) {
                        $params[$k] = urldecode($v);
                    }
                }
                return [$handler, $params];
            }
        }
        return null;
    }
}
