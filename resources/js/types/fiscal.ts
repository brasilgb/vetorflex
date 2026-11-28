export type FiscalDocument = {
    id: number;
    local_number: number;
    type: 'nfse' | 'nfe' | 'nfce';
    type_label: string;
    status: string;
    status_label: string;
    origin: string;
    origin_label: string;
    origin_type: string | null;
    origin_id: number | null;
    origin_reference: string | null;
    environment: string | null;
    provider: string | null;
    receiver_name: string | null;
    receiver_document: string | null;
    total: string;
    number: string | null;
    series: string | null;
    access_key: string | null;
    issued_at: string | null;
    created_at: string | null;
    error_message: string | null;
    has_files: boolean;
};

export type FiscalAddress = {
    postal_code?: string | null;
    street?: string | null;
    number?: string | null;
    complement?: string | null;
    district?: string | null;
    city?: string | null;
    city_code?: string | null;
    state?: string | null;
};

export type FiscalReceiver = {
    name?: string | null;
    document?: string | null;
    state_registration?: string | null;
    municipal_registration?: string | null;
    email?: string | null;
    phone?: string | null;
    address?: FiscalAddress | null;
};

export type FiscalItem = {
    product_variant_id?: number | null;
    code?: string | null;
    description: string;
    quantity: string;
    unit?: string | null;
    unit_price: string;
    discount?: string | null;
    gtin?: string | null;
    is_service?: boolean;
    ncm?: string | null;
    cest?: string | null;
    cfop?: string | null;
    origin?: string | number | null;
    icms_situation?: string | null;
    pis_situation?: string | null;
    cofins_situation?: string | null;
    service_code?: string | null;
    tax_unit?: string | null;
};

export type FiscalTaxData = {
    service_code?: string | null;
    iss_rate?: string | null;
    taxation_type?: string | null;
    city_code?: string | null;
};

export type FiscalDocumentDetail = FiscalDocument & {
    integration_id: string;
    receiver_party_id: number | null;
    receiver: FiscalReceiver | null;
    items: FiscalItem[];
    tax_data: FiscalTaxData | null;
    description: string | null;
    notes: string | null;
    payment_method: string | null;
    items_total: string;
    discount_total: string;
    authorization_protocol: string | null;
    provider_status: string | null;
    submitted_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    xml_sha256: string | null;
    pdf_sha256: string | null;
    created_by_name: string | null;
};

export type FiscalDocumentEvent = {
    id: number;
    event: string;
    description: string;
    to_status: string | null;
    user_name: string | null;
    created_at: string | null;
};

export type FiscalReadiness = {
    type: string;
    label: string;
    enabled?: boolean;
    blockers: string[];
};
