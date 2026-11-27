export type PaymentCharge = {
    id: number;
    number: number;
    status: string;
    status_label: string;
    method: string;
    provider: string | null;
    provider_name: string | null;
    environment: string | null;
    amount: string;
    paid_amount: string;
    refunded_amount: string;
    open_amount: string;
    fee_amount: string | null;
    net_amount: string | null;
    description: string;
    reference: string | null;
    due_date: string;
    expires_at: string | null;
    payer_name: string | null;
    payer_document: string | null;
    origin: string;
    origin_label: string;
    origin_type: string | null;
    origin_id: number | null;
    origin_reference: string | null;
    provider_charge_id: string | null;
    provider_status: string | null;
    paid_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    failure_reason: string | null;
    last_synced_at: string | null;
    created_at: string | null;
    created_by_name: string | null;
};

export type PaymentPix = {
    pix_copy_paste: string | null;
    qr_code_image: string | null;
    payment_url: string | null;
};

export type PaymentTransaction = {
    id: number;
    provider_transaction_id: string;
    amount: string;
    fee_amount: string | null;
    net_amount: string | null;
    refunded_amount: string;
    refundable: string;
    paid_at: string;
    payer_name: string | null;
};

export type PaymentRefundData = {
    id: number;
    amount: string;
    status: string;
    status_label: string;
    reason: string;
    failure_reason: string | null;
    requested_by_name: string | null;
    created_at: string | null;
};

export type PaymentChargeEventData = {
    id: number;
    event: string;
    description: string;
    user_name: string | null;
    created_at: string | null;
};

export type PaymentAccount = {
    id: number;
    provider: string;
    provider_name: string;
    environment: 'homologation' | 'production';
    name: string;
    pix_key: string | null;
    methods: string[];
    expiration_seconds: number;
    finance_account_id: number | null;
    is_active: boolean;
    status: string;
    status_label: string;
    last_tested_at: string | null;
    last_error: string | null;
    webhook_registered_at: string | null;
    webhook_url: string;
    has_client_id: boolean;
    has_client_secret: boolean;
    has_certificate: boolean;
    client_id_hint: string | null;
};

export type PaymentWebhookEventData = {
    id: number;
    provider: string;
    status: string;
    status_label: string;
    attempts: number;
    error: string | null;
    charges: number;
    created_at: string | null;
    processed_at: string | null;
};

export type PaymentIndicators = {
    received: string;
    gross: string;
    refunded: string;
    fees: string | null;
    net: string | null;
    pending: string;
    overdue: string;
    issued: number;
    paid: number;
    collection_rate: string | null;
    by_method: { key: string; total: string; count: number }[];
    by_provider: { key: string; total: string; count: number }[];
    by_origin: { key: string; total: string; count: number }[];
    failures: number;
};
