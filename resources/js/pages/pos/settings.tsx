import { Head, useForm } from '@inertiajs/react';
import CheckboxField from '@/components/checkbox-field';
import MoneyInput from '@/components/finance/money-input';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import { posBreadcrumb } from '@/components/pos/pos-nav';
import { Button } from '@/components/ui/button';
import { parseDecimal, toDecimalInput } from '@/lib/decimal';
import type { PosCatalogItem } from '@/lib/pos-cart';
import { settings } from '@/routes/pos';
import { update as updateFavorites } from '@/routes/pos/favorites';
import { update } from '@/routes/pos/settings';
import type { NamedRecord, Option } from '@/types';

type Settings = {
    allow_anonymous: boolean;
    max_discount_percent: string;
    discount_requires_authorization: boolean;
    allow_price_change: boolean;
    payment_methods: string[];
    blind_close: boolean;
    print_receipt: boolean;
    receipt_header: string | null;
    receipt_footer: string | null;
    finance_accounts: Record<string, number>;
    cash_account_id: number | null;
    treasury_account_id: number | null;
};

const FAVORITES_LIMIT = 24;

function FavoritesForm({
    favorites,
    catalog,
}: {
    favorites: PosCatalogItem[];
    catalog: NamedRecord[];
}) {
    const form = useForm({
        favorites: favorites.map((favorite) => favorite.id),
    });
    const names = new Map(catalog.map((item) => [item.id, item.name]));
    const available = catalog.filter(
        (item) => !form.data.favorites.includes(item.id),
    );

    return (
        <section
            aria-label="Produtos favoritos"
            className="space-y-3 rounded-lg border p-4"
        >
            <div>
                <h3 className="font-medium">Produtos favoritos</h3>
                <p className="text-sm text-muted-foreground">
                    Atalhos de um toque na frente de caixa, na ordem abaixo (até{' '}
                    {FAVORITES_LIMIT}). Vêm do catálogo compartilhado.
                </p>
            </div>
            {catalog.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nenhum item vendável no catálogo. Cadastre itens pela frente
                    de caixa ou pelo módulo Produtos.
                </p>
            ) : (
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(updateFavorites.url(), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <ol className="space-y-1">
                        {form.data.favorites.length === 0 && (
                            <li className="text-sm text-muted-foreground">
                                Nenhum favorito.
                            </li>
                        )}
                        {form.data.favorites.map((id, position) => (
                            <li
                                key={id}
                                className="flex items-center justify-between gap-2 rounded-md border px-3 py-1.5 text-sm"
                            >
                                <span>
                                    {position + 1}.{' '}
                                    {names.get(id) ??
                                        favorites.find(
                                            (favorite) => favorite.id === id,
                                        )?.name ??
                                        `Item ${id}`}
                                </span>
                                <span className="flex gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        aria-label="Subir"
                                        disabled={position === 0}
                                        onClick={() => {
                                            const next = [
                                                ...form.data.favorites,
                                            ];
                                            [
                                                next[position - 1],
                                                next[position],
                                            ] = [
                                                next[position],
                                                next[position - 1],
                                            ];
                                            form.setData('favorites', next);
                                        }}
                                    >
                                        ↑
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            form.setData(
                                                'favorites',
                                                form.data.favorites.filter(
                                                    (favorite) =>
                                                        favorite !== id,
                                                ),
                                            )
                                        }
                                    >
                                        Remover
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ol>
                    {form.data.favorites.length < FAVORITES_LIMIT &&
                        available.length > 0 && (
                            <NativeSelect
                                aria-label="Adicionar favorito"
                                className="max-w-md"
                                value=""
                                onChange={(event) => {
                                    if (event.target.value !== '') {
                                        form.setData('favorites', [
                                            ...form.data.favorites,
                                            Number(event.target.value),
                                        ]);
                                    }
                                }}
                            >
                                <option value="">Adicionar item…</option>
                                {available.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                    {form.errors.favorites && (
                        <p className="text-sm text-destructive">
                            {form.errors.favorites}
                        </p>
                    )}
                    <Button variant="outline" disabled={form.processing}>
                        Salvar favoritos
                    </Button>
                </form>
            )}
        </section>
    );
}

export default function PosSettings({
    settings: current,
    methods,
    financeEnabled,
    accounts,
    favorites,
    catalog,
}: {
    settings: Settings;
    methods: Option[];
    financeEnabled: boolean;
    accounts: NamedRecord[];
    favorites: PosCatalogItem[];
    catalog: NamedRecord[];
}) {
    const form = useForm({
        allow_anonymous: current.allow_anonymous,
        max_discount_percent: toDecimalInput(current.max_discount_percent),
        discount_requires_authorization:
            current.discount_requires_authorization,
        allow_price_change: current.allow_price_change,
        payment_methods: current.payment_methods,
        blind_close: current.blind_close,
        print_receipt: current.print_receipt,
        receipt_header: current.receipt_header ?? '',
        receipt_footer: current.receipt_footer ?? '',
        finance_accounts: Object.fromEntries(
            Object.entries(current.finance_accounts).map(([method, id]) => [
                method,
                String(id),
            ]),
        ) as Record<string, string>,
        cash_account_id:
            current.cash_account_id === null
                ? ''
                : String(current.cash_account_id),
        treasury_account_id:
            current.treasury_account_id === null
                ? ''
                : String(current.treasury_account_id),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const accountSelect = (
        id: string,
        value: string,
        onChange: (value: string) => void,
    ) => (
        <NativeSelect
            id={id}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="">Não vincular</option>
            {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                    {account.name}
                </option>
            ))}
        </NativeSelect>
    );

    return (
        <>
            <Head title="Configurações do PDV" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Configurações do PDV"
                    description="Regras da frente de caixa válidas para todos os terminais da empresa."
                />
                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            max_discount_percent:
                                parseDecimal(data.max_discount_percent) || '0',
                            receipt_header:
                                data.receipt_header === ''
                                    ? null
                                    : data.receipt_header,
                            receipt_footer:
                                data.receipt_footer === ''
                                    ? null
                                    : data.receipt_footer,
                            finance_accounts: Object.fromEntries(
                                Object.entries(data.finance_accounts)
                                    .filter(
                                        ([method, id]) =>
                                            id !== '' &&
                                            data.payment_methods.includes(
                                                method,
                                            ),
                                    )
                                    .map(([method, id]) => [
                                        method,
                                        Number(id),
                                    ]),
                            ),
                            cash_account_id:
                                data.cash_account_id === ''
                                    ? null
                                    : Number(data.cash_account_id),
                            treasury_account_id:
                                data.treasury_account_id === ''
                                    ? null
                                    : Number(data.treasury_account_id),
                        }));
                        form.put(update.url(), { preserveScroll: true });
                    }}
                >
                    <section
                        aria-label="Venda"
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <h3 className="font-medium">Venda</h3>
                        <CheckboxField
                            id="allow-anonymous"
                            label="Permitir venda sem identificação do cliente"
                            checked={form.data.allow_anonymous}
                            error={form.errors.allow_anonymous}
                            onChange={(checked) =>
                                form.setData('allow_anonymous', checked)
                            }
                        />
                        <FormField
                            id="max-discount"
                            label="Desconto máximo sem autorização (%)"
                            error={form.errors.max_discount_percent}
                            className="max-w-xs"
                        >
                            <MoneyInput
                                id="max-discount"
                                placeholder="0"
                                value={form.data.max_discount_percent}
                                onChange={(event) =>
                                    form.setData(
                                        'max_discount_percent',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <CheckboxField
                            id="discount-authorization"
                            label="Desconto acima do limite exige autorização do supervisor (sem esta opção, é recusado)"
                            checked={form.data.discount_requires_authorization}
                            error={form.errors.discount_requires_authorization}
                            onChange={(checked) =>
                                form.setData(
                                    'discount_requires_authorization',
                                    checked,
                                )
                            }
                        />
                        <CheckboxField
                            id="price-change"
                            label="Permitir alterar o preço unitário no caixa (exige a permissão de alterar preços)"
                            checked={form.data.allow_price_change}
                            error={form.errors.allow_price_change}
                            onChange={(checked) =>
                                form.setData('allow_price_change', checked)
                            }
                        />
                    </section>

                    <section
                        aria-label="Formas de pagamento"
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <div>
                            <h3 className="font-medium">Formas de pagamento</h3>
                            <p className="text-sm text-muted-foreground">
                                Registro manual pelo operador, sem processamento
                                bancário.
                                {financeEnabled
                                    ? ' Vincule cada forma à conta financeira que recebe o valor.'
                                    : ''}
                            </p>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2">
                            {methods.map((method) => {
                                const isEnabled =
                                    form.data.payment_methods.includes(
                                        method.value,
                                    );

                                return (
                                    <div
                                        key={method.value}
                                        className="space-y-2 rounded-md border p-3"
                                    >
                                        <CheckboxField
                                            id={`method-${method.value}`}
                                            label={method.label}
                                            checked={isEnabled}
                                            onChange={(checked) =>
                                                form.setData(
                                                    'payment_methods',
                                                    checked
                                                        ? [
                                                              ...form.data
                                                                  .payment_methods,
                                                              method.value,
                                                          ]
                                                        : form.data.payment_methods.filter(
                                                              (value) =>
                                                                  value !==
                                                                  method.value,
                                                          ),
                                                )
                                            }
                                        />
                                        {financeEnabled && isEnabled && (
                                            <FormField
                                                id={`account-${method.value}`}
                                                label="Conta financeira"
                                                error={
                                                    errors[
                                                        `finance_accounts.${method.value}`
                                                    ]
                                                }
                                            >
                                                {accountSelect(
                                                    `account-${method.value}`,
                                                    form.data.finance_accounts[
                                                        method.value
                                                    ] ?? '',
                                                    (value) =>
                                                        form.setData(
                                                            'finance_accounts',
                                                            {
                                                                ...form.data
                                                                    .finance_accounts,
                                                                [method.value]:
                                                                    value,
                                                            },
                                                        ),
                                                )}
                                            </FormField>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                        {(form.errors.payment_methods ||
                            errors.finance_accounts) && (
                            <p className="text-sm text-destructive">
                                {form.errors.payment_methods ??
                                    errors.finance_accounts}
                            </p>
                        )}
                    </section>

                    {financeEnabled && (
                        <section
                            aria-label="Sangrias e suprimentos no Financeiro"
                            className="space-y-3 rounded-lg border p-4"
                        >
                            <div>
                                <h3 className="font-medium">
                                    Sangrias e suprimentos no Financeiro
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Com as duas contas definidas, sangrias e
                                    suprimentos viram transferências entre elas,
                                    sem receita nem despesa. Sem elas, ficam só
                                    no controle do caixa.
                                </p>
                            </div>
                            <div className="grid gap-3 md:grid-cols-2">
                                <FormField
                                    id="cash-account"
                                    label="Conta do caixa (gaveta)"
                                    error={errors.cash_account_id}
                                >
                                    {accountSelect(
                                        'cash-account',
                                        form.data.cash_account_id,
                                        (value) =>
                                            form.setData(
                                                'cash_account_id',
                                                value,
                                            ),
                                    )}
                                </FormField>
                                <FormField
                                    id="treasury-account"
                                    label="Conta de destino da sangria"
                                    error={errors.treasury_account_id}
                                >
                                    {accountSelect(
                                        'treasury-account',
                                        form.data.treasury_account_id,
                                        (value) =>
                                            form.setData(
                                                'treasury_account_id',
                                                value,
                                            ),
                                    )}
                                </FormField>
                            </div>
                        </section>
                    )}

                    <section
                        aria-label="Fechamento e comprovante"
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <h3 className="font-medium">
                            Fechamento e comprovante
                        </h3>
                        <CheckboxField
                            id="blind-close"
                            label="Fechamento cego (o operador não vê o valor esperado ao fechar)"
                            checked={form.data.blind_close}
                            error={form.errors.blind_close}
                            onChange={(checked) =>
                                form.setData('blind_close', checked)
                            }
                        />
                        <CheckboxField
                            id="print-receipt"
                            label="Abrir a impressão do comprovante ao finalizar a venda"
                            checked={form.data.print_receipt}
                            error={form.errors.print_receipt}
                            onChange={(checked) =>
                                form.setData('print_receipt', checked)
                            }
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FormField
                                id="receipt-header"
                                label="Cabeçalho do comprovante"
                                error={form.errors.receipt_header}
                                hint="Ex.: nome fantasia, endereço e telefone."
                            >
                                <NativeTextarea
                                    id="receipt-header"
                                    maxLength={200}
                                    value={form.data.receipt_header}
                                    onChange={(event) =>
                                        form.setData(
                                            'receipt_header',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                id="receipt-footer"
                                label="Rodapé do comprovante"
                                error={form.errors.receipt_footer}
                            >
                                <NativeTextarea
                                    id="receipt-footer"
                                    maxLength={300}
                                    value={form.data.receipt_footer}
                                    onChange={(event) =>
                                        form.setData(
                                            'receipt_footer',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            O comprovante sempre informa que não tem validade
                            fiscal.
                        </p>
                    </section>

                    <Button disabled={form.processing}>
                        Salvar configurações
                    </Button>
                </form>

                <FavoritesForm favorites={favorites} catalog={catalog} />
            </div>
        </>
    );
}

PosSettings.layout = {
    breadcrumbs: [posBreadcrumb, { title: 'Configurações', href: settings() }],
};
