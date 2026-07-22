<?php
/**
 * SnackQuest — application bootstrap: autoloader, config, error handling, DB, session.
 * Version: 1.0.0 (2026-07-21)
 * Technical details stay in server logs; clients receive only a neutral error and
 * a request reference. Fatal shutdown errors and JSON API errors are handled too.
 */
declare(strict_types=1);

namespace SnackQuest;

use SnackQuest\Http\Session;
use SnackQuest\Support\Logger;
use SnackQuest\Support\View;

require __DIR__ . '/Support/helpers.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'SnackQuest\\')) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen('SnackQuest\\')));
    $file = __DIR__ . '/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

final class App
{
    public static Config $config;
    public static Logger $log;
    public static string $requestId = '';
    private static bool $handlingError = false;

    public static function boot(?string $configFile = null): void
    {
        // Install a minimal safety net before configuration and database access.
        // This also catches a missing/broken production config without leaking paths.
        self::$requestId = self::newRequestId();
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        $envConfig = getenv('SQ_CONFIG');
        if ($configFile === null && is_string($envConfig) && $envConfig !== '') {
            $configFile = $envConfig;
        }
        self::$config = Config::load($configFile);
        date_default_timezone_set((string)self::$config->get('timezone', 'Europe/Berlin'));
        mb_internal_encoding('UTF-8');

        error_reporting(E_ALL);
        ini_set('display_errors', self::$config->get('app_env') === 'development' ? '1' : '0');
        ini_set('log_errors', '1');

        self::$log = new Logger(
            (string)self::$config->get('log.dir'),
            (string)self::$config->get('log.level', 'info'),
        );

        // Configure the renderer before DB initialization so even a DB boot error
        // can show the neutral branded error page.
        View::$viewsDir = __DIR__ . '/Views';
        View::$basePath = (string)self::$config->get('base_path', '');
        View::$appVersion = (string)self::$config->get('app_version', '1.0.2');

        Database::init(self::$config);
    }

    public static function startSession(): void
    {
        Session::start(
            (string)self::$config->get('auth.session_name', 'sqsess'),
            (string)self::$config->get('base_path', ''),
        );
    }

    public static function handleException(\Throwable $e): void
    {
        if (self::$handlingError) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, 'Interner Fehler. Referenz: ' . (self::$requestId ?: 'unbekannt') . PHP_EOL);
                exit(1);
            }
            http_response_code(500);
            echo 'Interner Fehler. Referenz: ' . htmlspecialchars(self::$requestId ?: 'unbekannt', ENT_QUOTES, 'UTF-8');
            exit;
        }
        self::$handlingError = true;
        try {
            self::$log->error('Unhandled exception: ' . $e->getMessage(), [
                'type' => get_class($e),
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
                'request_id' => self::$requestId,
                'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
                'path' => (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''),
            ]);
        } catch (\Throwable) {
            // Error reporting must never hide the original failure.
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Interner Fehler. Referenz: ' . (self::$requestId ?: 'unbekannt') . PHP_EOL);
            exit(1);
        }
        http_response_code(500);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Request-ID: ' . self::$requestId);
        if (self::wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'Interner Fehler. Bitte versuche es erneut.',
                'request_id' => self::$requestId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        try {
            echo View::render('pages/500', [
                'title' => 'Technischer Fehler',
                'requestId' => self::$requestId,
                'isLoggedIn' => false,
                'flashes' => [],
            ]);
        } catch (\Throwable) {
            echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Fehler · SnackQuest</title>'
                . '<body><h1>Da ist etwas schiefgelaufen.</h1><p>Referenz: '
                . htmlspecialchars(self::$requestId, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
        }
        exit;
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        self::handleException(new \ErrorException(
            (string)$error['message'],
            0,
            (int)$error['type'],
            (string)$error['file'],
            (int)$error['line']
        ));
    }

    private static function wantsJson(): bool
    {
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || str_starts_with($path, View::$basePath . '/api/');
    }

    private static function newRequestId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return substr(hash('sha256', uniqid('', true)), 0, 16);
        }
    }
}
