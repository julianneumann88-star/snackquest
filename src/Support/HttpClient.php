<?php
/**
 * SnackQuest — HTTP client interface. Implementations: CurlHttpClient (production),
 * MockHttpClient (tests), FixtureHttpClient (e2e). One class per file (autoloader!).
 * Version: 1.0.1 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Support;

interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:?string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 8): array;
}

