<?php

namespace App\Modules\Purchases\Support;

use App\Modules\Purchases\Enums\PaymentCondition;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Valida a previsão de pagamento. Os vencimentos devem somar exatamente o total do pedido.
 */
class PaymentSchedule
{
    /**
     * @param  list<array{due_date: string, amount: string, method?: string|null}>  $installments
     * @return list<array{sequence: int, is_entry: bool, due_date: string, amount: string, method: string|null}>
     */
    public static function build(PaymentCondition $condition, ?string $method, array $installments, string $total, string $orderDate): array
    {
        [$minimum, $maximum] = $condition->scheduleLimits();

        if ($condition === PaymentCondition::Cash && $installments === []) {
            $installments = [['due_date' => $orderDate, 'amount' => $total]];
        }

        if (count($installments) < $minimum || count($installments) > $maximum) {
            throw self::invalid($minimum === 0
                ? 'Condição a definir não possui vencimentos.'
                : "A condição {$condition->label()} exige de {$minimum} a {$maximum} vencimento(s).");
        }

        if ($condition !== PaymentCondition::Undefined && BigDecimal::of($total)->isZero()) {
            throw self::invalid('Pedido sem valor deve usar a condição "A definir".');
        }

        usort($installments, fn (array $first, array $second): int => strcmp($first['due_date'], $second['due_date']));
        $sum = array_reduce($installments, fn (BigDecimal $carry, array $installment): BigDecimal => $carry->plus($installment['amount']), BigDecimal::zero());

        if ($installments !== [] && ! $sum->isEqualTo($total)) {
            throw self::invalid('A soma dos vencimentos ('.PurchaseMath::money($sum).') deve ser igual ao total do pedido ('.PurchaseMath::money($total).').');
        }

        return array_map(fn (array $installment, int $index): array => [
            'sequence' => $index + 1,
            'is_entry' => $condition === PaymentCondition::EntryInstallments && $index === 0,
            'due_date' => $installment['due_date'],
            'amount' => PurchaseMath::money($installment['amount']),
            'method' => ($installment['method'] ?? null) ?: $method,
        ], $installments, array_keys($installments));
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['installments' => $message]);
    }
}
