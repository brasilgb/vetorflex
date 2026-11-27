import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { inventoryBreadcrumb } from '@/components/inventory/inventory-nav';
import MovementDialog from '@/components/inventory/movement-dialog';
import MovementsTable from '@/components/inventory/movements-table';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/inventory/movements';
import type {
    Option,
    Paginated,
    StockMovement,
    StockWarehouseOption,
} from '@/types';

type Filters = {
    search?: string | null;
    type?: string | null;
    origin?: string | null;
    warehouse: number | null;
    from?: string | null;
    until?: string | null;
};

export default function StockMovements({
    movements,
    filters,
    types,
    origins,
    warehouses,
    canMove,
    canAdjust,
}: {
    movements: Paginated<StockMovement>;
    filters: Filters;
    types: Option[];
    origins: Option[];
    warehouses: StockWarehouseOption[];
    canMove: boolean;
    canAdjust: boolean;
}) {
    const form = useForm({
        search: filters.search ?? '',
        type: filters.type ?? '',
        origin: filters.origin ?? '',
        warehouse: filters.warehouse === null ? '' : String(filters.warehouse),
        from: filters.from ?? '',
        until: filters.until ?? '',
    });

    return (
        <>
            <Head title="Movimentações de estoque" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Movimentações"
                        description="Histórico imutável de entradas, saídas, ajustes, transferências e estornos, de todas as origens."
                    />
                    <MovementDialog
                        warehouses={warehouses}
                        canMove={canMove}
                        canAdjust={canAdjust}
                    />
                </div>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) =>
                            Object.fromEntries(
                                Object.entries(data).filter(
                                    ([, value]) => value !== '',
                                ),
                            ),
                        );
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        aria-label="Mercadoria"
                        placeholder="Mercadoria, SKU ou código"
                        className="w-56"
                        value={form.data.search}
                        onChange={(event) =>
                            form.setData('search', event.target.value)
                        }
                    />
                    <NativeSelect
                        aria-label="Tipo"
                        className="w-44"
                        value={form.data.type}
                        onChange={(event) =>
                            form.setData('type', event.target.value)
                        }
                    >
                        <option value="">Todos os tipos</option>
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Origem"
                        className="w-40"
                        value={form.data.origin}
                        onChange={(event) =>
                            form.setData('origin', event.target.value)
                        }
                    >
                        <option value="">Todas as origens</option>
                        {origins.map((origin) => (
                            <option key={origin.value} value={origin.value}>
                                {origin.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Depósito"
                        className="w-44"
                        value={form.data.warehouse}
                        onChange={(event) =>
                            form.setData('warehouse', event.target.value)
                        }
                    >
                        <option value="">Todos os depósitos</option>
                        {warehouses.map((warehouse) => (
                            <option key={warehouse.id} value={warehouse.id}>
                                {warehouse.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input
                        type="date"
                        aria-label="A partir de"
                        className="w-40"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Até"
                        className="w-40"
                        value={form.data.until}
                        onChange={(event) =>
                            form.setData('until', event.target.value)
                        }
                    />
                    <Button variant="outline" disabled={form.processing}>
                        Filtrar
                    </Button>
                </form>
                <MovementsTable
                    movements={movements.data}
                    canAdjust={canAdjust}
                />
                <Pagination links={movements.links} />
            </div>
        </>
    );
}

StockMovements.layout = {
    breadcrumbs: [
        inventoryBreadcrumb,
        { title: 'Movimentações', href: index() },
    ],
};
