<?php

namespace App\Modules\Finance\Enums;

enum TitleKind: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';

    public function label(): string
    {
        return match ($this) {
            self::Receivable => 'A receber',
            self::Payable => 'A pagar',
        };
    }

    public function categoryKind(): CategoryKind
    {
        return $this === self::Receivable ? CategoryKind::Income : CategoryKind::Expense;
    }

    /** Sinal da movimentação gerada pela baixa: recebimento entra, pagamento sai. */
    public function sign(): int
    {
        return $this === self::Receivable ? 1 : -1;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
