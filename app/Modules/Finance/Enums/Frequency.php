<?php

namespace App\Modules\Finance\Enums;

use Carbon\CarbonImmutable;

enum Frequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Semanal',
            self::Monthly => 'Mensal',
            self::Quarterly => 'Trimestral',
            self::Semiannual => 'Semestral',
            self::Annual => 'Anual',
        };
    }

    /** Data da ocorrência de ordem `$index` (0 = data inicial), preservando o dia do mês quando possível. */
    public function occurrence(CarbonImmutable $start, int $index): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $start->addWeeks($index),
            self::Monthly => $start->addMonthsNoOverflow($index),
            self::Quarterly => $start->addMonthsNoOverflow($index * 3),
            self::Semiannual => $start->addMonthsNoOverflow($index * 6),
            self::Annual => $start->addYearsNoOverflow($index),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
