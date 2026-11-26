export type FinanceKind = 'receivable' | 'payable';

export type FinanceOption = { id: number; name: string };

export type FinanceCategory = {
    id: number;
    kind: 'income' | 'expense';
    name: string;
    full_name: string;
    parent_id: number | null;
    is_active: boolean;
};

export type FinanceParty = { id: number; name: string; document: string | null };

export type FinanceTitleSummary = {
    id: number;
    number: number;
    kind: FinanceKind;
    status: 'pending' | 'partial' | 'settled' | 'cancelled' | 'renegotiated';
    status_label: string;
    is_overdue: boolean;
    origin: string;
    origin_label: string;
    description: string;
    party_name: string | null;
    category: string | null;
    competence_date: string;
    due_date: string;
    amount: string;
    settled: string;
    open: string;
    installment: string | null;
    source_label: string | null;
};

export type FinanceSettlement = {
    id: number;
    status: 'confirmed' | 'reversed';
    status_label: string;
    is_external: boolean;
    account: string | null;
    settled_at: string;
    method: string | null;
    principal: string;
    interest: string;
    fine: string;
    discount: string;
    amount: string;
    notes: string | null;
    user_name: string | null;
    reversed_by_name: string | null;
    reversal_reason: string | null;
};

export type FinanceTitle = FinanceTitleSummary & {
    original_amount: string;
    adjusted_amount: string;
    cancelled_amount: string;
    interest_total: string;
    fine_total: string;
    discount_total: string;
    party_id: number | null;
    finance_category_id: number | null;
    notes: string | null;
    cancellation_reason: string | null;
    is_editable: boolean;
    has_settlements: boolean;
    is_integration: boolean;
    user_name: string | null;
    settlements: FinanceSettlement[];
    events: {
        id: number;
        event: string;
        description: string;
        amount: string | null;
        user_name: string | null;
        occurred_at: string;
    }[];
    attachments: {
        id: number;
        original_name: string;
        size: number;
        user_name: string | null;
        created_at: string | null;
    }[];
};

export type FinanceAccount = {
    id: number;
    name: string;
    type: string;
    type_label: string;
    institution: string | null;
    opening_balance: string;
    opening_date: string;
    is_active: boolean;
    notes: string | null;
    balance: string;
};

export type FinanceMovement = {
    id: number;
    account: string | null;
    type: string;
    type_label: string;
    amount: string;
    is_operational: boolean;
    occurred_at: string;
    description: string;
    category: string | null;
    method: string | null;
    user_name: string | null;
    title_id: number | null;
    is_reversal: boolean;
    reconciled_at: string | null;
    reconciled_by_name: string | null;
};

export type FinanceTransfer = {
    id: number;
    from: string | null;
    to: string | null;
    amount: string;
    transferred_at: string;
    description: string | null;
    status: 'confirmed' | 'reversed';
    user_name: string | null;
    reversed_by_name: string | null;
    reversal_reason: string | null;
};

export type FinanceRecurrence = {
    id: number;
    kind: FinanceKind;
    description: string;
    category: string | null;
    amount: string;
    frequency_label: string;
    start_date: string;
    end_date: string | null;
    generated_until: string | null;
    ended_at: string | null;
    titles_count: number;
};

export type CashFlowRow = {
    period: string;
    label: string;
    realized_in: string;
    realized_out: string;
    forecast_in: string;
    forecast_out: string;
    balance: string;
};

export type CashFlowData = {
    opening: string;
    closing: string;
    granularity: string;
    account_id: number | null;
    rows: CashFlowRow[];
    totals: Record<string, string>;
};

export type FinanceDashboardData = {
    period: { from: string; until: string };
    account_id: number | null;
    available_balance: string;
    to_receive: string;
    to_pay: string;
    overdue_receivable: string;
    overdue_payable: string;
    due_soon_receivable: string;
    due_soon_payable: string;
    received: string;
    paid: string;
    result: string;
    monthly: { month: string; income: string; expense: string }[];
    projection: CashFlowData;
    next_due: {
        id: number;
        number: number;
        kind: FinanceKind;
        description: string;
        party_name: string | null;
        due_date: string;
        open: string;
        is_overdue: boolean;
    }[];
    expenses_by_category: { category: string; total: string }[];
    accounts: {
        id: number;
        name: string;
        type: string;
        is_active: boolean;
        balance: string;
    }[];
};

export type FinanceReportTable = {
    type: string;
    title: string;
    subtitle: string;
    columns: { key: string; label: string; align?: 'right' }[];
    rows: Record<string, string | null>[];
    totals: Record<string, string>;
};
