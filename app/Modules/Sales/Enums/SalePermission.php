<?php

namespace App\Modules\Sales\Enums;

enum SalePermission: string
{
    case View = 'sales.view';
    case Create = 'sales.create';
    case Update = 'sales.update';
    case Confirm = 'sales.confirm';
    case Cancel = 'sales.cancel';
    case ManageQuotes = 'sales.manage_quotes';
    case ApproveQuotes = 'sales.approve_quotes';
    case Fulfill = 'sales.fulfill';
    case Return = 'sales.return';
    case ApplyDiscounts = 'sales.apply_discounts';
    case ChangePrices = 'sales.change_prices';
    case CustomItems = 'sales.custom_items';
    case RegisterCustomers = 'sales.register_customers';
    case RegisterItems = 'sales.register_items';
    case ViewCost = 'sales.view_cost';
    case ViewMargin = 'sales.view_margin';
    case Export = 'sales.export';
    case ViewIndicators = 'sales.view_indicators';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Consultar vendas e orçamentos',
            self::Create => 'Cadastrar vendas',
            self::Update => 'Editar vendas em rascunho',
            self::Confirm => 'Confirmar e concluir vendas',
            self::Cancel => 'Cancelar vendas e encerrar saldo pendente',
            self::ManageQuotes => 'Cadastrar, emitir e converter orçamentos',
            self::ApproveQuotes => 'Aprovar e recusar orçamentos',
            self::Fulfill => 'Registrar e estornar atendimentos',
            self::Return => 'Registrar devoluções',
            self::ApplyDiscounts => 'Aplicar descontos',
            self::ChangePrices => 'Alterar preços de venda no documento',
            self::CustomItems => 'Incluir itens avulsos, fora do catálogo',
            self::RegisterCustomers => 'Cadastrar clientes simplificados',
            self::RegisterItems => 'Cadastrar itens simplificados',
            self::ViewCost => 'Consultar custos das vendas',
            self::ViewMargin => 'Consultar margens (exige consultar custos)',
            self::Export => 'Exportar vendas',
            self::ViewIndicators => 'Consultar indicadores de vendas',
        };
    }

    /** @return list<array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(fn (self $permission): array => ['key' => $permission->value, 'label' => $permission->label()], self::cases());
    }
}
