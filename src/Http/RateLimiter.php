<?php
/**
 * SnackQuest — DB-backed rate limiter (fixed window). Works on shared hosting without
 * memcached/redis. Keys are hashed so raw identifiers (e-mail, IP) never hit the table.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Http;

use SnackQuest\Database;

final class RateLimiter
{
    /**
     * Returns true if the action is allowed, false if the limit is exceeded.
     * Fails open on DB errors (availability over strictness for reads),
     * but callers protecting auth endpoints should treat exceptions as "deny".
     */
    public static function allow(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $hash = hash('sha256', $key);
        $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
        $table = Database::table('rate_limits');
        $pdo = Database::pdo();

        $sql = Database::driver() === 'sqlite'
            ? "INSERT INTO {$table} (rl_key, window_start, attempts) VALUES (:k, :w, 1)
               ON CONFLICT(rl_key, window_start) DO UPDATE SET attempts = attempts + 1"
            : "INSERT INTO {$table} (rl_key, window_start, attempts) VALUES (:k, :w, 1)
               ON DUPLICATE KEY UPDATE attempts = attempts + 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['k' => $hash, 'w' => $windowStart]);

        $stmt = $pdo->prepare("SELECT attempts FROM {$table} WHERE rl_key = :k AND window_start = :w");
        $stmt->execute(['k' => $hash, 'w' => $windowStart]);
        $attempts = (int)($stmt->fetchColumn() ?: 0);

        // opportunistic cleanup of old windows (cheap, occasional)
        if (random_int(1, 50) === 1) {
            $pdo->prepare("DELETE FROM {$table} WHERE window_start < :cutoff")
                ->execute(['cutoff' => time() - 86400]);
        }

        return $attempts <= $maxAttempts;
    }
}

