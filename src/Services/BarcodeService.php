<?php
declare(strict_types=1);

namespace SnackQuest\Services;

final class BarcodeService
{
    public static function normalize(string $raw): string
    {
        return preg_replace('/[^0-9]/', '', trim($raw)) ?? '';
    }

    public static function validate(string $raw): bool
    {
        $code = self::normalize($raw);
        if (!in_array(strlen($code), [8, 12, 13], true)) {
            return false;
        }
        if (strlen($code) === 8 && ($code[0] === '0' || $code[0] === '1') && self::isUpce($code)) {
            return true;
        }
        return self::checkDigit(substr($code, 0, -1)) === (int)substr($code, -1);
    }

    public static function formatName(string $raw): string
    {
        return match (strlen(self::normalize($raw))) {
            8 => 'EAN-8 / UPC-E', 12 => 'UPC-A', 13 => 'EAN-13', default => 'GTIN',
        };
    }

    private static function checkDigit(string $data): int
    {
        $sum = 0;
        $weight = 3;
        for ($i = strlen($data) - 1; $i >= 0; $i--) {
            $sum += ((int)$data[$i]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }
        return (10 - ($sum % 10)) % 10;
    }

    private static function isUpce(string $code): bool
    {
        $ns = $code[0];
        $d = str_split(substr($code, 1, 6));
        $last = $d[5];
        if (in_array($last, ['0', '1', '2'], true)) {
            $data = $ns . $d[0] . $d[1] . $last . '0000' . $d[2] . $d[3] . $d[4];
        } elseif ($last === '3') {
            $data = $ns . $d[0] . $d[1] . $d[2] . '00000' . $d[3] . $d[4];
        } elseif ($last === '4') {
            $data = $ns . $d[0] . $d[1] . $d[2] . $d[3] . '00000' . $d[4];
        } else {
            $data = $ns . implode('', array_slice($d, 0, 5)) . '0000' . $last;
        }
        return strlen($data) === 11 && self::checkDigit($data) === (int)$code[7];
    }
}
