export type PosTerminal = {
    id: number;
    name: string;
    code: string;
    location: string | null;
    is_active: boolean;
    receipt_width: number;
    operators: { id: number; name: string }[];
    open_session: { id: number; number: number; user_name: string; user_id: number | null } | null;
};

export type PosSessionData = {
    id: number;
    number: number;
    status: 'open' | 'closed';
    status_label: string;
    terminal: { id: number; name: string; code: string; receipt_width: number };
    user_id: number | null;
    user_name: string;
    opening_amount: string;
    opening_notes: string | null;
    opened_at: string;
    is_blind: boolean;
    closed_at: string | null;
    closed_by_name: string | null;
    expected_cash: string | null;
    counted_cash: string | null;
    divergence: string | null;
    closing_notes: string | null;
};

export type PosSaleData = {
    id: number;
    status: 'suspended' | 'completed' | 'cancelled';
    status_label: string;
    order_id: number | null;
    order_number: number | null;
    customer_name: string | null;
    terminal: string;
    session_number: number;
    user_name: string;
    label: string | null;
    total: string | null;
    received_total: string;
    term_total: string;
    change_total: string;
    refunded_total: string;
    authorized_by_name: string | null;
    items_count: number;
    suspended_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    cancelled_by_name: string | null;
    reprints: number;
};

export type PosSessionSummary = {
    opening: string;
    sales_count: number;
    sales_total: string;
    received_total: string;
    term_total: string;
    change_total: string;
    by_method: { method: string; label: string; received: string; term: string }[];
    supplies: string;
    withdrawals: string;
    adjustments: string;
    cash_refunds: string;
    refunds_total: string;
    returns_count: number;
    cancelled_count: number;
    suspended_count: number;
    cash_received: string;
    expected_cash?: string;
    post_close_adjustments: string;
};

export type PosMovement = {
    id: number;
    type: string;
    type_label: string;
    amount: string;
    reason: string;
    user_name: string;
    authorized_by_name: string | null;
    is_post_close: boolean;
    occurred_at: string;
};

export type PosRefundData = {
    id: number;
    return_number: number;
    method_label: string;
    amount: string;
    return_value: string;
    user_name: string;
    authorized_by_name: string | null;
    created_at: string | null;
};
