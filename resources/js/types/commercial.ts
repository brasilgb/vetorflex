export type CatalogApplication = {
    identifier: string;
    name: string;
    tagline: string | null;
    description: string;
    icon: string | null;
    category: string;
    benefits: string[];
    monthly_price: string | null;
    dependencies: string[];
    optional_integrations: string[];
};

export type CommercialContact = {
    email: string | null;
    whatsapp: string | null;
    company: string;
};

export type SubscriptionSummary = {
    status: 'trial' | 'active' | 'expired';
    label: string;
    trial_started_at: string | null;
    trial_ends_at: string | null;
    days_left: number | null;
    blocks_writes: boolean;
};

export type ApplicationQuote = {
    selected: string[];
    required: string[];
    modules: string[];
    total: string | null;
    unpriced: string[];
};
