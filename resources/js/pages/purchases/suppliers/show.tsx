import { Head, Link, router, useForm } from '@inertiajs/react';
import ConfirmButton from '@/components/confirm-button';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import RemoteLookup from '@/components/purchases/remote-lookup';
import StatusBadge from '@/components/purchases/status-badge';
import type { Tab } from '@/components/tabs';
import Tabs from '@/components/tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatDecimal, formatMoney, parseDecimal } from '@/lib/decimal';
import { dashboard } from '@/routes/purchases';
import { lookup as itemLookup } from '@/routes/purchases/items';
import {
    create as orderCreate,
    index as orders,
    show as orderShow,
} from '@/routes/purchases/orders';
import {
    deactivate,
    edit,
    index,
    reactivate,
} from '@/routes/purchases/suppliers';
import {
    destroy,
    store as linkStore,
    update as linkUpdate,
} from '@/routes/purchases/suppliers/products';
import type {
    PurchasableItem,
    PurchaseOrderSummary,
    SupplierSummary,
} from '@/types';

type Supplier = SupplierSummary & {
    mobile: string | null;
    whatsapp: string | null;
    state_registration: string | null;
    municipal_registration: string | null;
    lead_time_days: number | null;
    payment_terms: string | null;
    minimum_order: string | null;
    notes: string | null;
    addresses: {
        id: number;
        type: string;
        street: string | null;
        number: string | null;
        district: string | null;
        city: string | null;
        state: string | null;
        postal_code: string | null;
    }[];
    contacts: {
        id: number;
        name: string;
        position: string | null;
        email: string | null;
        phone: string | null;
        whatsapp: string | null;
    }[];
};

type Link = {
    id: number;
    product_variant_id: number;
    name: string;
    sku: string | null;
    supplier_code: string | null;
    reference: string | null;
    lead_time_days: number | null;
    minimum_quantity: string | null;
    is_preferred: boolean;
    notes: string | null;
    last_purchased_at: string | null;
    last_cost?: string | null;
};

type PriceRow = {
    id: number;
    received_at: string;
    order_id: number;
    order_number: number;
    description: string;
    unit_code: string;
    quantity: string;
    purchase_unit_cost: string;
    unit_cost: string;
};

function AddLink({ supplierId }: { supplierId: number }) {
    const form = useForm({
        product_variant_id: null as number | null,
        name: '',
        supplier_code: '',
        reference: '',
        minimum_quantity: '',
        is_preferred: false,
    });
    const errors = form.errors as Record<string, string>;

    return (
        <form
            className="space-y-3 rounded-lg border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform(({ name: _name, ...current }) => ({
                    ...current,
                    minimum_quantity:
                        current.minimum_quantity === ''
                            ? null
                            : parseDecimal(current.minimum_quantity),
                }));
                form.post(linkStore.url(supplierId), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <h4 className="text-sm font-medium">Vincular item</h4>
            {form.data.product_variant_id === null ? (
                <RemoteLookup<PurchasableItem>
                    id="link-item"
                    placeholder="Nome, SKU ou código de barras"
                    url={(term) => itemLookup.url({ query: { term } })}
                    render={(item) => (
                        <>
                            {item.name}
                            {item.sku && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {item.sku}
                                </span>
                            )}
                        </>
                    )}
                    onSelect={(item) =>
                        form.setData((current) => ({
                            ...current,
                            product_variant_id: item.id,
                            name: item.name,
                        }))
                    }
                />
            ) : (
                <p className="text-sm">
                    {form.data.name}{' '}
                    <Button
                        type="button"
                        variant="link"
                        size="sm"
                        onClick={() => form.setData('product_variant_id', null)}
                    >
                        Trocar
                    </Button>
                </p>
            )}
            <InputError message={errors.product_variant_id} />
            <div className="grid gap-3 sm:grid-cols-3">
                <FormField
                    id="link-code"
                    label="Código no fornecedor"
                    error={errors.supplier_code}
                >
                    <Input
                        id="link-code"
                        value={form.data.supplier_code}
                        onChange={(event) =>
                            form.setData('supplier_code', event.target.value)
                        }
                    />
                </FormField>
                <FormField
                    id="link-reference"
                    label="Referência comercial"
                    error={errors.reference}
                >
                    <Input
                        id="link-reference"
                        value={form.data.reference}
                        onChange={(event) =>
                            form.setData('reference', event.target.value)
                        }
                    />
                </FormField>
                <FormField
                    id="link-minimum"
                    label="Quantidade mínima"
                    error={errors.minimum_quantity}
                >
                    <Input
                        id="link-minimum"
                        inputMode="decimal"
                        value={form.data.minimum_quantity}
                        onChange={(event) =>
                            form.setData('minimum_quantity', event.target.value)
                        }
                    />
                </FormField>
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.is_preferred}
                    onCheckedChange={(checked) =>
                        form.setData('is_preferred', checked === true)
                    }
                />
                Fornecedor preferencial deste item
            </label>
            <Button
                size="sm"
                disabled={
                    form.processing || form.data.product_variant_id === null
                }
            >
                Vincular
            </Button>
        </form>
    );
}

export default function SupplierShow({
    supplier,
    orders: supplierOrders,
    totals,
    products,
    priceHistory,
}: {
    supplier: Supplier;
    orders: PurchaseOrderSummary[];
    totals: { orders: number; value?: string };
    products: Link[];
    priceHistory: PriceRow[];
}) {
    const { can } = useTenant();
    const canViewCost = can('purchases.view_cost');
    const canManage = can('purchases.manage_suppliers');

    const tabs: Tab[] = [
        {
            key: 'data',
            label: 'Cadastro',
            content: (
                <div className="space-y-6 text-sm">
                    <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {(
                            [
                                [
                                    'Tipo',
                                    supplier.type === 'company'
                                        ? 'Pessoa jurídica'
                                        : 'Pessoa física',
                                ],
                                ['Nome fantasia', supplier.trade_name],
                                ['CPF/CNPJ', supplier.document],
                                [
                                    'Inscrição estadual',
                                    supplier.state_registration,
                                ],
                                [
                                    'Inscrição municipal',
                                    supplier.municipal_registration,
                                ],
                                ['E-mail', supplier.email],
                                ['Telefone', supplier.phone],
                                ['Celular', supplier.mobile],
                                ['WhatsApp', supplier.whatsapp],
                                ['Código interno', supplier.internal_code],
                                [
                                    'Prazo médio de entrega',
                                    supplier.lead_time_days === null
                                        ? null
                                        : `${supplier.lead_time_days} dia(s)`,
                                ],
                                [
                                    'Condição preferencial',
                                    supplier.payment_terms,
                                ],
                                [
                                    'Pedido mínimo',
                                    supplier.minimum_order === null
                                        ? null
                                        : formatMoney(supplier.minimum_order),
                                ],
                            ] as const
                        ).map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-xs text-muted-foreground">
                                    {label}
                                </dt>
                                <dd>{value ?? '—'}</dd>
                            </div>
                        ))}
                    </dl>
                    {supplier.notes && (
                        <p className="whitespace-pre-line">{supplier.notes}</p>
                    )}
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <h4 className="mb-2 font-medium">Endereços</h4>
                            {supplier.addresses.length === 0 && (
                                <p className="text-muted-foreground">
                                    Nenhum endereço.
                                </p>
                            )}
                            {supplier.addresses.map((address) => (
                                <p key={address.id}>
                                    {[
                                        address.street,
                                        address.number,
                                        address.district,
                                        address.city &&
                                            `${address.city}/${address.state ?? ''}`,
                                        address.postal_code,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')}
                                </p>
                            ))}
                        </div>
                        <div>
                            <h4 className="mb-2 font-medium">
                                Contatos comerciais
                            </h4>
                            {supplier.contacts.length === 0 && (
                                <p className="text-muted-foreground">
                                    Nenhum contato.
                                </p>
                            )}
                            {supplier.contacts.map((contact) => (
                                <p key={contact.id}>
                                    <strong>{contact.name}</strong>
                                    {contact.position &&
                                        ` (${contact.position})`}{' '}
                                    {[
                                        contact.phone,
                                        contact.whatsapp,
                                        contact.email,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            ))}
                        </div>
                    </div>
                </div>
            ),
        },
        {
            key: 'orders',
            label: `Histórico de compras (${totals.orders})`,
            content: (
                <div className="space-y-3">
                    <p className="text-sm text-muted-foreground">
                        {totals.orders} compra(s) efetivada(s)
                        {totals.value !== undefined &&
                            `, ${formatMoney(totals.value)}`}
                        . Exibindo as mais recentes.{' '}
                        <Link
                            className="underline"
                            href={orders.url({
                                query: { supplier: supplier.id },
                            })}
                        >
                            Ver todas
                        </Link>
                    </p>
                    <ul className="divide-y rounded-lg border text-sm">
                        {supplierOrders.length === 0 && (
                            <li className="p-3 text-muted-foreground">
                                Nenhum pedido.
                            </li>
                        )}
                        {supplierOrders.map((order) => (
                            <li
                                key={order.id}
                                className="flex flex-wrap items-center justify-between gap-2 p-3"
                            >
                                <Link
                                    className="font-medium underline-offset-4 hover:underline"
                                    href={orderShow(order.id)}
                                >
                                    #{order.number} ·{' '}
                                    {formatDate(order.order_date)}
                                </Link>
                                <span className="flex items-center gap-2">
                                    <StatusBadge
                                        status={order.status}
                                        isLate={order.is_late}
                                    />
                                    {canViewCost && formatMoney(order.total)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            ),
        },
        {
            key: 'products',
            label: `Produtos (${products.length})`,
            content: (
                <div className="space-y-4">
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">Item</th>
                                    <th className="p-2">Cód. fornecedor</th>
                                    <th className="p-2">Referência</th>
                                    <th className="p-2">Qtd. mínima</th>
                                    <th className="p-2">Última compra</th>
                                    {canViewCost && (
                                        <th className="p-2 text-right">
                                            Último custo
                                        </th>
                                    )}
                                    <th className="p-2">Preferencial</th>
                                    {canManage && (
                                        <th className="p-2">
                                            <span className="sr-only">
                                                Ações
                                            </span>
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {products.length === 0 && (
                                    <tr>
                                        <td
                                            className="p-4 text-muted-foreground"
                                            colSpan={8}
                                        >
                                            Nenhum item vinculado. Os itens
                                            recebidos deste fornecedor são
                                            vinculados automaticamente.
                                        </td>
                                    </tr>
                                )}
                                {products.map((link) => (
                                    <tr key={link.id} className="border-t">
                                        <td className="p-2">
                                            {link.name}
                                            {link.sku && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {link.sku}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-2">
                                            {link.supplier_code ?? '—'}
                                        </td>
                                        <td className="p-2">
                                            {link.reference ?? '—'}
                                        </td>
                                        <td className="p-2">
                                            {link.minimum_quantity === null
                                                ? '—'
                                                : formatDecimal(
                                                      link.minimum_quantity,
                                                  )}
                                        </td>
                                        <td className="p-2">
                                            {formatDate(link.last_purchased_at)}
                                        </td>
                                        {canViewCost && (
                                            <td className="p-2 text-right">
                                                {formatMoney(link.last_cost)}
                                            </td>
                                        )}
                                        <td className="p-2">
                                            {canManage ? (
                                                <Checkbox
                                                    aria-label={`Preferencial para ${link.name}`}
                                                    checked={link.is_preferred}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        router.put(
                                                            linkUpdate.url([
                                                                supplier.id,
                                                                link.id,
                                                            ]),
                                                            {
                                                                ...link,
                                                                is_preferred:
                                                                    checked ===
                                                                    true,
                                                                product_variant_id:
                                                                    undefined,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                />
                                            ) : link.is_preferred ? (
                                                'Sim'
                                            ) : (
                                                'Não'
                                            )}
                                        </td>
                                        {canManage && (
                                            <td className="p-2">
                                                <ConfirmButton
                                                    size="sm"
                                                    variant="ghost"
                                                    route={{
                                                        url: destroy.url([
                                                            supplier.id,
                                                            link.id,
                                                        ]),
                                                        method: 'delete',
                                                    }}
                                                    title="Remover vínculo"
                                                    description="O histórico de compras do item é preservado."
                                                    confirmLabel="Remover"
                                                >
                                                    Remover
                                                </ConfirmButton>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {canManage && <AddLink supplierId={supplier.id} />}
                </div>
            ),
        },
        ...(canViewCost
            ? [
                  {
                      key: 'prices',
                      label: 'Histórico de preços',
                      content: (
                          <div className="overflow-x-auto rounded-lg border">
                              <table className="w-full text-sm">
                                  <thead className="bg-muted/50 text-left">
                                      <tr>
                                          <th className="p-2">Data</th>
                                          <th className="p-2">Pedido</th>
                                          <th className="p-2">Item</th>
                                          <th className="p-2 text-right">
                                              Quantidade
                                          </th>
                                          <th className="p-2 text-right">
                                              Preço de compra
                                          </th>
                                          <th className="p-2 text-right">
                                              Custo efetivo
                                          </th>
                                      </tr>
                                  </thead>
                                  <tbody>
                                      {priceHistory.length === 0 && (
                                          <tr>
                                              <td
                                                  className="p-4 text-muted-foreground"
                                                  colSpan={6}
                                              >
                                                  Nenhum recebimento.
                                              </td>
                                          </tr>
                                      )}
                                      {priceHistory.map((row) => (
                                          <tr key={row.id} className="border-t">
                                              <td className="p-2">
                                                  {formatDate(row.received_at)}
                                              </td>
                                              <td className="p-2">
                                                  <Link
                                                      className="underline-offset-4 hover:underline"
                                                      href={orderShow(
                                                          row.order_id,
                                                      )}
                                                  >
                                                      #{row.order_number}
                                                  </Link>
                                              </td>
                                              <td className="p-2">
                                                  {row.description}
                                              </td>
                                              <td className="p-2 text-right">
                                                  {formatDecimal(row.quantity)}{' '}
                                                  {row.unit_code}
                                              </td>
                                              <td className="p-2 text-right">
                                                  {formatMoney(
                                                      row.purchase_unit_cost,
                                                  )}
                                              </td>
                                              <td className="p-2 text-right">
                                                  {formatMoney(row.unit_cost)}
                                              </td>
                                          </tr>
                                      ))}
                                  </tbody>
                              </table>
                              <p className="p-2 text-xs text-muted-foreground">
                                  Preço de compra: negociado, líquido do
                                  desconto do item. Custo efetivo: inclui o
                                  rateio de frete, seguro, despesas e desconto
                                  geral.
                              </p>
                          </div>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title={supplier.name} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <Heading
                            variant="small"
                            title={supplier.name}
                            description={supplier.trade_name ?? undefined}
                        />
                        <span className="inline-flex gap-1">
                            <Badge
                                variant={
                                    supplier.is_active ? 'secondary' : 'outline'
                                }
                            >
                                {supplier.is_active ? 'Ativo' : 'Inativo'}
                            </Badge>
                            {supplier.is_customer && (
                                <Badge variant="outline">Também cliente</Badge>
                            )}
                        </span>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canManage && (
                            <>
                                <ConfirmButton
                                    route={{
                                        url: (supplier.is_active
                                            ? deactivate
                                            : reactivate
                                        ).url(supplier.id),
                                        method: 'patch',
                                    }}
                                    title={
                                        supplier.is_active
                                            ? 'Inativar fornecedor'
                                            : 'Reativar fornecedor'
                                    }
                                    description={
                                        supplier.is_active
                                            ? 'Fornecedores inativos não recebem novos pedidos. O histórico é preservado e o cadastro de cliente não é afetado.'
                                            : 'O fornecedor volta a aceitar novos pedidos.'
                                    }
                                    confirmLabel={
                                        supplier.is_active
                                            ? 'Inativar'
                                            : 'Reativar'
                                    }
                                >
                                    {supplier.is_active
                                        ? 'Inativar'
                                        : 'Reativar'}
                                </ConfirmButton>
                                <Button variant="outline" asChild>
                                    <Link href={edit(supplier.id)}>Editar</Link>
                                </Button>
                            </>
                        )}
                        {supplier.is_active && can('purchases.create') && (
                            <Button asChild>
                                <Link
                                    href={orderCreate.url({
                                        query: { supplier: supplier.id },
                                    })}
                                >
                                    Novo pedido
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
                <Tabs label="Ficha do fornecedor" tabs={tabs} />
            </div>
        </>
    );
}

SupplierShow.layout = {
    breadcrumbs: [
        { title: 'Compras', href: dashboard() },
        { title: 'Fornecedores', href: index() },
        { title: 'Ficha', href: '#' },
    ],
};
