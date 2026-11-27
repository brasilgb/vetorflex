import { HttpNetworkError } from '@inertiajs/core';
import { Head, Link, useHttp } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import MoneyInput from '@/components/finance/money-input';
import FormField from '@/components/form-field';
import NativeSelect from '@/components/native-select';
import AuthorizationFields, {
    type Credentials,
    credentialsPayload,
    emptyCredentials,
} from '@/components/pos/authorization-fields';
import CustomerDialog from '@/components/pos/customer-dialog';
import PaymentDialog from '@/components/pos/payment-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { formatDecimal, formatMoney, parseDecimal } from '@/lib/decimal';
import { fromCents, toCents } from '@/lib/money';
import {
    addToCart,
    cartFromSuspended,
    cartPayload,
    cartTotals,
    catalogToCartItem,
    emptyCart,
    itemCents,
    loadCart,
    type PosCart,
    type PosCatalogItem,
    saveCart,
} from '@/lib/pos-cart';
import { uuid } from '@/lib/uuid';
import { home } from '@/routes/pos';
import { store as storeMovement } from '@/routes/pos/cash-movements';
import { scan, search } from '@/routes/pos/catalog';
import { store as storeItem } from '@/routes/pos/items';
import { cancel, finalize, receipt, resume, suspend } from '@/routes/pos/sales';
import { show as sessionShow } from '@/routes/pos/sessions';
import type { Option } from '@/types';

type Session = {
    id: number;
    number: number;
    terminal: { id: number; name: string; code: string; receipt_width: number };
    user_name: string;
};

type SuspendedSale = {
    id: number;
    label: string | null;
    terminal: string;
    user_name: string;
    total: string | null;
    items_count: number;
    customer_name: string | null;
    suspended_at: string | null;
};

type Completed = {
    id: number;
    total: string;
    change: string;
    receiptUrl: string;
    orderNumber: number | null;
};

const SHORTCUTS: [string, string][] = [
    ['F2', 'Buscar produto'],
    ['F3', 'Cliente'],
    ['F4', 'Quantidade'],
    ['F6', 'Desconto'],
    ['F8', 'Remover item'],
    ['F7', 'Suspender'],
    ['F9', 'Recuperar'],
    ['F10', 'Pagamento'],
    ['Ctrl+Enter', 'Finalizar'],
];

function useOnline(): boolean {
    const [online, setOnline] = useState(() =>
        typeof navigator === 'undefined' ? true : navigator.onLine,
    );

    useEffect(() => {
        const up = () => setOnline(true);
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);

        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, []);

    return online;
}

export default function PosRegister({
    session,
    favorites,
    suspended: initialSuspended,
    paymentMethods,
    settings,
    abilities,
    stockEnabled,
    today,
}: {
    session: Session;
    favorites: PosCatalogItem[];
    suspended: SuspendedSale[];
    paymentMethods: Option[];
    settings: {
        allow_anonymous: boolean;
        max_discount_percent: string;
        allow_price_change: boolean;
        print_receipt: boolean;
        blind_close: boolean;
    };
    abilities: Record<
        | 'apply_discounts'
        | 'change_prices'
        | 'authorize'
        | 'cancel'
        | 'withdraw'
        | 'supply'
        | 'close'
        | 'register_items',
        boolean
    >;
    stockEnabled: boolean;
    today: string;
}) {
    useFlashToast();
    const online = useOnline();
    const [cart, setCart] = useState<PosCart>(
        () => loadCart(session.id) ?? emptyCart(),
    );
    const [selected, setSelected] = useState<string | null>(null);
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<PosCatalogItem[] | null>(null);
    const [notice, setNotice] = useState<{
        type: 'error' | 'info';
        message: string;
    } | null>(null);
    const [connectionLost, setConnectionLost] = useState(false);
    const [suspended, setSuspended] = useState(initialSuspended);
    const [completed, setCompleted] = useState<Completed | null>(null);
    const [dialog, setDialog] = useState<
        | null
        | 'customer'
        | 'payment'
        | 'suspend'
        | 'recover'
        | 'cancel'
        | 'cash'
        | 'custom'
    >(null);
    const [paymentKey, setPaymentKey] = useState(0);
    const [paymentErrors, setPaymentErrors] = useState<
        Record<string, string | undefined>
    >({});
    const [needsAuthorization, setNeedsAuthorization] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);
    const quantityRefs = useRef<Record<string, HTMLInputElement | null>>({});
    const discountRefs = useRef<Record<string, HTMLInputElement | null>>({});
    const lookupHttp = useHttp<
        Record<string, never>,
        PosCatalogItem[] | PosCatalogItem
    >({});
    const finalizeHttp = useHttp<
        Record<string, never>,
        {
            sale: {
                id: number;
                total: string;
                change_total: string;
                order_number: number | null;
            };
            receipt_url: string;
        }
    >({});
    const actionHttp = useHttp<Record<string, never>, Record<string, unknown>>(
        {},
    );
    const totals = useMemo(() => cartTotals(cart), [cart]);
    const selectedItem =
        cart.items.find((item) => item.key === selected) ??
        cart.items[cart.items.length - 1];
    const discountAboveLimit =
        toCents(totals.discountPercent) >
        toCents(settings.max_discount_percent);

    useEffect(() => saveCart(session.id, cart), [cart, session.id]);

    const focusSearch = useCallback(
        () => window.setTimeout(() => searchRef.current?.focus(), 0),
        [],
    );

    function networkFailure(error: unknown, action: string) {
        if (error instanceof HttpNetworkError) {
            setConnectionLost(true);
            setNotice({
                type: 'error',
                message: `Sem conexão com o servidor: ${action} NÃO foi confirmado. Os itens foram preservados; tente novamente quando a conexão voltar.`,
            });
        } else {
            setNotice({
                type: 'error',
                message: `Não foi possível ${action}. Tente novamente.`,
            });
        }
    }

    function add(item: PosCatalogItem, quantity = '1') {
        if (item.price === null) {
            setNotice({
                type: 'error',
                message: `"${item.name}" não tem preço de venda cadastrado.`,
            });

            return;
        }

        const row = catalogToCartItem(item, quantity);
        setCart((current) => addToCart(current, row));
        setSelected(
            cart.items.find(
                (existing) => existing.product_variant_id === item.id,
            )?.key ?? row.key,
        );
        setResults(null);
        setTerm('');
        setNotice(null);
        setCompleted(null);
        focusSearch();
    }

    function lookup() {
        const value = term.trim();

        if (value === '' || lookupHttp.processing) {
            return;
        }

        // "3*7891234567895" lança três unidades do código lido.
        const multiplier = /^(\d+(?:[.,]\d{1,4})?)\*(.+)$/.exec(value);
        const quantity = multiplier ? parseDecimal(multiplier[1]) : '1';
        const code = multiplier ? multiplier[2].trim() : value;

        lookupHttp
            .get(scan.url({ query: { code } }), {
                onSuccess: (item) => add(item as PosCatalogItem, quantity),
            })
            .catch((error: unknown) => {
                if (error instanceof HttpNetworkError) {
                    networkFailure(error, 'a busca');

                    return;
                }

                lookupHttp
                    .get(search.url({ query: { term: code } }), {
                        onSuccess: (found) => {
                            const list = found as PosCatalogItem[];

                            if (list.length === 1) {
                                add(list[0], quantity);
                            } else {
                                setResults(list);
                                setNotice(
                                    list.length === 0
                                        ? {
                                              type: 'info',
                                              message:
                                                  'Nenhum item encontrado. Use "Item avulso" para vender sem cadastro.',
                                          }
                                        : null,
                                );
                            }
                        },
                    })
                    .catch((searchError: unknown) =>
                        networkFailure(searchError, 'a busca'),
                    );
            });
    }

    function updateItem(
        key: string,
        changes: Partial<PosCart['items'][number]>,
    ) {
        setCart((current) => ({
            ...current,
            items: current.items.map((item) =>
                item.key === key ? { ...item, ...changes } : item,
            ),
        }));
    }

    function removeItem(key: string) {
        setCart((current) => ({
            ...current,
            items: current.items.filter((item) => item.key !== key),
        }));
        setSelected(null);
        focusSearch();
    }

    function openPayment() {
        if (cart.items.length === 0) {
            setNotice({ type: 'info', message: 'Inclua ao menos um item.' });

            return;
        }

        setPaymentErrors({});
        setNeedsAuthorization(discountAboveLimit && !abilities.authorize);
        setPaymentKey((key) => key + 1);
        setDialog('payment');
    }

    function submitPayment(
        payments: {
            method: string;
            amount: string;
            is_term: boolean;
            due_date: string | null;
        }[],
        credentials: Credentials,
    ) {
        setPaymentErrors({});
        finalizeHttp.transform(() => ({
            ...cartPayload(cart),
            payments,
            authorization: credentialsPayload(credentials),
            idempotency_key: cart.idempotencyKey,
        }));
        finalizeHttp
            .post(finalize.url(session.id), {
                onSuccess: (response) => {
                    setConnectionLost(false);
                    setDialog(null);
                    setCompleted({
                        id: response.sale.id,
                        total: response.sale.total,
                        change: response.sale.change_total,
                        receiptUrl: response.receipt_url,
                        orderNumber: response.sale.order_number,
                    });
                    setCart(emptyCart());
                    setSuspended((list) =>
                        list.filter((sale) => sale.id !== cart.suspendedSaleId),
                    );
                    setNotice(null);

                    if (settings.print_receipt) {
                        window.open(
                            `${response.receipt_url}?print=1&width=${session.terminal.receipt_width}`,
                            '_blank',
                            'noopener',
                        );
                    }

                    focusSearch();
                },
                onError: (errors) => {
                    setPaymentErrors(errors as Record<string, string>);

                    if ('authorization' in errors) {
                        setNeedsAuthorization(true);
                    }
                },
            })
            .catch((error: unknown) => {
                if (error instanceof HttpNetworkError) {
                    networkFailure(error, 'a venda');
                    setPaymentErrors({
                        connection:
                            'Sem conexão: a venda não foi confirmada. Ao tentar de novo, ela não será duplicada.',
                    });
                }
            });
    }

    useEffect(() => {
        function onKey(event: KeyboardEvent) {
            const keys: Record<string, () => void> = {
                F2: () => searchRef.current?.focus(),
                F3: () => setDialog('customer'),
                F4: () =>
                    selectedItem &&
                    quantityRefs.current[selectedItem.key]?.select(),
                F6: () =>
                    selectedItem
                        ? discountRefs.current[selectedItem.key]?.select()
                        : undefined,
                F7: () => cart.items.length > 0 && setDialog('suspend'),
                F8: () => selectedItem && removeItem(selectedItem.key),
                F9: () => setDialog('recover'),
                F10: () => openPayment(),
            };

            if (dialog !== null || !(event.key in keys)) {
                return;
            }

            event.preventDefault();
            keys[event.key]();
        }

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    });

    return (
        <>
            <Head title={`Caixa — ${session.terminal.name}`} />
            <div className="flex min-h-svh flex-col bg-background text-foreground">
                <header className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <Link
                            href={home()}
                            className="text-sm text-muted-foreground hover:underline"
                        >
                            ← PDV
                        </Link>
                        <h1 className="text-lg font-semibold">
                            {session.terminal.name} · sessão {session.number}
                        </h1>
                        <span className="text-sm text-muted-foreground">
                            {session.user_name}
                        </span>
                        <Badge
                            variant={
                                online && !connectionLost
                                    ? 'secondary'
                                    : 'destructive'
                            }
                        >
                            {online && !connectionLost
                                ? 'Online'
                                : 'Sem conexão'}
                        </Badge>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {(abilities.withdraw ||
                            abilities.supply ||
                            abilities.authorize) && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setDialog('cash')}
                            >
                                Sangria / suprimento
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={sessionShow(session.id)}>
                                {abilities.close
                                    ? 'Resumo e fechamento'
                                    : 'Resumo do caixa'}
                            </Link>
                        </Button>
                    </div>
                </header>

                {notice && (
                    <div
                        role="alert"
                        className={`px-4 py-2 text-sm ${notice.type === 'error' ? 'bg-destructive/10 text-destructive' : 'bg-muted'}`}
                    >
                        {notice.message}
                    </div>
                )}

                <main className="grid flex-1 gap-4 p-4 lg:grid-cols-[1fr_22rem]">
                    <section
                        className="flex min-w-0 flex-col gap-3"
                        aria-label="Itens da venda"
                    >
                        <form
                            className="flex gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                lookup();
                            }}
                        >
                            <Input
                                ref={searchRef}
                                autoFocus
                                autoComplete="off"
                                aria-label="Código de barras, SKU ou descrição (F2)"
                                placeholder="Código de barras, SKU ou descrição (F2) — use 3*código para quantidade"
                                className="h-12 text-lg"
                                value={term}
                                onChange={(event) =>
                                    setTerm(event.target.value)
                                }
                            />
                            <Button
                                size="lg"
                                variant="outline"
                                disabled={lookupHttp.processing}
                            >
                                Buscar
                            </Button>
                            <Button
                                size="lg"
                                type="button"
                                variant="ghost"
                                onClick={() => setDialog('custom')}
                            >
                                Item avulso
                            </Button>
                        </form>

                        {results !== null && results.length > 0 && (
                            <ul
                                className="max-h-64 divide-y overflow-y-auto rounded-md border"
                                aria-label="Resultados da busca"
                            >
                                {results.map((item) => (
                                    <li key={item.id}>
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between gap-3 p-3 text-left hover:bg-muted"
                                            onClick={() => add(item)}
                                        >
                                            <span>
                                                {item.name}
                                                <span className="block text-xs text-muted-foreground">
                                                    {[
                                                        item.sku,
                                                        item.barcode,
                                                        stockEnabled &&
                                                        item.available !== null
                                                            ? `disponível ${formatDecimal(item.available)}`
                                                            : null,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                            </span>
                                            <span className="font-medium whitespace-nowrap">
                                                {formatMoney(item.price)}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {favorites.length > 0 &&
                            cart.items.length < 3 &&
                            results === null && (
                                <div
                                    className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-6"
                                    aria-label="Favoritos"
                                >
                                    {favorites.map((item) => (
                                        <button
                                            key={item.id}
                                            type="button"
                                            className="min-h-16 rounded-md border p-2 text-left text-sm hover:bg-muted"
                                            onClick={() => add(item)}
                                        >
                                            <span className="line-clamp-2 font-medium">
                                                {item.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {formatMoney(item.price)}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}

                        <div className="flex-1 overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-2">Item</th>
                                        <th className="w-28 p-2 text-right">
                                            Qtd. (F4)
                                        </th>
                                        <th className="w-28 p-2 text-right">
                                            Preço
                                        </th>
                                        <th className="w-28 p-2 text-right">
                                            Desconto (F6)
                                        </th>
                                        <th className="w-28 p-2 text-right">
                                            Total
                                        </th>
                                        <th className="w-12 p-2">
                                            <span className="sr-only">
                                                Remover
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {cart.items.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="p-8 text-center text-muted-foreground"
                                            >
                                                {completed
                                                    ? 'Venda registrada. Leia o próximo item para iniciar outra venda.'
                                                    : 'Leia o código de barras ou busque um item para começar.'}
                                            </td>
                                        </tr>
                                    )}
                                    {cart.items.map((item) => (
                                        <tr
                                            key={item.key}
                                            className={`border-t align-middle ${selectedItem?.key === item.key ? 'bg-primary/5' : ''}`}
                                            onClick={() =>
                                                setSelected(item.key)
                                            }
                                        >
                                            <td className="p-2">
                                                {item.name}
                                                <span className="block text-xs text-muted-foreground">
                                                    {item.unit_code}
                                                    {item.product_variant_id ===
                                                        null && ' · avulso'}
                                                    {stockEnabled &&
                                                        item.available !==
                                                            null &&
                                                        ` · disponível ${formatDecimal(item.available)}`}
                                                </span>
                                            </td>
                                            <td className="p-2">
                                                <Input
                                                    ref={(element) => {
                                                        quantityRefs.current[
                                                            item.key
                                                        ] = element;
                                                    }}
                                                    aria-label={`Quantidade de ${item.name}`}
                                                    inputMode="decimal"
                                                    className="text-right"
                                                    value={item.quantity}
                                                    onFocus={() =>
                                                        setSelected(item.key)
                                                    }
                                                    onChange={(event) =>
                                                        updateItem(item.key, {
                                                            quantity:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    onKeyDown={(event) =>
                                                        event.key === 'Enter' &&
                                                        focusSearch()
                                                    }
                                                />
                                            </td>
                                            <td className="p-2 text-right">
                                                {item.product_variant_id ===
                                                    null ||
                                                abilities.change_prices ? (
                                                    <MoneyInput
                                                        aria-label={`Preço de ${item.name}`}
                                                        className="text-right"
                                                        value={item.unit_price}
                                                        onChange={(event) =>
                                                            updateItem(
                                                                item.key,
                                                                {
                                                                    unit_price:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    price_changed: true,
                                                                },
                                                            )
                                                        }
                                                    />
                                                ) : (
                                                    formatMoney(
                                                        parseDecimal(
                                                            item.unit_price,
                                                        ),
                                                    )
                                                )}
                                            </td>
                                            <td className="p-2">
                                                <MoneyInput
                                                    ref={(element) => {
                                                        discountRefs.current[
                                                            item.key
                                                        ] = element;
                                                    }}
                                                    aria-label={`Desconto em ${item.name}`}
                                                    className="text-right"
                                                    value={item.discount}
                                                    onFocus={() =>
                                                        setSelected(item.key)
                                                    }
                                                    onChange={(event) =>
                                                        updateItem(item.key, {
                                                            discount:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    onKeyDown={(event) =>
                                                        event.key === 'Enter' &&
                                                        focusSearch()
                                                    }
                                                />
                                            </td>
                                            <td className="p-2 text-right font-medium whitespace-nowrap">
                                                {formatMoney(
                                                    fromCents(itemCents(item)),
                                                )}
                                            </td>
                                            <td className="p-2 text-right">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={`Remover ${item.name}`}
                                                    onClick={() =>
                                                        removeItem(item.key)
                                                    }
                                                >
                                                    ✕
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <aside
                        className="flex flex-col gap-3"
                        aria-label="Resumo da venda"
                    >
                        <div className="space-y-2 rounded-md border p-4">
                            <div className="flex items-center justify-between gap-2 text-sm">
                                <span className="text-muted-foreground">
                                    Cliente
                                </span>
                                <Button
                                    type="button"
                                    variant="link"
                                    className="h-auto p-0"
                                    onClick={() => setDialog('customer')}
                                >
                                    {cart.customer
                                        ? cart.customer.name
                                        : settings.allow_anonymous
                                          ? 'Sem identificação (F3)'
                                          : 'Selecionar (F3)'}
                                </Button>
                            </div>
                            {cart.suspendedSaleId !== null && (
                                <p className="text-xs text-muted-foreground">
                                    Venda recuperada — preços e disponibilidade
                                    serão validados ao finalizar.
                                </p>
                            )}
                            <dl className="space-y-1 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        Subtotal
                                    </dt>
                                    <dd>{formatMoney(totals.items)}</dd>
                                </div>
                                <div className="flex items-center justify-between gap-2">
                                    <dt className="text-muted-foreground">
                                        Desconto geral
                                    </dt>
                                    <dd className="w-28">
                                        <MoneyInput
                                            aria-label="Desconto geral"
                                            className="h-8 text-right"
                                            value={cart.discount}
                                            onChange={(event) =>
                                                setCart({
                                                    ...cart,
                                                    discount:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </dd>
                                </div>
                                {toCents(totals.discount) > 0n && (
                                    <div
                                        className={`flex justify-between text-xs ${discountAboveLimit ? 'text-destructive' : 'text-muted-foreground'}`}
                                    >
                                        <dt>
                                            Descontos (
                                            {formatDecimal(
                                                totals.discountPercent,
                                                2,
                                            )}
                                            %)
                                        </dt>
                                        <dd>
                                            {discountAboveLimit
                                                ? `acima de ${formatDecimal(settings.max_discount_percent, 2)}%: exige autorização`
                                                : formatMoney(totals.discount)}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                            <p className="flex items-baseline justify-between border-t pt-2">
                                <span className="text-sm text-muted-foreground">
                                    Total
                                </span>
                                <span className="text-4xl font-bold tabular-nums">
                                    {formatMoney(totals.total)}
                                </span>
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Valores conferidos pelo servidor na finalização.
                            </p>
                        </div>

                        <Button
                            size="lg"
                            className="h-14 text-lg"
                            disabled={cart.items.length === 0}
                            onClick={openPayment}
                        >
                            Pagamento (F10)
                        </Button>
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                variant="outline"
                                disabled={cart.items.length === 0}
                                onClick={() => setDialog('suspend')}
                            >
                                Suspender (F7)
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => setDialog('recover')}
                            >
                                Recuperar (F9)
                                {suspended.length > 0 &&
                                    ` · ${suspended.length}`}
                            </Button>
                            <Button
                                variant="outline"
                                className="col-span-2"
                                disabled={
                                    cart.items.length === 0 &&
                                    cart.suspendedSaleId === null
                                }
                                onClick={() => setDialog('cancel')}
                            >
                                Cancelar venda
                            </Button>
                        </div>

                        {completed && (
                            <div
                                className="space-y-2 rounded-md border border-emerald-600/40 bg-emerald-600/5 p-4"
                                role="status"
                            >
                                <p className="font-medium">
                                    Venda{' '}
                                    {completed.orderNumber
                                        ? `#${completed.orderNumber}`
                                        : ''}{' '}
                                    registrada: {formatMoney(completed.total)}
                                </p>
                                {toCents(completed.change) > 0n && (
                                    <p className="text-2xl font-bold">
                                        Troco: {formatMoney(completed.change)}
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={receipt.url(completed.id, {
                                                query: { print: 1, width: 80 },
                                            })}
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            Comprovante 80 mm
                                        </a>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={receipt.url(completed.id, {
                                                query: { print: 1, width: 58 },
                                            })}
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            58 mm
                                        </a>
                                    </Button>
                                </div>
                            </div>
                        )}

                        <details className="rounded-md border p-3 text-xs text-muted-foreground">
                            <summary className="cursor-pointer text-sm">
                                Atalhos de teclado
                            </summary>
                            <dl className="mt-2 grid grid-cols-2 gap-1">
                                {SHORTCUTS.map(([key, label]) => (
                                    <div key={key} className="flex gap-2">
                                        <dt>
                                            <kbd className="rounded border px-1">
                                                {key}
                                            </kbd>
                                        </dt>
                                        <dd>{label}</dd>
                                    </div>
                                ))}
                            </dl>
                        </details>
                    </aside>
                </main>
            </div>

            <CustomerDialog
                open={dialog === 'customer'}
                onOpenChange={(open) => setDialog(open ? 'customer' : null)}
                onSelect={(customer) => setCart({ ...cart, customer })}
            />

            <PaymentDialog
                key={paymentKey}
                open={dialog === 'payment'}
                onOpenChange={(open) => setDialog(open ? 'payment' : null)}
                total={totals.total}
                methods={paymentMethods}
                today={today}
                hasCustomer={cart.customer !== null}
                processing={finalizeHttp.processing}
                errors={paymentErrors}
                needsAuthorization={needsAuthorization}
                onSubmit={submitPayment}
            />

            <ReasonActionDialog
                open={dialog === 'suspend'}
                onOpenChange={(open) => setDialog(open ? 'suspend' : null)}
                title="Suspender venda"
                description="Os itens ficam guardados, sem reserva nem saída de estoque, para recuperar depois em qualquer caixa."
                label="Identificação (opcional)"
                required={false}
                confirmLabel="Suspender"
                processing={actionHttp.processing}
                onConfirm={(label, _credentials, done) => {
                    actionHttp.transform(() => ({
                        ...cartPayload(cart),
                        label: label || null,
                        idempotency_key: uuid(),
                    }));
                    actionHttp
                        .post(suspend.url(session.id), {
                            onSuccess: (response) => {
                                setSuspended(
                                    response.suspended as SuspendedSale[],
                                );
                                setCart(emptyCart());
                                setNotice({
                                    type: 'info',
                                    message: 'Venda suspensa.',
                                });
                                done();
                                focusSearch();
                            },
                            onError: (errors) =>
                                done(Object.values(errors)[0] as string),
                        })
                        .catch((error: unknown) => {
                            networkFailure(error, 'a suspensão');
                            done('Sem conexão. Tente novamente.');
                        });
                }}
            />

            <ReasonActionDialog
                open={dialog === 'cancel'}
                onOpenChange={(open) => setDialog(open ? 'cancel' : null)}
                title="Cancelar venda"
                description="A venda em andamento é descartada e o cancelamento fica registrado no histórico."
                label="Motivo"
                required
                withAuthorization={!abilities.cancel && !abilities.authorize}
                confirmLabel="Cancelar venda"
                destructive
                processing={actionHttp.processing}
                onConfirm={(reason, credentials, done) => {
                    actionHttp.transform(() => ({
                        pos_sale_id: cart.suspendedSaleId,
                        cart:
                            cart.suspendedSaleId === null
                                ? cartPayload(cart)
                                : null,
                        reason,
                        authorization: credentialsPayload(credentials),
                        idempotency_key: uuid(),
                    }));
                    actionHttp
                        .post(cancel.url(session.id), {
                            onSuccess: (response) => {
                                setSuspended(
                                    response.suspended as SuspendedSale[],
                                );
                                setCart(emptyCart());
                                setNotice({
                                    type: 'info',
                                    message: 'Venda cancelada.',
                                });
                                done();
                                focusSearch();
                            },
                            onError: (errors) =>
                                done(Object.values(errors)[0] as string),
                        })
                        .catch((error: unknown) => {
                            networkFailure(error, 'o cancelamento');
                            done('Sem conexão. Tente novamente.');
                        });
                }}
            />

            <Dialog
                open={dialog === 'recover'}
                onOpenChange={(open) => setDialog(open ? 'recover' : null)}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogTitle>Vendas suspensas</DialogTitle>
                    <DialogDescription>
                        Ao recuperar, preços, disponibilidade e permissões são
                        conferidos de novo na finalização.
                    </DialogDescription>
                    {cart.items.length > 0 && (
                        <p className="text-sm text-destructive">
                            Finalize, suspenda ou cancele a venda atual antes de
                            recuperar outra.
                        </p>
                    )}
                    {suspended.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nenhuma venda suspensa.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-md border text-sm">
                            {suspended.map((sale) => (
                                <li
                                    key={sale.id}
                                    className="flex items-center justify-between gap-2 p-3"
                                >
                                    <span>
                                        {sale.label ??
                                            sale.customer_name ??
                                            `Venda suspensa #${sale.id}`}
                                        <span className="block text-xs text-muted-foreground">
                                            {sale.items_count} item(ns) ·{' '}
                                            {sale.terminal} · {sale.user_name}
                                            {sale.total &&
                                                ` · ${formatMoney(sale.total)} (estimado)`}
                                        </span>
                                    </span>
                                    <Button
                                        size="sm"
                                        disabled={
                                            cart.items.length > 0 ||
                                            actionHttp.processing
                                        }
                                        onClick={() => {
                                            actionHttp.transform(() => ({}));
                                            actionHttp
                                                .post(
                                                    resume.url({
                                                        session: session.id,
                                                        sale: sale.id,
                                                    }),
                                                    {
                                                        onSuccess: (
                                                            response,
                                                        ) => {
                                                            setCart(
                                                                cartFromSuspended(
                                                                    sale.id,
                                                                    response.cart as Parameters<
                                                                        typeof cartFromSuspended
                                                                    >[1],
                                                                ),
                                                            );
                                                            setDialog(null);
                                                            setCompleted(null);
                                                            focusSearch();
                                                        },
                                                        onError: (errors) =>
                                                            setNotice({
                                                                type: 'error',
                                                                message: String(
                                                                    Object.values(
                                                                        errors,
                                                                    )[0],
                                                                ),
                                                            }),
                                                    },
                                                )
                                                .catch((error: unknown) =>
                                                    networkFailure(
                                                        error,
                                                        'a recuperação',
                                                    ),
                                                );
                                        }}
                                    >
                                        Recuperar
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </DialogContent>
            </Dialog>

            <CashMovementDialog
                open={dialog === 'cash'}
                onOpenChange={(open) => setDialog(open ? 'cash' : null)}
                sessionId={session.id}
                abilities={abilities}
                onDone={(message) => {
                    setNotice({ type: 'info', message });
                    setDialog(null);
                    focusSearch();
                }}
            />

            <CustomItemDialog
                open={dialog === 'custom'}
                onOpenChange={(open) => setDialog(open ? 'custom' : null)}
                canRegister={abilities.register_items}
                onAdd={(item) => {
                    setCart((current) => addToCart(current, item));
                    setSelected(item.key);
                    setDialog(null);
                    setCompleted(null);
                    focusSearch();
                }}
                onRegistered={(item) => {
                    setDialog(null);
                    add(item);
                }}
            />
        </>
    );
}

function ReasonActionDialog({
    open,
    onOpenChange,
    title,
    description,
    label,
    required,
    withAuthorization = false,
    confirmLabel,
    destructive = false,
    processing,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    label: string;
    required: boolean;
    withAuthorization?: boolean;
    confirmLabel: string;
    destructive?: boolean;
    processing: boolean;
    onConfirm: (
        value: string,
        credentials: Credentials,
        done: (error?: string) => void,
    ) => void;
}) {
    const [value, setValue] = useState('');
    const [credentials, setCredentials] =
        useState<Credentials>(emptyCredentials);
    const [error, setError] = useState<string>();
    const [askAuthorization, setAskAuthorization] = useState(withAuthorization);

    return (
        <Dialog
            open={open}
            onOpenChange={(state) => {
                setValue('');
                setCredentials(emptyCredentials);
                setError(undefined);
                setAskAuthorization(withAuthorization);
                onOpenChange(state);
            }}
        >
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setError(undefined);
                        onConfirm(value.trim(), credentials, (message) => {
                            if (message === undefined) {
                                onOpenChange(false);
                                setValue('');
                                setCredentials(emptyCredentials);
                            } else {
                                setError(message);
                                setAskAuthorization(
                                    askAuthorization ||
                                        message.includes('autoriza'),
                                );
                            }
                        });
                    }}
                >
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                    <FormField
                        id={`reason-${title}`}
                        label={label}
                        error={askAuthorization ? undefined : error}
                    >
                        <Input
                            id={`reason-${title}`}
                            autoFocus
                            required={required}
                            maxLength={required ? 400 : 120}
                            value={value}
                            onChange={(event) => setValue(event.target.value)}
                        />
                    </FormField>
                    {askAuthorization && (
                        <AuthorizationFields
                            id={`auth-${title}`}
                            value={credentials}
                            onChange={setCredentials}
                            error={error}
                        />
                    )}
                    <div className="flex justify-between gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => onOpenChange(false)}
                        >
                            Voltar
                        </Button>
                        <Button
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing}
                        >
                            {confirmLabel}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CashMovementDialog({
    open,
    onOpenChange,
    sessionId,
    abilities,
    onDone,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sessionId: number;
    abilities: Record<string, boolean>;
    onDone: (message: string) => void;
}) {
    const http = useHttp<
        {
            type: string;
            amount: string;
            reason: string;
            idempotency_key: string;
            authorization: Credentials | null;
        },
        Record<string, unknown>
    >({
        type: 'withdrawal',
        amount: '',
        reason: '',
        idempotency_key: uuid(),
        authorization: null,
    });
    const [credentials, setCredentials] =
        useState<Credentials>(emptyCredentials);
    const [failure, setFailure] = useState<string>();
    const needsAuthorization =
        (http.data.type === 'withdrawal' &&
            !abilities.withdraw &&
            !abilities.authorize) ||
        (http.data.type === 'supply' &&
            !abilities.supply &&
            !abilities.authorize) ||
        'authorization' in http.errors;

    return (
        <Dialog
            open={open}
            onOpenChange={(state) => {
                http.reset();
                http.clearErrors();
                http.setData('idempotency_key', uuid());
                setCredentials(emptyCredentials);
                setFailure(undefined);
                onOpenChange(state);
            }}
        >
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setFailure(undefined);
                        http.transform((data) => ({
                            ...data,
                            amount: parseDecimal(data.amount),
                            authorization: credentialsPayload(credentials),
                        }));
                        http.post(storeMovement.url(sessionId), {
                            onSuccess: () => {
                                onDone(
                                    http.data.type === 'withdrawal'
                                        ? 'Sangria registrada.'
                                        : 'Suprimento registrado.',
                                );
                                http.reset();
                                http.setData('idempotency_key', uuid());
                            },
                        }).catch((error: unknown) =>
                            setFailure(
                                error instanceof HttpNetworkError
                                    ? 'Sem conexão: a movimentação não foi registrada.'
                                    : 'Não foi possível registrar.',
                            ),
                        );
                    }}
                >
                    <DialogTitle>Sangria ou suprimento</DialogTitle>
                    <DialogDescription>
                        Retirada ou reforço de dinheiro na gaveta. Não é receita
                        nem despesa e fica registrado com motivo e responsável.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="cash-type"
                            label="Operação"
                            error={http.errors.type}
                        >
                            <NativeSelect
                                id="cash-type"
                                value={http.data.type}
                                onChange={(event) =>
                                    http.setData('type', event.target.value)
                                }
                            >
                                <option value="withdrawal">
                                    Sangria (retirada)
                                </option>
                                <option value="supply">
                                    Suprimento (reforço)
                                </option>
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="cash-amount"
                            label="Valor"
                            error={http.errors.amount}
                        >
                            <MoneyInput
                                id="cash-amount"
                                required
                                value={http.data.amount}
                                onChange={(event) =>
                                    http.setData('amount', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="cash-reason"
                            label="Motivo"
                            error={http.errors.reason}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="cash-reason"
                                required
                                maxLength={400}
                                value={http.data.reason}
                                onChange={(event) =>
                                    http.setData('reason', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    {needsAuthorization && (
                        <AuthorizationFields
                            id="cash-authorization"
                            value={credentials}
                            onChange={setCredentials}
                            error={http.errors.authorization}
                        />
                    )}
                    {(failure ||
                        (http.errors as Record<string, string | undefined>)
                            .session) && (
                        <p className="text-sm text-destructive">
                            {failure ??
                                (
                                    http.errors as Record<
                                        string,
                                        string | undefined
                                    >
                                ).session}
                        </p>
                    )}
                    <div className="flex justify-between gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => onOpenChange(false)}
                        >
                            Voltar
                        </Button>
                        <Button disabled={http.processing}>Registrar</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CustomItemDialog({
    open,
    onOpenChange,
    canRegister,
    onAdd,
    onRegistered,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canRegister: boolean;
    onAdd: (item: PosCart['items'][number]) => void;
    onRegistered: (item: PosCatalogItem) => void;
}) {
    const [description, setDescription] = useState('');
    const [price, setPrice] = useState('');
    const [quantity, setQuantity] = useState('1');
    const [isService, setIsService] = useState(false);
    const [register, setRegister] = useState(false);
    const http = useHttp<
        { name: string; type: string; sale_price: string },
        PosCatalogItem
    >({ name: '', type: 'simple', sale_price: '' });

    return (
        <Dialog
            open={open}
            onOpenChange={(state) => {
                setDescription('');
                setPrice('');
                setQuantity('1');
                setRegister(false);
                http.clearErrors();
                onOpenChange(state);
            }}
        >
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (register) {
                            http.transform(() => ({
                                name: description.trim(),
                                type: isService ? 'service' : 'simple',
                                sale_price: parseDecimal(price),
                            }));
                            http.post(storeItem.url(), {
                                onSuccess: onRegistered,
                            }).catch(() => undefined);

                            return;
                        }

                        onAdd({
                            key: uuid(),
                            product_variant_id: null,
                            name: description.trim(),
                            unit_code: 'UN',
                            allows_fraction: false,
                            is_service: isService,
                            quantity: parseDecimal(quantity) || '1',
                            unit_price: price,
                            price_changed: true,
                            discount: '',
                            available: null,
                        });
                    }}
                >
                    <DialogTitle>Item avulso</DialogTitle>
                    <DialogDescription>
                        Venda de um item fora do catálogo. Marque “cadastrar”
                        para incluí-lo no catálogo compartilhado.
                    </DialogDescription>
                    <FormField
                        id="custom-description"
                        label="Descrição"
                        error={http.errors.name}
                    >
                        <Input
                            id="custom-description"
                            autoFocus
                            required
                            maxLength={255}
                            value={description}
                            onChange={(event) =>
                                setDescription(event.target.value)
                            }
                        />
                    </FormField>
                    <div className="grid grid-cols-2 gap-3">
                        <FormField
                            id="custom-price"
                            label="Preço"
                            error={http.errors.sale_price}
                        >
                            <MoneyInput
                                id="custom-price"
                                required
                                value={price}
                                onChange={(event) =>
                                    setPrice(event.target.value)
                                }
                            />
                        </FormField>
                        {!register && (
                            <FormField id="custom-quantity" label="Quantidade">
                                <Input
                                    id="custom-quantity"
                                    inputMode="numeric"
                                    value={quantity}
                                    onChange={(event) =>
                                        setQuantity(event.target.value)
                                    }
                                />
                            </FormField>
                        )}
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={isService}
                            onChange={(event) =>
                                setIsService(event.target.checked)
                            }
                        />{' '}
                        É um serviço
                    </label>
                    {canRegister && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={register}
                                onChange={(event) =>
                                    setRegister(event.target.checked)
                                }
                            />{' '}
                            Cadastrar no catálogo (cadastro simplificado)
                        </label>
                    )}
                    <div className="flex justify-between gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => onOpenChange(false)}
                        >
                            Voltar
                        </Button>
                        <Button
                            disabled={http.processing || toCents(price) <= 0n}
                        >
                            {register ? 'Cadastrar e incluir' : 'Incluir'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
