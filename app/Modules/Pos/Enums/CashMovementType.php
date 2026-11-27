<?php

namespace App\Modules\Pos\Enums;

/**
 * Movimentações operacionais da gaveta. Não são receitas nem despesas: sangria e suprimento só mudam o dinheiro
 * disponível no caixa; restituição é a devolução em dinheiro de uma venda.
 */
enum CashMovementType: string
{
    case Supply = 'supply';
    case Withdrawal = 'withdrawal';
    case Adjustment = 'adjustment';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Supply => 'Suprimento',
            self::Withdrawal => 'Sangria',
            self::Adjustment => 'Ajuste autorizado',
            self::Refund => 'Restituição em dinheiro',
        };
    }

    /** Efeito no dinheiro da gaveta: +1 entra, -1 sai, 0 o sinal vem do próprio valor (ajuste). */
    public function sign(): int
    {
        return match ($this) {
            self::Supply => 1,
            self::Withdrawal, self::Refund => -1,
            self::Adjustment => 0,
        };
    }
}
