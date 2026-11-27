<?php

namespace App\Modules\Inventory\Enums;

/** Permissões individuais do módulo Estoque (`inventory`). */
enum InventoryPermission: string
{
    case View = 'inventory.view';
    case ManageItems = 'inventory.manage_items';
    case Move = 'inventory.move';
    case Transfer = 'inventory.transfer';
    case Adjust = 'inventory.adjust';
    case Count = 'inventory.count';
    case ApproveCounts = 'inventory.approve_counts';
    case ManageWarehouses = 'inventory.manage_warehouses';
    case ViewReports = 'inventory.view_reports';
    case ViewCost = 'inventory.view_cost';
    case ImportExport = 'inventory.import_export';
    case ManageSettings = 'inventory.manage_settings';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Consultar mercadorias, saldos e movimentações',
            self::ManageItems => 'Cadastrar e editar mercadorias',
            self::Move => 'Registrar entradas e saídas manuais',
            self::Transfer => 'Transferir entre depósitos',
            self::Adjust => 'Ajustar saldos e estornar movimentações',
            self::Count => 'Criar inventários e registrar contagens',
            self::ApproveCounts => 'Aprovar inventários (gera os ajustes)',
            self::ManageWarehouses => 'Gerenciar depósitos',
            self::ViewReports => 'Consultar relatórios de estoque',
            self::ViewCost => 'Consultar custo e valor do estoque',
            self::ImportExport => 'Importar e exportar mercadorias (CSV)',
            self::ManageSettings => 'Gerenciar configurações do estoque',
        };
    }

    /** @return list<array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(fn (self $permission): array => ['key' => $permission->value, 'label' => $permission->label()], self::cases());
    }
}
