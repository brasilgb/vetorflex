<?php

namespace App\Modules\Pos\Enums;

enum PosPermission: string
{
    case Operate = 'pos.operate';
    case OpenSession = 'pos.open_session';
    case CloseSession = 'pos.close_session';
    case ViewSessions = 'pos.view_sessions';
    case ManageTerminals = 'pos.manage_terminals';
    case ApplyDiscounts = 'pos.apply_discounts';
    case ChangePrices = 'pos.change_prices';
    case Authorize = 'pos.authorize';
    case Withdraw = 'pos.withdraw';
    case Supply = 'pos.supply';
    case CancelSales = 'pos.cancel_sales';
    case Return = 'pos.return';
    case Reprint = 'pos.reprint';
    case ViewReports = 'pos.view_reports';
    case ManageSettings = 'pos.manage_settings';

    public function label(): string
    {
        return match ($this) {
            self::Operate => 'Operar a frente de caixa',
            self::OpenSession => 'Abrir caixa',
            self::CloseSession => 'Fechar caixa',
            self::ViewSessions => 'Consultar sessões de caixa',
            self::ManageTerminals => 'Gerenciar terminais',
            self::ApplyDiscounts => 'Aplicar descontos',
            self::ChangePrices => 'Alterar preços',
            self::Authorize => 'Autorizar operações (supervisor)',
            self::Withdraw => 'Realizar sangrias',
            self::Supply => 'Realizar suprimentos',
            self::CancelSales => 'Cancelar vendas',
            self::Return => 'Registrar devoluções',
            self::Reprint => 'Emitir segunda via',
            self::ViewReports => 'Consultar relatórios do PDV',
            self::ManageSettings => 'Gerenciar configurações e favoritos',
        };
    }

    /** @return list<array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(fn (self $permission): array => ['key' => $permission->value, 'label' => $permission->label()], self::cases());
    }
}
