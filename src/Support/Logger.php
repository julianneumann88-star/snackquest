<?php
/**
 * SnackQuest — file logger with level filtering and daily rotation.
 * Version: 1.0.0 (2026-07-21)
 * Errors while logging are swallowed on purpose: logging must never break the app.
 */
declare(strict_types=1);

namespace SnackQuest\Support;

final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(
        private readonly string $dir,
        private readonly string $minLevel = 'info',
    ) {
    }

    public function debug(string $msg, array $ctx = []): void { $this->log('debug', $msg, $ctx); }
    public function info(string $msg, array $ctx = []): void { $this->log('info', $msg, $ctx); }
    public function warning(string $msg, array $ctx = []): void { $this->log('warning', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void { $this->log('error', $msg, $ctx); }

    public function log(string $level, string $msg, array $ctx = []): void
    {
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }
        try {
            if (!is_dir($this->dir)) {
                @mkdir($this->dir, 0775, true);
            }
            $ctxStr = $ctx === [] ? '' : ' ' . json_encode($this->scrub($ctx), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $line = sprintf("%s | %-8s | %s%s\n", date('Y-m-d\TH:i:s'), strtoupper($level), $msg, $ctxStr);
            @file_put_contents($this->dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // never let logging take the app down
        }
    }

    /** Remove obviously sensitive keys before writing context to disk. */
    private function scrub(array $ctx): array
    {
        $blocked = ['password', 'pass', 'token', 'secret', 'api_key', 'apikey', 'authorization', 'cookie'];
        $out = [];
        foreach ($ctx as $k => $v) {
            $lk = strtolower((string)$k);
            $hit = false;
            foreach ($blocked as $b) {
                if (str_contains($lk, $b)) { $hit = true; break; }
            }
            $out[$k] = $hit ? '[redacted]' : (is_scalar($v) || $v === null ? $v : json_decode(json_encode($v), true));
        }
        return $out;
    }
}

