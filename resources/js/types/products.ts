export type Option = { value: string; label: string };

export type NamedRecord = { id: number; name: string };

export type UnitOption = {
    id: number;
    code: string;
    name: string;
    allows_fraction: boolean;
};

export type Product = {
    id: number;
    name: string;
    short_description: string | null;
    description: string | null;
    internal_code: string | null;
    product_category_id: number | null;
    product_brand_id: number | null;
    unit_of_measure_id: number;
    manufacturer_reference: string | null;
    tracks_inventory: boolean;
    ncm: string | null;
    cest: string | null;
    merchandise_origin: string | null;
    notes: string | null;
    is_active: boolean;
    type: 'simple' | 'variable' | 'service';
    type_label: string;
    category: string | null;
    brand: string | null;
    unit: { code: string; name: string; allows_fraction: boolean };
    controls_stock: boolean;
};

/** Custos e saldos só são enviados a quem possui a permissão correspondente. */
export type ProductVariant = {
    id: number;
    label: string | null;
    sku: string | null;
    barcode: string | null;
    barcode_type: string | null;
    sale_price: string | null;
    promotional_price: string | null;
    minimum_stock: string;
    weight: string | null;
    height: string | null;
    width: string | null;
    length: string | null;
    is_default: boolean;
    is_active: boolean;
    cost_price?: string | null;
    margin?: string | null;
    markup?: string | null;
    balance?: string;
    is_low_stock?: boolean;
};

export type ProductImage = {
    id: number;
    product_variant_id: number | null;
    original_name: string;
    is_primary: boolean;
    url: string;
};

export type PriceChange = {
    id: number;
    field: string;
    old_value: string | null;
    new_value: string | null;
    user_name: string | null;
    variant: string | null;
    changed_at: string;
};

export type Movement = {
    id: number;
    product_variant_id: number;
    quantity: string;
    balance_before: string;
    balance_after: string;
    reference_type: string | null;
    reference_id: string | null;
    reversal_of_id: number | null;
    user_name: string | null;
    reason: string | null;
    type: string;
    type_label: string;
    origin_label: string;
    occurred_at: string;
    item: string;
    product_id: number;
    sku: string | null;
    unit: string;
    is_reversed: boolean;
};

export type SellableUnit = {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    unit: { code: string; allows_fraction: boolean };
    controls_stock: boolean;
    balance: string | null;
};

export type ProductFormOptions = {
    types: Option[];
    barcodeTypes: Option[];
    categories: NamedRecord[];
    brands: NamedRecord[];
    units: UnitOption[];
};

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};
