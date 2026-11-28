import { parseDecimal, toDecimalInput } from '@/lib/decimal';
import { fromCents, toCents } from '@/lib/money';
import { uuid } from '@/lib/uuid';

/** Item do catálogo compartilhado como chega das buscas do caixa. */
export type PosCatalogItem = {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    unit: { code: string; allows_fraction: boolean };
    price: string | null;
    is_service: boolean;
    controls_stock: boolean;
    available: string | null;
};

export type PosCartItem = {
    key: string;
    product_variant_id: number | null;
    name: string;
    unit_code: string;
    allows_fraction: boolean;
    is_service: boolean;
    quantity: string;
    unit_price: string;
    price_changed: boolean;
    discount: string;
    available: string | null;
};

export type PosCustomer = { id: number; name: string; document: string | null };

export type PosCart = {
    items: PosCartItem[];
    discount: string;
    customer: PosCustomer | null;
    suspendedSaleId: number | null;
    idempotencyKey: string;
};

export const emptyCart = (): PosCart => ({
    items: [],
    discount: '',
    customer: null,
    suspendedSaleId: null,
    idempotencyKey: uuid(),
});

const QUANTITY_SCALE = 10000n;

/** Quantidade com até 4 casas em décimos de milésimo, sem ponto flutuante. */
export function toQuantityUnits(value: string): bigint {
    const match = /^(\d+)(?:\.(\d{1,4}))?$/.exec(parseDecimal(value) || '0');

    return match === null
        ? 0n
        : BigInt(match[1] + (match[2] ?? '').padEnd(4, '0'));
}

/** Total bruto do item (quantidade × preço) arredondado em centavos, como no servidor. */
export function grossCents(item: PosCartItem): bigint {
    const product = toQuantityUnits(item.quantity) * toCents(item.unit_price);

    return (product + QUANTITY_SCALE / 2n) / QUANTITY_SCALE;
}

export function itemCents(item: PosCartItem): bigint {
    const total = grossCents(item) - toCents(item.discount);

    return total < 0n ? 0n : total;
}

/** Totais apenas para exibição; o servidor recalcula tudo na finalização. */
export function cartTotals(cart: PosCart): {
    gross: string;
    items: string;
    discount: string;
    total: string;
    discountPercent: string;
} {
    const gross = cart.items.reduce((sum, item) => sum + grossCents(item), 0n);
    const items = cart.items.reduce((sum, item) => sum + itemCents(item), 0n);
    const general = toCents(cart.discount);
    const total = items - general < 0n ? 0n : items - general;
    const discount = gross - total;
    const percent = gross === 0n ? 0n : (discount * 10000n) / gross;

    return {
        gross: fromCents(gross),
        items: fromCents(items),
        discount: fromCents(discount),
        total: fromCents(total),
        discountPercent: fromCents(percent),
    };
}

export function catalogToCartItem(
    item: PosCatalogItem,
    quantity = '1',
): PosCartItem {
    return {
        key: uuid(),
        product_variant_id: item.id,
        name: item.name,
        unit_code: item.unit.code,
        allows_fraction: item.unit.allows_fraction,
        is_service: item.is_service,
        quantity,
        unit_price: toDecimalInput(item.price ?? '0', 2),
        price_changed: false,
        discount: '',
        available: item.available,
    };
}

/** Inclui o item ou soma a quantidade quando a mesma unidade comercializável já está no carrinho. */
export function addToCart(cart: PosCart, item: PosCartItem): PosCart {
    const existing =
        item.product_variant_id === null
            ? undefined
            : cart.items.find(
                  (row) => row.product_variant_id === item.product_variant_id,
              );

    if (existing === undefined) {
        return { ...cart, items: [...cart.items, item] };
    }

    const sum =
        toQuantityUnits(existing.quantity) + toQuantityUnits(item.quantity);
    const quantity =
        `${sum / QUANTITY_SCALE}.${(sum % QUANTITY_SCALE).toString().padStart(4, '0')}`.replace(
            /\.?0+$/,
            '',
        );

    return {
        ...cart,
        items: cart.items.map((row) =>
            row.key === existing.key ? { ...row, quantity } : row,
        ),
    };
}

/** Itens no formato da API. Preço do catálogo só é enviado quando alterado pelo operador. */
export function cartPayload(cart: PosCart): Record<string, unknown> {
    return {
        customer_party_id: cart.customer?.id ?? null,
        customer_name: cart.customer?.name ?? null,
        discount: parseDecimal(cart.discount) || '0',
        suspended_sale_id: cart.suspendedSaleId,
        estimated_total: cartTotals(cart).total,
        items: cart.items.map((item) => ({
            product_variant_id: item.product_variant_id,
            name: item.name,
            description: item.product_variant_id === null ? item.name : null,
            unit_code: item.product_variant_id === null ? item.unit_code : null,
            allows_fraction:
                item.product_variant_id === null ? item.allows_fraction : null,
            is_service:
                item.product_variant_id === null ? item.is_service : null,
            quantity: parseDecimal(item.quantity),
            unit_price:
                item.product_variant_id === null || item.price_changed
                    ? parseDecimal(item.unit_price)
                    : null,
            discount: parseDecimal(item.discount) || '0',
        })),
    };
}

/** Carrinho devolvido pelo servidor ao recuperar uma venda suspensa. */
export function cartFromSuspended(
    saleId: number,
    stored: {
        items?: Record<string, unknown>[];
        discount?: string | null;
        customer_party_id?: number | null;
        customer_name?: string | null;
    },
): PosCart {
    return {
        items: (stored.items ?? []).map((row) => ({
            key: uuid(),
            product_variant_id:
                (row.product_variant_id as number | null) ?? null,
            name: String(row.name ?? row.description ?? 'Item'),
            unit_code: String(row.unit_code ?? 'UN'),
            allows_fraction: Boolean(row.allows_fraction),
            is_service: Boolean(row.is_service),
            quantity: String(row.quantity ?? '1'),
            unit_price: toDecimalInput(String(row.unit_price ?? '0'), 2),
            price_changed:
                row.product_variant_id !== null &&
                row.product_variant_id !== undefined &&
                row.unit_price !== null &&
                row.unit_price !== undefined,
            discount:
                row.discount && row.discount !== '0'
                    ? String(row.discount)
                    : '',
            available: null,
        })),
        discount:
            stored.discount && stored.discount !== '0' ? stored.discount : '',
        customer:
            stored.customer_party_id === null ||
            stored.customer_party_id === undefined
                ? null
                : {
                      id: stored.customer_party_id,
                      name: stored.customer_name ?? 'Cliente',
                      document: null,
                  },
        suspendedSaleId: saleId,
        idempotencyKey: uuid(),
    };
}

/** Preservação temporária do carrinho no navegador (queda de conexão ou recarga da página). */
export function loadCart(sessionId: number): PosCart | null {
    try {
        const raw = window.localStorage.getItem(`pos-cart:${sessionId}`);

        return raw === null ? null : (JSON.parse(raw) as PosCart);
    } catch {
        return null;
    }
}

export function saveCart(sessionId: number, cart: PosCart): void {
    try {
        if (cart.items.length === 0 && cart.customer === null) {
            window.localStorage.removeItem(`pos-cart:${sessionId}`);
        } else {
            window.localStorage.setItem(
                `pos-cart:${sessionId}`,
                JSON.stringify(cart),
            );
        }
    } catch {
        // Sem armazenamento disponível: o carrinho continua só na memória da página.
    }
}
