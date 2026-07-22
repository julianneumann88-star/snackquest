<?php
/**
 * SnackQuest — template renderer. Templates are plain PHP files under src/Views.
 * All dynamic output must go through e() (htmlspecialchars).
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Support;

final class View
{
    public static string $viewsDir = __DIR__ . '/../Views';
    public static string $basePath = '';
    public static string $appVersion = '1.0.1';

    /** Render a page template inside a layout. */
    public static function render(string $template, array $data = [], string $layout = 'layouts/base'): string
    {
        $content = self::partial($template, $data);
        $data['content'] = $content;
        return self::partial($layout, $data);
    }

    /** Render a single template file to string. */
    public static function partial(string $template, array $data = []): string
    {
        $file = self::$viewsDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }
        // Deliberate, contained use of extract(): $data comes exclusively from our
        // controllers (never from request input) and EXTR_SKIP prevents overwrites.
        extract($data, EXTR_SKIP);
        $base = self::$basePath; // available in all templates as $base
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }
}
