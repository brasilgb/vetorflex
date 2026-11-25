<?php

namespace App\Modules\Products\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Margem é o lucro sobre o preço de venda; markup é o lucro sobre o custo.
 */
class Pricing
{
    public static function margin(?string $cost, ?string $sale): ?string
    {
        return self::percentage($cost, $sale, $sale);
    }

    public static function markup(?string $cost, ?string $sale): ?string
    {
        return self::percentage($cost, $sale, $cost);
    }

    public static function effective(?string $sale, ?string $promotional): ?string
    {
        return $promotional ?? $sale;
    }

    public static function equals(?string $first, ?string $second): bool
    {
        if ($first === null || $second === null) {
            return $first === $second;
        }

        return BigDecimal::of($first)->isEqualTo($second);
    }

    private static function percentage(?string $cost, ?string $sale, ?string $base): ?string
    {
        if ($cost === null || $sale === null || $base === null || BigDecimal::of($base)->isZero()) {
            return null;
        }

        return (string) BigDecimal::of($sale)->minus($cost)->multipliedBy(100)->dividedBy($base, 2, RoundingMode::HalfUp);
    }
}
