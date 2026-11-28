<?php

namespace App\Modules\Finance\Enums;

enum FinancePermission: string
{
    case ViewDashboard = 'finance.view_dashboard';
    case ViewAccounts = 'finance.view_accounts';
    case ManageAccounts = 'finance.manage_accounts';
    case ManageCategories = 'finance.manage_categories';
    case ViewPayables = 'finance.view_payables';
    case ManagePayables = 'finance.manage_payables';
    case Pay = 'finance.pay';
    case ViewReceivables = 'finance.view_receivables';
    case ManageReceivables = 'finance.manage_receivables';
    case Receive = 'finance.receive';
    case CancelTitles = 'finance.cancel_titles';
    case ReverseSettlements = 'finance.reverse_settlements';
    case Transfer = 'finance.transfer';
    case AdjustBalances = 'finance.adjust_balances';
    case ManageRecurrences = 'finance.manage_recurrences';
    case Reconcile = 'finance.reconcile';
    case ImportDocuments = 'finance.import_documents';
    case ViewReports = 'finance.view_reports';
    case Export = 'finance.export';

    public function label(): string
    {
        return match ($this) {
            self::ViewDashboard => 'Consultar a visão geral financeira',
            self::ViewAccounts => 'Consultar contas, saldos e movimentações',
            self::ManageAccounts => 'Gerenciar contas financeiras',
            self::ManageCategories => 'Gerenciar categorias',
            self::ViewPayables => 'Consultar contas a pagar',
            self::ManagePayables => 'Cadastrar e editar contas a pagar',
            self::Pay => 'Registrar pagamentos',
            self::ViewReceivables => 'Consultar contas a receber',
            self::ManageReceivables => 'Cadastrar e editar contas a receber',
            self::Receive => 'Registrar recebimentos',
            self::CancelTitles => 'Cancelar e renegociar títulos',
            self::ReverseSettlements => 'Estornar baixas',
            self::Transfer => 'Transferir entre contas',
            self::AdjustBalances => 'Ajustar saldos',
            self::ManageRecurrences => 'Gerenciar recorrências',
            self::Reconcile => 'Conciliar e desfazer conciliação',
            self::ImportDocuments => 'Importar vendas e compras',
            self::ViewReports => 'Consultar relatórios e fluxo de caixa',
            self::Export => 'Exportar dados financeiros',
        };
    }

    /** @return list<array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(fn (self $permission): array => ['key' => $permission->value, 'label' => $permission->label()], self::cases());
    }

    /** Permissão de consulta de cada tipo de título. */
    public static function viewFor(TitleKind $kind): self
    {
        return $kind === TitleKind::Receivable ? self::ViewReceivables : self::ViewPayables;
    }

    public static function manageFor(TitleKind $kind): self
    {
        return $kind === TitleKind::Receivable ? self::ManageReceivables : self::ManagePayables;
    }

    public static function settleFor(TitleKind $kind): self
    {
        return $kind === TitleKind::Receivable ? self::Receive : self::Pay;
    }
}
