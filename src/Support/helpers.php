<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function u(string $path): string
{
    return \SnackQuest\Support\View::$basePath . $path;
}

function fmt_price(mixed $value, string $currency = 'EUR'): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return number_format((float)$value, 2, ',', '.') . ' ' . ($currency === 'EUR' ? '€' : e($currency));
}

function product_img(?string $url): string
{
    if (!$url || !preg_match('#^https://(images|world)\.openfoodfacts\.org/#', $url)) {
        return '';
    }
    return $url;
}

function product_key_label(string $key): string
{
    return str_starts_with($key, 'off:') ? substr($key, 4) : $key;
}
