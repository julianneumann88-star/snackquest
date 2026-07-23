<?php
/**
 * SnackQuest — PDO factory (MariaDB in production, SQLite for tests).
 * Exceptions on, real prepares, utf8mb4. Table names use a configurable prefix.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest;

final class Database
{
    private static ?\PDO $pdo = null;
    private static string $prefix = 'sq_';
    private static string $driver = 'mysql';

    public static function init(Config $config): void
    {
        self::$prefix = (string)$config->get('db.prefix', 'sq_');
        self::$driver = (string)$config->get('db.driver', 'mysql');
        self::$pdo = null; // reset (tests may re-init)

        if (self::$driver === 'sqlite') {
            $path = (string)$config->get('db.sqlite_path', ':memory:');
            $pdo = new \PDO('sqlite:' . $path, null, null, self::options());
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 8000');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string)$config->get('db.host'),
                (int)$config->get('db.port', 3306),
                (string)$config->get('db.name'),
            );
            $pdo = new \PDO($dsn, (string)$config->get('db.user'), (string)$config->get('db.pass'), self::options());
        }
        self::$pdo = $pdo;
    }

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Database not initialized. Call Database::init() first.');
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    public static function prefix(): string
    {
        return self::$prefix;
    }

    /** Resolve a logical table name to its prefixed physical name. */
    public static function table(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Invalid table name: ' . $name);
        }
        return self::$prefix . $name;
    }

    /** Current time expression usable in both dialects. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function options(): array
    {
        return [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_TIMEOUT            => 8,
        ];
    }
}
