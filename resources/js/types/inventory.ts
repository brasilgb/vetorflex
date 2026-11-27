export type StockItem = {
    id: number;
    product_id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    internal_code: string | null;
    category: string | null;
    unit: { code: string; allows_fraction: boolean };
    balance: string;
    reserved: string;
    available: string;
    warehouse_quantity: string | null;
    minimum_stock: string;
    maximum_stock: string | null;
    is_low_stock: boolean;
    is_above_max: boolean;
    is_active: boolean;
    cost_price: string | null;
    last_movement_at: string | null;
};

export type StockMovement = {
    id: number;
    occurred_at: string;
    type: string;
    type_label: string;
    origin: string;
    origin_label: string;
    quantity: string;
    balance_after: string;
    warehouse: string | null;
    warehouse_balance_after: string | null;
    item: string;
    variant_id: number;
    sku: string | null;
    unit: string;
    user_name: string | null;
    reason: string | null;
    reference: string | null;
    unit_cost: string | null;
    is_reversed: boolean;
    is_reversible: boolean;
};

export type StockWarehouseOption = {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
};

export type StockWarehouse = {
    id: number;
    name: string;
    code: string;
    location: string | null;
    is_default: boolean;
    is_active: boolean;
    items: number;
    quantity: string;
};

export type StockTransfer = {
    id: number;
    number: number;
    from: string;
    to: string;
    user_name: string | null;
    reason: string | null;
    transferred_at: string;
    items: { item: string; quantity: string; unit: string }[];
};

export type StockCount = {
    id: number;
    number: number;
    warehouse: string;
    status: 'open' | 'approved' | 'cancelled';
    status_label: string;
    description: string | null;
    created_by_name: string | null;
    created_at: string | null;
    approved_by_name: string | null;
    approved_at: string | null;
    cancelled_by_name: string | null;
    cancellation_reason: string | null;
    items_count: number | null;
    counted_count: number | null;
};

export type StockReportTable = {
    type: string;
    title: string;
    columns: { key: string; label: string; align?: 'right' }[];
    rows: Record<string, string | null>[];
};
