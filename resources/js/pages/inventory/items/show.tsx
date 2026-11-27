import { Head, useForm } from '@inertiajs/react';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import { inventoryBreadcrumb } from '@/components/inventory/inventory-nav';
import MovementDialog from '@/components/inventory/movement-dialog';
import MovementsTable from '@/components/inventory/movements-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import {
    formatDecimal,
    formatMoney,
    parseDecimal,
    toDecimalInput,
} from '@/lib/decimal';
import { index, thresholds } from '@/routes/inventory/items';
import type { StockItem, StockMovement, StockWarehouseOption } from '@/types';

export default function StockItemShow({
    item,
    byWarehouse,
    movements,
    canManage,
    canMove,
    canAdjust,
    canViewCost,
    warehouses,
}: {
    item: StockItem & { average_cost: string | null };
    byWarehouse: {
        id: number;
        name: string;
        is_active: boolean;
        quantity: string;
    }[];
    movements: StockMovement[];
    canManage: boolean;
    canMove: boolean;
    canAdjust: boolean;
    canViewCost: boolean;
    warehouses: StockWarehouseOption[];
}) {
    const { formatDateTime } = useTenant();
    const form = useForm({
        minimum_stock: toDecimalInput(item.minimum_stock),
        maximum_stock: toDecimalInput(item.maximum_stock),
    });

    return (
        <>
            <Head title={item.name} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <Heading
                            variant="small"
                            title={item.name}
                            description={
                                [
                                    item.sku ? `SKU ${item.sku}` : null,
                                    item.barcode
                                        ? `Código de barras ${item.barcode}`
                                        : null,
                                    item.internal_code
                                        ? `Código interno ${item.internal_code}`
                                        : null,
                                    item.category,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || 'Sem códigos cadastrados'
                            }
                        />
                        <div className="flex flex-wrap gap-1">
                            {item.is_low_stock && (
                                <Badge variant="destructive">
                                    Abaixo do mínimo
                                </Badge>
                            )}
                            {item.is_above_max && (
                                <Badge variant="outline">Acima do máximo</Badge>
                            )}
                            {!item.is_active && (
                                <Badge variant="secondary">Inativa</Badge>
                            )}
                        </div>
                    </div>
                    {item.is_active && (
                        <MovementDialog
                            warehouses={warehouses}
                            canMove={canMove}
                            canAdjust={canAdjust}
                            canViewCost={canViewCost}
                            item={item}
                            trigger="Movimentar"
                        />
                    )}
                </div>

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        [
                            'Saldo total',
                            `${formatDecimal(item.balance)} ${item.unit.code}`,
                        ],
                        ['Reservado', formatDecimal(item.reserved)],
                        ['Disponível', formatDecimal(item.available)],
                        [
                            'Última movimentação',
                            item.last_movement_at
                                ? formatDateTime(item.last_movement_at)
                                : 'Nenhuma',
                        ],
                        ...(canViewCost
                            ? [
                                  [
                                      'Custo cadastrado',
                                      formatMoney(item.cost_price),
                                  ],
                                  [
                                      'Custo médio',
                                      item.average_cost === null
                                          ? '—'
                                          : formatMoney(item.average_cost),
                                  ],
                              ]
                            : []),
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-3">
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-lg font-semibold">{value}</dd>
                        </div>
                    ))}
                </dl>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section
                        aria-label="Saldo por depósito"
                        className="space-y-3"
                    >
                        <h3 className="font-medium">Saldo por depósito</h3>
                        <ul className="space-y-2">
                            {byWarehouse.map((warehouse) => (
                                <li
                                    key={warehouse.id}
                                    className="flex justify-between gap-2 rounded-lg border p-3 text-sm"
                                >
                                    <span>
                                        {warehouse.name}
                                        {!warehouse.is_active && (
                                            <span className="text-xs text-muted-foreground">
                                                {' '}
                                                (inativo)
                                            </span>
                                        )}
                                    </span>
                                    <span className="font-medium">
                                        {formatDecimal(warehouse.quantity)}{' '}
                                        {item.unit.code}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                    {canManage && (
                        <section
                            aria-label="Estoque mínimo e máximo"
                            className="space-y-3"
                        >
                            <h3 className="font-medium">
                                Estoque mínimo e máximo
                            </h3>
                            <form
                                className="flex flex-wrap items-end gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.transform((data) => ({
                                        minimum_stock:
                                            parseDecimal(data.minimum_stock) ||
                                            '0',
                                        maximum_stock:
                                            data.maximum_stock.trim() === ''
                                                ? null
                                                : parseDecimal(
                                                      data.maximum_stock,
                                                  ),
                                    }));
                                    form.put(thresholds.url(item.id), {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <FormField
                                    id="threshold-minimum"
                                    label="Mínimo"
                                    error={form.errors.minimum_stock}
                                >
                                    <Input
                                        id="threshold-minimum"
                                        inputMode="decimal"
                                        className="w-32"
                                        value={form.data.minimum_stock}
                                        onChange={(event) =>
                                            form.setData(
                                                'minimum_stock',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="threshold-maximum"
                                    label="Máximo (opcional)"
                                    error={form.errors.maximum_stock}
                                >
                                    <Input
                                        id="threshold-maximum"
                                        inputMode="decimal"
                                        className="w-32"
                                        value={form.data.maximum_stock}
                                        onChange={(event) =>
                                            form.setData(
                                                'maximum_stock',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                                <Button
                                    variant="outline"
                                    disabled={form.processing}
                                >
                                    Salvar
                                </Button>
                            </form>
                        </section>
                    )}
                </div>

                <section aria-label="Movimentações" className="space-y-3">
                    <h3 className="font-medium">Últimas movimentações</h3>
                    <MovementsTable
                        movements={movements}
                        canAdjust={canAdjust}
                        showItem={false}
                    />
                </section>
            </div>
        </>
    );
}

StockItemShow.layout = {
    breadcrumbs: [
        inventoryBreadcrumb,
        { title: 'Mercadorias', href: index() },
        { title: 'Mercadoria', href: '#' },
    ],
};
