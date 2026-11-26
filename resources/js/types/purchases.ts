import type { Option } from './products';

/** Campos monetários só chegam a quem tem `purchases.view_cost`. */
export type PurchaseOrderSummary = {
    id: number;
    number: number;
    status: Option;
    supplier: string;
    order_date: string;
    expected_date: string | null;
    is_late: boolean;
    is_quick: boolean;
    user_name: string | null;
    total?: string;
};

export type PurchaseOrderItem = {
    id: number;
    product_variant_id: number;
    position: number;
    description: string;
    sku: string | null;
    supplier_code: string | null;
    unit_code: string;
    allows_fraction: boolean;
    is_service: boolean;
    is_free: boolean;
    quantity: string;
    received_quantity: string;
    pending_quantity: string;
    unit_cost?: string;
    discount?: string;
    total?: string;
    acquisition_total?: string | null;
};

export type PaymentScheduleRow = {
    sequence: number;
    is_entry: boolean;
    due_date: string;
    amount: string;
    method: string | null;
    method_value: string | null;
};

export type PurchaseOrder = PurchaseOrderSummary & {
    supplier_profile_id: number;
    supplier_snapshot: Record<string, string | null>;
    external_reference: string | null;
    notes: string | null;
    confirmed_at: string | null;
    balance_closed_at: string | null;
    payment_condition: Option;
    payment_method: Option | null;
    payment_notes: string | null;
    items_total?: string;
    discount?: string;
    freight?: string;
    insurance?: string;
    other_costs?: string;
    items: PurchaseOrderItem[];
    payment_schedules: PaymentScheduleRow[];
    history: {
        id: number;
        from: string | null;
        to: string;
        reason: string | null;
        user_name: string | null;
        occurred_at: string;
    }[];
};

export type PurchaseReceiptItem = {
    id: number;
    purchase_order_item_id: number;
    description: string;
    unit_code: string;
    allows_fraction: boolean;
    is_service: boolean;
    quantity: string;
    returned_quantity: string;
    returnable_quantity: string;
    divergence: string | null;
    acquisition_total?: string;
    unit_cost?: string;
    purchase_unit_cost?: string;
};

export type PurchaseReceipt = {
    id: number;
    number: number;
    purchase_order_id: number;
    document_reference: string | null;
    notes: string | null;
    user_name: string | null;
    reversed_by_name: string | null;
    reversal_reason: string | null;
    status: Option;
    is_reversed: boolean;
    received_at: string;
    reversed_at: string | null;
    created_at: string | null;
    total_cost?: string;
    items: PurchaseReceiptItem[];
    order_number?: number;
    supplier?: string;
};

export type PurchaseReturn = {
    id: number;
    number: number;
    purchase_order_id: number;
    purchase_receipt_id: number;
    receipt_number: number;
    reason: string;
    user_name: string | null;
    returned_at: string;
    total_value?: string;
    items: {
        id: number;
        description: string;
        unit_code: string;
        quantity: string;
        historical_value?: string;
        stock_value?: string | null;
    }[];
    order_number?: number;
    supplier?: string;
};

export type SupplierSummary = {
    id: number;
    party_id: number;
    name: string;
    trade_name: string | null;
    type: 'individual' | 'company';
    document: string | null;
    email: string | null;
    phone: string | null;
    internal_code: string | null;
    is_active: boolean;
    is_customer: boolean | null;
};

export type PurchasableItem = {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    unit: { code: string; allows_fraction: boolean };
    controls_stock: boolean;
    is_service: boolean;
    supplier_code: string | null;
    last_cost: string | null;
};
