<?php

namespace App\Modules\Fiscal\Enums;

/** Permissões individuais do módulo Documentos Fiscais (`fiscal`). */
enum FiscalPermission: string
{
    case View = 'fiscal.view';
    case CreateDraft = 'fiscal.create_draft';
    case Edit = 'fiscal.edit';
    case Issue = 'fiscal.issue';
    case Cancel = 'fiscal.cancel';
    case Download = 'fiscal.download';
    case Export = 'fiscal.export';
    case ManageSettings = 'fiscal.manage_settings';
    case Reprocess = 'fiscal.reprocess';
    case ViewReports = 'fiscal.view_reports';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Consultar documentos fiscais',
            self::CreateDraft => 'Criar rascunhos',
            self::Edit => 'Editar rascunhos e dados fiscais de itens',
            self::Issue => 'Transmitir (emitir) documentos',
            self::Cancel => 'Cancelar documentos autorizados',
            self::Download => 'Baixar XML e PDF',
            self::Export => 'Exportar documentos (CSV)',
            self::ManageSettings => 'Gerenciar configuração fiscal e certificado',
            self::Reprocess => 'Consultar e reprocessar documentos pendentes',
            self::ViewReports => 'Consultar relatórios fiscais',
        };
    }

    /** @return list<array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(fn (self $permission): array => ['key' => $permission->value, 'label' => $permission->label()], self::cases());
    }
}
