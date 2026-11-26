<?php

namespace App\Modules\Purchases\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Pix = 'pix';
    case BankSlip = 'bank_slip';
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';
    case Transfer = 'transfer';
    case Cheque = 'cheque';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::Pix => 'PIX',
            self::BankSlip => 'Boleto',
            self::CreditCard => 'Cartão de crédito',
            self::DebitCard => 'Cartão de débito',
            self::Transfer => 'Transferência',
            self::Cheque => 'Cheque',
            self::Other => 'Outros',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $method): array => ['value' => $method->value, 'label' => $method->label()], self::cases());
    }
}
