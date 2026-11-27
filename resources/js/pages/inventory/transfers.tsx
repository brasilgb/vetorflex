import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import { inventoryBreadcrumb } from '@/components/inventory/inventory-nav';
import ItemPicker from '@/components/inventory/item-picker';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
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
import { formatDecimal, parseDecimal } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { index, store } from '@/routes/inventory/transfers';
import type {
    Paginated,
    StockItem,
    StockTransfer,
    StockWarehouseOption,
} from '@/types';

type Line = { item: StockItem; quantity: string };

function TransferDialog({
    warehouses,
}: {
    warehouses: StockWarehouseOption[];
}) {
    const [isOpen, setIsOpen] = useState(false);
    const [lines, setLines] = useState<Line[]>([]);
    const initial = {
        from_warehouse_id: String(warehouses[0]?.id ?? ''),
        to_warehouse_id: String(warehouses[1]?.id ?? ''),
        reason: '',
        idempotency_key: uuid(),
    };
    const form = useForm(initial);
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.setData({ ...initial, idempotency_key: uuid() });
                form.clearErrors();
                setLines([]);
            }}
        >
            <DialogTrigger asChild>
                <Button disabled={warehouses.length < 2}>
                    Nova transferência
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            from_warehouse_id: Number(data.from_warehouse_id),
                            to_warehouse_id: Number(data.to_warehouse_id),
                            reason: data.reason || null,
                            items: lines.map((line) => ({
                                product_variant_id: line.item.id,
                                quantity: parseDecimal(line.quantity),
                            })),
                        }));
                        form.post(store.url(), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Transferência entre depósitos</DialogTitle>
                    <DialogDescription>
                        O saldo total não muda: sai da origem e entra no destino
                        na mesma operação.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="transfer-from"
                            label="Origem"
                            error={errors.from_warehouse_id}
                        >
                            <NativeSelect
                                id="transfer-from"
                                value={form.data.from_warehouse_id}
                                onChange={(event) => {
                                    form.setData(
                                        'from_warehouse_id',
                                        event.target.value,
                                    );
                                    setLines([]);
                                }}
                            >
                                {warehouses.map((warehouse) => (
                                    <option
                                        key={warehouse.id}
                                        value={warehouse.id}
                                    >
                                        {warehouse.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="transfer-to"
                            label="Destino"
                            error={errors.to_warehouse_id}
                        >
                            <NativeSelect
                                id="transfer-to"
                                value={form.data.to_warehouse_id}
                                onChange={(event) =>
                                    form.setData(
                                        'to_warehouse_id',
                                        event.target.value,
                                    )
                                }
                            >
                                {warehouses.map((warehouse) => (
                                    <option
                                        key={warehouse.id}
                                        value={warehouse.id}
                                    >
                                        {warehouse.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    </div>
                    <FormField
                        id="transfer-item"
                        label="Adicionar mercadoria"
                        error={errors.items}
                    >
                        <ItemPicker
                            id="transfer-item"
                            warehouseId={Number(form.data.from_warehouse_id)}
                            onSelect={(item) =>
                                setLines((current) =>
                                    current.some(
                                        (line) => line.item.id === item.id,
                                    )
                                        ? current
                                        : [...current, { item, quantity: '' }],
                                )
                            }
                        />
                    </FormField>
                    {lines.length > 0 && (
                        <ul className="space-y-2">
                            {lines.map((line, position) => (
                                <li
                                    key={line.item.id}
                                    className="grid grid-cols-[1fr_7rem_auto] items-center gap-2 text-sm"
                                >
                                    <span>
                                        {line.item.name}
                                        <span className="block text-xs text-muted-foreground">
                                            Na origem:{' '}
                                            {formatDecimal(
                                                line.item.warehouse_quantity ??
                                                    '0',
                                            )}{' '}
                                            {line.item.unit.code}
                                        </span>
                                        {errors[
                                            `items.${position}.quantity`
                                        ] && (
                                            <span className="block text-xs text-destructive">
                                                {
                                                    errors[
                                                        `items.${position}.quantity`
                                                    ]
                                                }
                                            </span>
                                        )}
                                    </span>
                                    <Input
                                        aria-label={`Quantidade de ${line.item.name}`}
                                        inputMode="decimal"
                                        required
                                        value={line.quantity}
                                        onChange={(event) =>
                                            setLines((current) =>
                                                current.map((other) =>
                                                    other.item.id ===
                                                    line.item.id
                                                        ? {
                                                              ...other,
                                                              quantity:
                                                                  event.target
                                                                      .value,
                                                          }
                                                        : other,
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setLines((current) =>
                                                current.filter(
                                                    (other) =>
                                                        other.item.id !==
                                                        line.item.id,
                                                ),
                                            )
                                        }
                                    >
                                        Remover
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {errors.quantity && (
                        <p className="text-sm text-destructive">
                            {errors.quantity}
                        </p>
                    )}
                    <FormField
                        id="transfer-reason"
                        label="Observação"
                        error={errors.reason}
                    >
                        <Input
                            id="transfer-reason"
                            maxLength={500}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button
                            disabled={form.processing || lines.length === 0}
                        >
                            Transferir
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function StockTransfers({
    transfers,
    warehouses,
}: {
    transfers: Paginated<StockTransfer>;
    warehouses: StockWarehouseOption[];
}) {
    const { can, formatDateTime } = useTenant();

    return (
        <>
            <Head title="Transferências" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Transferências"
                        description={
                            warehouses.length < 2
                                ? 'Cadastre ao menos dois depósitos ativos para transferir mercadorias.'
                                : 'Movimentação entre depósitos da empresa, sem alterar o saldo total nem o custo médio.'
                        }
                    />
                    {can('inventory.transfer') && (
                        <TransferDialog warehouses={warehouses} />
                    )}
                </div>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Transferência</th>
                                <th className="p-3">Origem → destino</th>
                                <th className="p-3">Itens</th>
                                <th className="p-3">Responsável</th>
                            </tr>
                        </thead>
                        <tbody>
                            {transfers.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhuma transferência registrada.
                                    </td>
                                </tr>
                            )}
                            {transfers.data.map((transfer) => (
                                <tr
                                    key={transfer.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <span className="font-medium">
                                            #{transfer.number}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDateTime(
                                                transfer.transferred_at,
                                            )}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {transfer.from} → {transfer.to}
                                    </td>
                                    <td className="p-3">
                                        {transfer.items.map(
                                            (item, position) => (
                                                <span
                                                    key={position}
                                                    className="block"
                                                >
                                                    {item.item}:{' '}
                                                    {formatDecimal(
                                                        item.quantity,
                                                    )}{' '}
                                                    {item.unit}
                                                </span>
                                            ),
                                        )}
                                    </td>
                                    <td className="p-3">
                                        {transfer.user_name ?? '—'}
                                        {transfer.reason && (
                                            <span className="block text-xs text-muted-foreground">
                                                {transfer.reason}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={transfers.links} />
            </div>
        </>
    );
}

StockTransfers.layout = {
    breadcrumbs: [
        inventoryBreadcrumb,
        { title: 'Transferências', href: index() },
    ],
};
