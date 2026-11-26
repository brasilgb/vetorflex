<?php

namespace App\Modules\Core\Documents;

use Brick\Math\BigDecimal;

/**
 * Formatação brasileira de valores e quantidades nos documentos comerciais.
 */
class DocumentFormat
{
    public static function money(string $value): string
    {
        return 'R$ '.self::decimal($value);
    }

    /**
     * @param  int<0, max>  $minimum
     * @param  int<0, max>  $maximum
     */
    public static function decimal(string $value, int $minimum = 2, int $maximum = 2): string
    {
        [$integer, $fraction] = array_pad(explode('.', (string) BigDecimal::of($value)->toScale($maximum)), 2, '');
        $fraction = str_pad(rtrim($fraction, '0'), $minimum, '0');
        $sign = str_starts_with($integer, '-') ? '-' : '';

        return $sign.number_format((int) ltrim($integer, '-'), 0, ',', '.').($fraction === '' ? '' : ",{$fraction}");
    }

    public static function quantity(string $value): string
    {
        return self::decimal($value, 0, 4);
    }
}
