<?php

namespace App\Modules\Core\Commerce;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Cálculos monetários de documentos comerciais (Compras, Vendas), sempre com `Brick\Math`. Valores em reais têm 2 casas; quantidades e custos unitários, 4.
 */
class CommercialMath
{
    public static function money(BigDecimal|string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);
    }

    /** Total líquido do item: quantidade × custo, arredondado a centavos, menos o desconto do item. */
    public static function itemTotal(string $quantity, string $unitCost, string $discount, int $position): string
    {
        $gross = BigDecimal::of($quantity)->multipliedBy($unitCost)->toScale(2, RoundingMode::HalfUp);

        if ($gross->isLessThan($discount)) {
            throw ValidationException::withMessages(["items.{$position}.discount" => 'O desconto do item não pode superar o seu valor.']);
        }

        return (string) $gross->minus($discount);
    }

    /**
     * Rateia o ajuste (despesas menos desconto geral) entre os itens, proporcionalmente ao valor líquido.
     * Itens gratuitos não recebem rateio, salvo quando todos os itens somam zero: então o peso é a quantidade.
     * Sobras de centavos vão aos maiores restos e, no empate, ao item de menor posição.
     *
     * @param  list<array{total: string, quantity: string}>  $items
     * @return list<string> Custo de aquisição de cada item.
     */
    public static function allocate(array $items, string $adjustment): array
    {
        $byValue = array_map(fn (array $item): BigInteger => BigDecimal::of($item['total'])->getUnscaledValue(), $items);
        $sum = array_reduce($byValue, fn (BigInteger $carry, BigInteger $weight): BigInteger => $carry->plus($weight), BigInteger::zero());
        $weights = $sum->isZero() ? array_map(fn (array $item): BigInteger => BigDecimal::of($item['quantity'])->toScale(4)->getUnscaledValue(), $items) : $byValue;
        $sum = array_reduce($weights, fn (BigInteger $carry, BigInteger $weight): BigInteger => $carry->plus($weight), BigInteger::zero());
        $cents = BigDecimal::of($adjustment)->toScale(2)->getUnscaledValue();
        $isNegative = $cents->isNegative();
        $cents = $cents->abs();
        $shares = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $product = $cents->multipliedBy($weight);
            $shares[$index] = $sum->isZero() ? BigInteger::zero() : $product->quotient($sum);
            $remainders[$index] = $sum->isZero() ? BigInteger::zero() : $product->remainder($sum);
        }

        $left = $cents->minus(array_reduce($shares, fn (BigInteger $carry, BigInteger $share): BigInteger => $carry->plus($share), BigInteger::zero()))->toInt();
        $order = array_keys($remainders);
        usort($order, fn (int $first, int $second): int => $remainders[$second]->compareTo($remainders[$first]) ?: $first <=> $second);

        foreach (array_slice($order, 0, $left) as $index) {
            $shares[$index] = $shares[$index]->plus(1);
        }

        return array_map(function (array $item, int $index) use ($shares, $isNegative): string {
            $share = BigDecimal::ofUnscaledValue($shares[$index], 2);

            return (string) BigDecimal::of($item['total'])->plus($isNegative ? $share->negated() : $share);
        }, $items, array_keys($items));
    }

    /**
     * Parcela de um valor que corresponde às quantidades acumuladas. A parcela final fecha o total exato,
     * de modo que vários recebimentos ou devoluções nunca somam centavos a mais ou a menos.
     */
    public static function cumulativeShare(string $total, string $quantity, string $doneBefore, string $doneAfter, string $allocatedBefore): string
    {
        if (BigDecimal::of($doneAfter)->isGreaterThanOrEqualTo($quantity)) {
            $share = BigDecimal::of($total)->minus($allocatedBefore);
        } else {
            $share = BigDecimal::of($total)->multipliedBy($doneAfter)->dividedBy($quantity, 2, RoundingMode::HalfUp)->minus($allocatedBefore);
        }

        return (string) ($share->isNegative() ? BigDecimal::zero()->toScale(2) : $share->toScale(2));
    }

    /** @param int<0, max> $scale */
    public static function unitCost(string $total, string $quantity, int $scale): string
    {
        return (string) BigDecimal::of($total)->dividedBy($quantity, $scale, RoundingMode::HalfUp);
    }

    public static function hasFraction(string $quantity): bool
    {
        return BigDecimal::of($quantity)->strippedOfTrailingZeros()->getScale() > 0;
    }
}
