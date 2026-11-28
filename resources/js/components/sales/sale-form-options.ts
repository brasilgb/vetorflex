import type { NamedRecord, Option, SaleAbilities, SaleCustomer } from '@/types';

/** Dados auxiliares enviados pelo servidor aos formulários de orçamento e venda. */
export type SaleFormOptions = {
    conditions: Option[];
    methods: Option[];
    deliveryMethods: Option[];
    sellers: NamedRecord[];
    abilities: SaleAbilities;
    allowsAnonymous: boolean;
    stockEnabled: boolean;
    today: string;
    defaultValidUntil: string;
    currentUserId: number;
    selectedCustomer: SaleCustomer | null;
};
