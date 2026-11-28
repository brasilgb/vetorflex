import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import { inventoryBreadcrumb } from '@/components/inventory/inventory-nav';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { index, show, store } from '@/routes/inventory/counts';
import type { Paginated, StockCount, StockWarehouseOption } from '@/types';

type Item = { id: number; name: string; sku: string | null };

function CreateCountDialog({
    warehouses,
    items,
}: {
    warehouses: StockWarehouseOption[];
    items: Item[];
}) {
    const [isOpen, setIsOpen] = useState(false);
    const [selectAll, setSelectAll] = useState(true);
    const [selected, setSelected] = useState<number[]>([]);
    const [filter, setFilter] = useState('');
    const initial = {
        inventory_warehouse_id: String(
            warehouses.find((warehouse) => warehouse.is_default)?.id ??
                warehouses[0]?.id ??
                '',
        ),
        description: '',
    };
    const form = useForm(initial);
    const visible = items.filter((item) =>
        `${item.name} ${item.sku ?? ''}`
            .toLowerCase()
            .includes(filter.toLowerCase()),
    );

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.setData(initial);
                form.clearErrors();
                setSelectAll(true);
                setSelected([]);
                setFilter('');
            }}
        >
            <DialogTrigger asChild>
                <Button>Novo inventário</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            inventory_warehouse_id: Number(
                                data.inventory_warehouse_id,
                            ),
                            description: data.description || null,
                            items: selectAll ? null : selected,
                        }));
                        form.post(store.url());
                    }}
                >
                    <DialogTitle>Novo inventário</DialogTitle>
                    <DialogDescription>
                        Escolha o depósito e as mercadorias. Os saldos só mudam
                        na aprovação, comparando a contagem com o saldo daquele
                        momento.
                    </DialogDescription>
                    <FormField
                        id="count-warehouse"
                        label="Depósito"
                        error={form.errors.inventory_warehouse_id}
                    >
                        <NativeSelect
                            id="count-warehouse"
                            value={form.data.inventory_warehouse_id}
                            onChange={(event) =>
                                form.setData(
                                    'inventory_warehouse_id',
                                    event.target.value,
                                )
                            }
                        >
                            {warehouses.map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>
                                    {warehouse.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="count-description"
                        label="Descrição (opcional)"
                        error={form.errors.description}
                    >
                        <Input
                            id="count-description"
                            maxLength={160}
                            placeholder="Balanço mensal, prateleira A…"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </FormField>
                    <CheckboxField
                        id="count-all"
                        label="Todas as mercadorias ativas com controle de estoque"
                        checked={selectAll}
                        onChange={setSelectAll}
                    />
                    {!selectAll && (
                        <div className="space-y-2">
                            <Input
                                aria-label="Filtrar mercadorias"
                                placeholder="Filtrar por nome ou SKU"
                                value={filter}
                                onChange={(event) =>
                                    setFilter(event.target.value)
                                }
                            />
                            <div className="max-h-56 space-y-1 overflow-y-auto rounded-md border p-2">
                                {visible.map((item) => (
                                    <CheckboxField
                                        key={item.id}
                                        id={`count-item-${item.id}`}
                                        label={`${item.name}${item.sku ? ` (${item.sku})` : ''}`}
                                        checked={selected.includes(item.id)}
                                        onChange={(checked) =>
                                            setSelected((current) =>
                                                checked
                                                    ? [...current, item.id]
                                                    : current.filter(
                                                          (id) =>
                                                              id !== item.id,
                                                      ),
                                            )
                                        }
                                    />
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {selected.length} selecionada(s).
                            </p>
                        </div>
                    )}
                    {(form.errors as Record<string, string>).items && (
                        <p className="text-sm text-destructive">
                            {(form.errors as Record<string, string>).items}
                        </p>
                    )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button
                            disabled={
                                form.processing ||
                                (!selectAll && selected.length === 0)
                            }
                        >
                            Criar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function StockCounts({
    counts,
    warehouses,
    items,
    canCount,
}: {
    counts: Paginated<StockCount>;
    warehouses: StockWarehouseOption[];
    items: Item[];
    canCount: boolean;
}) {
    const { formatDateTime } = useTenant();

    return (
        <>
            <Head title="Inventários" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Inventários"
                        description="Contagem física por depósito, com aprovação e ajustes rastreáveis."
                    />
                    {canCount && (
                        <CreateCountDialog
                            warehouses={warehouses}
                            items={items}
                        />
                    )}
                </div>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Inventário</th>
                                <th className="p-3">Depósito</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3 text-right">Contados</th>
                                <th className="p-3">Responsáveis</th>
                            </tr>
                        </thead>
                        <tbody>
                            {counts.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhum inventário.
                                    </td>
                                </tr>
                            )}
                            {counts.data.map((count) => (
                                <tr
                                    key={count.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <Link
                                            href={show(count.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            #{count.number}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {count.description ??
                                                'Sem descrição'}
                                            {count.created_at
                                                ? ` · ${formatDateTime(count.created_at)}`
                                                : ''}
                                        </span>
                                    </td>
                                    <td className="p-3">{count.warehouse}</td>
                                    <td className="p-3">
                                        <Badge
                                            variant={
                                                count.status === 'open'
                                                    ? 'default'
                                                    : count.status ===
                                                        'approved'
                                                      ? 'secondary'
                                                      : 'outline'
                                            }
                                        >
                                            {count.status_label}
                                        </Badge>
                                    </td>
                                    <td className="p-3 text-right">
                                        {count.counted_count ?? 0} /{' '}
                                        {count.items_count ?? 0}
                                    </td>
                                    <td className="p-3">
                                        {count.created_by_name ?? '—'}
                                        {count.approved_by_name && (
                                            <span className="block text-xs text-muted-foreground">
                                                Aprovado por{' '}
                                                {count.approved_by_name}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={counts.links} />
            </div>
        </>
    );
}

StockCounts.layout = {
    breadcrumbs: [inventoryBreadcrumb, { title: 'Inventários', href: index() }],
};
