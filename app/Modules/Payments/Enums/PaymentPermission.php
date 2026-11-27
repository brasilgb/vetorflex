<?php

namespace App\Modules\Payments\Enums;

/** Permissões individuais do módulo Pagamentos (`payments`). */
enum PaymentPermission: string
{
    case View = 'payments.view';
    case Create = 'payments.create';
    case Cancel = 'payments.cancel';
    case Refund = 'payments.refund';
    case ViewReports = 'payments.view_reports';
    case Export = 'payments.export';
    case ManageProviders = 'payments.manage_providers';
    case Reconcile = 'payments.reconcile';
    case Reprocess = 'payments.reprocess';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Consultar cobranças',
            self::Create => 'Criar cobranças',
            self::Cancel => 'Cancelar cobranças',
            self::Refund => 'Solicitar estornos (devoluções)',
            self::ViewReports => 'Consultar relatórios de pagamentos',
            self::Export => 'Exportar cobranças',
            self::ManageProviders => 'Configurar provedores e credenciais',
            self::Reconcile => 'Conciliar pagamentos com o provedor',
            self::Reprocess => 'Reprocessar eventos de webhook',
        };
    }

    /** @return list<array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(fn (self $permission): array => ['key' => $permission->value, 'label' => $permission->label()], self::cases());
    }
}
