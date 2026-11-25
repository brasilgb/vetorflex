<?php

namespace App\Modules\Customers\Enums;

enum CustomerPermission: string
{
    case View = 'customers.view';
    case Create = 'customers.create';
    case Update = 'customers.update';
    case Deactivate = 'customers.deactivate';
    case Reactivate = 'customers.reactivate';
    case Import = 'customers.import';
    case Export = 'customers.export';
    case ViewSensitive = 'customers.view_sensitive';
    case ManageCredit = 'customers.manage_credit';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Consultar clientes',
            self::Create => 'Cadastrar clientes',
            self::Update => 'Editar clientes, endereços e contatos',
            self::Deactivate => 'Inativar clientes',
            self::Reactivate => 'Reativar clientes',
            self::Import => 'Importar clientes',
            self::Export => 'Exportar clientes',
            self::ViewSensitive => 'Ver documento e data de nascimento',
            self::ManageCredit => 'Ver e alterar limite de crédito',
        };
    }

    /** @return list<array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(fn (self $permission): array => ['key' => $permission->value, 'label' => $permission->label()], self::cases());
    }
}
