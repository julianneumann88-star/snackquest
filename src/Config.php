<?php
/**
 * SnackQuest — configuration loader with startup validation.
 * Fails loudly if required keys are missing. Never exposes secret values in errors.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest;

final class Config
{
    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(?string $file = null): self
    {
        $file ??= dirname(__DIR__) . '/config/config.local.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Config error: config/config.local.php missing. Copy config.example.php and fill it in.');
        }
        $data = require $file;
        if (!is_array($data)) {
            throw new \RuntimeException('Config error: config.local.php must return an array.');
        }
        $cfg = new self($data);
        $cfg->validate();
        return $cfg;
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** Dot-notation getter: $config->get('db.host') */
    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function all(): array
    {
        return $this->data;
    }

    private function validate(): void
    {
        $required = ['app_base_url', 'db.driver', 'log.dir'];
        if ($this->get('db.driver') === 'mysql') {
            $required = array_merge($required, ['db.host', 'db.name', 'db.user']);
        }
        $missing = [];
        foreach ($required as $key) {
            $v = $this->get($key);
            if ($v === null || $v === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException('Config error: missing required keys: ' . implode(', ', $missing));
        }
    }
}

