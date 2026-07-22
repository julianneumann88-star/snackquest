<?php
/**
 * SnackQuest — curl-based HTTP client (production). Hard timeouts, TLS verification.
 * Version: 1.0.1 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Support;

final class CurlHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 8): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(1, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'SnackQuest/1.0',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);
        return [
            'status' => $status,
            'body'   => is_string($responseBody) ? $responseBody : '',
            'error'  => $error,
        ];
    }
}

