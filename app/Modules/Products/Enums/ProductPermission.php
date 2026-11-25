<?php

namespace App\Modules\Products\Enums;

enum ProductPermission: string
{
    case View = 'products.view';
    case Create = 'products.create';
    case Update = 'products.update';
    case Deactivate = 'products.deactivate';
    case Reactivate = 'products.reactivate';
    case Import = 'products.import';
    case Export = 'products.export';
    case ManageCategories = 'products.manage_categories';
    case ManageBrands = 'products.manage_brands';
    case ManageUnits = 'products.manage_units';
    case ViewCost = 'products.view_cost';
    case ManageCost = 'products.manage_cost';
    case ManagePrices = 'products.manage_prices';
    case ViewInventory = 'products.view_inventory';
    case AdjustInventory = 'products.adjust_inventory';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Consultar produtos e serviços',
            self::Create => 'Cadastrar produtos e serviços',
            self::Update => 'Editar produtos, variações e imagens',
            self::Deactivate => 'Inativar produtos',
            self::Reactivate => 'Reativar produtos',
            self::Import => 'Importar produtos',
            self::Export => 'Exportar produtos',
            self::ManageCategories => 'Gerenciar categorias',
            self::ManageBrands => 'Gerenciar marcas',
            self::ManageUnits => 'Gerenciar unidades de medida',
            self::ViewCost => 'Consultar custos, margem e markup',
            self::ManageCost => 'Alterar custos (exige consultar custos)',
            self::ManagePrices => 'Alterar preços de venda e promocionais',
            self::ViewInventory => 'Consultar saldos e movimentações',
            self::AdjustInventory => 'Registrar e estornar movimentações de estoque',
        };
    }

    /** @return list<array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(fn (self $permission): array => ['key' => $permission->value, 'label' => $permission->label()], self::cases());
    }
}
