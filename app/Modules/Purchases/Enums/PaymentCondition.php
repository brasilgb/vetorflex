<?php

namespace App\Modules\Purchases\Enums;

/**
 * Previsão comercial. Não representa pagamento efetuado.
 */
enum PaymentCondition: string
{
    case Undefined = 'undefined';
    case Cash = 'cash';
    case Term = 'term';
    case Installments = 'installments';
    case EntryInstallments = 'entry_installments';

    public function label(): string
    {
        return match ($this) {
            self::Undefined => 'A definir',
            self::Cash => 'À vista',
            self::Term => 'A prazo',
            self::Installments => 'Parcelado',
            self::EntryInstallments => 'Entrada mais parcelas',
        };
    }

    /** @return array{int, int} Quantidade mínima e máxima de vencimentos. */
    public function scheduleLimits(): array
    {
        return match ($this) {
            self::Undefined => [0, 0],
            self::Cash, self::Term => [1, 1],
            self::Installments, self::EntryInstallments => [2, 60],
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $condition): array => ['value' => $condition->value, 'label' => $condition->label()], self::cases());
    }
}
