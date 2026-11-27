import type { Option } from './products';
import type { PaymentScheduleRow } from './purchases';

export type SaleAbilities = {
    changePrices: boolean;
    applyDiscounts: boolean;
    customItems: boolean;
    registerCustomers: boolean;
    registerItems: boolean;
    viewCost: boolean;
    quickSale: boolean;
};

export type SaleCustomer = {
    id: number;
    name: string;
    trade_name: string | null;
    document: string | null;
    phone: string | null;
    email: string | null;
    is_active: boolean;
    addresses: { id: number; text: string }[];
};

/** Custo médio só chega a quem tem `sales.view_cost`; saldo, só com o estoque habilitado. */
export type SellableItem = {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    unit: { code: string; allows_fraction: boolean };
    price: string | null;
    is_service: boolean;
    controls_stock: boolean;
    available: string | null;
    average_cost: string | null;
};

export type SaleItem = {
    id: number;
    product_variant_id: number | null;
    position: number;
    description: string;
    sku: string | null;
    unit_code: string;
    allows_fraction: boolean;
    is_service: boolean;
    is_custom: boolean;
    controls_stock: boolean;
    quantity: string;
    catalog_price: string | null;
    unit_price: string;
    discount_percent: string | null;
    discount: string;
    total: string;
    notes: string | null;
};

export type SaleOrderItem = SaleItem & {
    net_total: string | null;
    fulfilled_quantity: string;
    returned_quantity: string;
    pending_quantity: string;
};

export type StatusChange = {
    id: number;
    from: string | null;
    to: string;
    reason: string | null;
    user_name: string | null;
    occurred_at: string;
};

type SaleHeader = {
    customer_party_id: number | null;
    customer_snapshot: Record<string, string | null> | null;
    seller_id: number | null;
    user_name: string | null;
    commercial_terms: string | null;
    notes: string | null;
    items_total: string;
    discount: string;
    freight: string;
    other_costs: string;
    payment_condition: Option;
    payment_method: Option | null;
    payment_notes: string | null;
    payment_schedules: PaymentScheduleRow[];
    history: StatusChange[];
};

export type SaleQuoteSummary = {
    id: number;
    number: number;
    status: Option;
    customer: string | null;
    seller_name: string | null;
    issue_date: string;
    valid_until: string;
    is_overdue: boolean;
    total: string;
};

export type SaleQuote = SaleQuoteSummary &
    SaleHeader & {
        items: SaleItem[];
        issued_at: string | null;
        decided_at: string | null;
        order: { id: number; number: number } | null;
    };

export type SaleOrderSummary = {
    id: number;
    number: number;
    status: Option;
    origin: Option;
    origin_reference: string | null;
    customer: string | null;
    seller_name: string | null;
    order_date: string;
    payment_method: string | null;
    is_quick: boolean;
    total: string;
};

export type SaleMargin = {
    revenue: string;
    cost: string | null;
    margin: string | null;
    percent: string | null;
    is_complete: boolean;
};

export type SaleOrder = SaleOrderSummary &
    SaleHeader & {
        sale_quote: { id: number; number: number } | null;
        external_reference: string | null;
        expected_date: string | null;
        delivery_method: Option;
        delivery_address: {
            party_address_id: number | null;
            text: string;
        } | null;
        internal_notes: string | null;
        reserves_stock: boolean;
        confirmed_at: string | null;
        completed_at: string | null;
        balance_closed_at: string | null;
        items: SaleOrderItem[];
        margin: SaleMargin | null;
    };

export type SaleFulfillment = {
    id: number;
    number: number;
    status: Option;
    method: Option;
    fulfilled_at: string;
    notes: string | null;
    revenue: string;
    cost_total: string | null;
    user_name: string | null;
    reversed_at: string | null;
    reversed_by_name: string | null;
    reversal_reason: string | null;
    has_returns: boolean;
    order: { id: number; number: number; customer: string | null } | null;
    items: {
        id: number;
        sale_order_item_id: number;
        description: string;
        unit_code: string;
        allows_fraction: boolean;
        quantity: string;
        returned_quantity: string;
        revenue: string;
        moved_stock: boolean;
        unit_cost?: string | null;
        cost_total?: string | null;
    }[];
};

export type SaleReturn = {
    id: number;
    number: number;
    returned_at: string;
    reason: string;
    restock: boolean;
    total_value: string;
    cost_total: string | null;
    user_name: string | null;
    fulfillment_number: number;
    order: { id: number; number: number; customer: string | null } | null;
    items: {
        description: string;
        unit_code: string;
        quantity: string;
        historical_value: string;
        restocked: boolean;
    }[];
};

export type SaleIndicators = {
    period: { from: string; until: string };
    sold_value: string;
    orders_count: number;
    average_ticket: string | null;
    returned_value: string;
    quotes_issued: number;
    quotes_approved: number;
    conversion_rate: string | null;
    pending_count: number;
    completed_count: number;
    cancelled_count: number;
    top_products: {
        product_variant_id: number | null;
        description: string;
        unit_code: string;
        quantity: string;
        value: string;
    }[];
    top_customers: {
        customer_party_id: number;
        name: string;
        orders: number;
        value: string;
    }[];
    by_seller: {
        seller_id: number | null;
        name: string;
        orders: number;
        value: string;
    }[];
    monthly: { month: string; sold: string; orders: number }[];
    margin: {
        revenue: string;
        cost: string;
        margin: string;
        percent: string | null;
        items_without_cost: number;
    } | null;
};
