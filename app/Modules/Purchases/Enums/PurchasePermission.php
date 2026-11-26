<?php

namespace App\Modules\Purchases\Enums;

enum PurchasePermission: string
{
    case View = 'purchases.view';
    case Create = 'purchases.create';
    case Update = 'purchases.update';
    case Confirm = 'purchases.confirm';
    case Cancel = 'purchases.cancel';
    case Receive = 'purchases.receive';
    case Return = 'purchases.return';
    case ViewCost = 'purchases.view_cost';
    case ManageCost = 'purchases.manage_cost';
    case Export = 'purchases.export';
    case ManageSuppliers = 'purchases.manage_suppliers';
    case ImportSuppliers = 'purchases.import_suppliers';
    case ExportSuppliers = 'purchases.export_suppliers';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Consultar compras e fornecedores',
            self::Create => 'Cadastrar pedidos de compra',
            self::Update => 'Editar pedidos em rascunho',
            self::Confirm => 'Confirmar pedidos',
            self::Cancel => 'Cancelar pedidos e encerrar saldo pendente',
            self::Receive => 'Registrar recebimentos',
            self::Return => 'Registrar devoluções e estornar recebimentos',
            self::ViewCost => 'Consultar custos e valores de compra',
            self::ManageCost => 'Informar custos, valores e pagamento (exige consultar custos)',
            self::Export => 'Exportar pedidos de compra',
            self::ManageSuppliers => 'Gerenciar fornecedores',
            self::ImportSuppliers => 'Importar fornecedores',
            self::ExportSuppliers => 'Exportar fornecedores',
        };
    }

    /** @return list<array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(fn (self $permission): array => ['key' => $permission->value, 'label' => $permission->label()], self::cases());
    }
}
