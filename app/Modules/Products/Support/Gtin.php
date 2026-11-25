<?php

namespace App\Modules\Products\Support;

class Gtin
{
    private const LENGTHS = [8, 12, 13, 14];

    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[\s\-.]/', '', $value);
    }

    /**
     * Valida GTIN-8, UPC-A (12), EAN-13 e GTIN-14 pelo dígito verificador módulo 10.
     */
    public static function isValid(string $code): bool
    {
        if (preg_match('/^\d+$/D', $code) !== 1 || ! in_array(strlen($code), self::LENGTHS, true) || (int) $code === 0) {
            return false;
        }

        $sum = 0;

        foreach (str_split(strrev(substr($code, 0, -1))) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) substr($code, -1);
    }
}
