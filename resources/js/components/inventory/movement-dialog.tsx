import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import ItemPicker from '@/components/inventory/item-picker';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
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
import { formatDecimal, parseDecimal } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { store } from '@/routes/inventory/movements';
import type { StockItem, StockWarehouseOption } from '@/types';

const TYPES: { value: string; label: string; adjust: boolean; hint: string }[] =
    [
        {
            value: 'entry',
            label: 'Entrada',
            adjust: false,
            hint: 'Soma a quantidade ao saldo do depósito.',
        },
        {
            value: 'exit',
            label: 'Saída',
            adjust: false,
            hint: 'Retira a quantidade do depósito. Exige justificativa.',
        },
        {
            value: 'adjustment',
            label: 'Ajuste (saldo contado)',
            adjust: true,
            hint: 'Informe o saldo real do depósito; a diferença é registrada. Exige justificativa.',
        },
        {
            value: 'initial',
            label: 'Saldo inicial',
            adjust: true,
            hint: 'Somente antes da primeira movimentação da mercadoria.',
        },
    ];

/** Lançamento manual de estoque: entrada, saída, ajuste ou saldo inicial, por depósito. */
export default function MovementDialog({
    warehouses,
    canMove,
    canAdjust,
    canViewCost = false,
    item: fixedItem,
    trigger = 'Nova movimentação',
}: {
    warehouses: StockWarehouseOption[];
    canMove: boolean;
    canAdjust: boolean;
    canViewCost?: boolean;
    item?: StockItem;
    trigger?: string;
}) {
    const types = TYPES.filter((type) => (type.adjust ? canAdjust : canMove));
    const [isOpen, setIsOpen] = useState(false);
    const [item, setItem] = useState<StockItem | undefined>(fixedItem);
    const initial = {
        type: types[0]?.value ?? 'entry',
        inventory_warehouse_id: String(
            warehouses.find((warehouse) => warehouse.is_default)?.id ??
                warehouses[0]?.id ??
                '',
        ),
        quantity: '',
        unit_cost: '',
        reason: '',
        idempotency_key: uuid(),
    };
    const form = useForm(initial);
    const selectedType = TYPES.find((type) => type.value === form.data.type);

    if (types.length === 0) {
        return null;
    }

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.setData({ ...initial, idempotency_key: uuid() });
                form.clearErrors();
                setItem(fixedItem);
            }}
        >
            <DialogTrigger asChild>
                <Button variant={fixedItem ? 'outline' : 'default'}>
                    {trigger}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            product_variant_id: item?.id,
                            inventory_warehouse_id:
                                data.inventory_warehouse_id === ''
                                    ? null
                                    : Number(data.inventory_warehouse_id),
                            quantity: parseDecimal(data.quantity),
                            unit_cost:
                                data.unit_cost === ''
                                    ? null
                                    : parseDecimal(data.unit_cost),
                        }));
                        form.post(store.url(), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Movimentação de estoque</DialogTitle>
                    <DialogDescription>
                        Registros não são apagados: corrija lançamentos manuais
                        pelo estorno.
                    </DialogDescription>
                    {fixedItem === undefined && (
                        <FormField
                            id="movement-item"
                            label="Mercadoria"
                            error={
                                (form.errors as Record<string, string>)
                                    .product_variant_id
                            }
                        >
                            {item ? (
                                <div className="flex items-center justify-between gap-2 rounded-md border p-2 text-sm">
                                    <span>
                                        {item.name}
                                        <span className="block text-xs text-muted-foreground">
                                            Saldo total{' '}
                                            {formatDecimal(item.balance)}{' '}
                                            {item.unit.code}
                                        </span>
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setItem(undefined)}
                                    >
                                        Trocar
                                    </Button>
                                </div>
                            ) : (
                                <ItemPicker
                                    id="movement-item"
                                    onSelect={setItem}
                                />
                            )}
                        </FormField>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="movement-type"
                            label="Tipo"
                            error={form.errors.type}
                            hint={selectedType?.hint}
                        >
                            <NativeSelect
                                id="movement-type"
                                value={form.data.type}
                                onChange={(event) =>
                                    form.setData('type', event.target.value)
                                }
                            >
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="movement-warehouse"
                            label="Depósito"
                            error={form.errors.inventory_warehouse_id}
                        >
                            <NativeSelect
                                id="movement-warehouse"
                                value={form.data.inventory_warehouse_id}
                                onChange={(event) =>
                                    form.setData(
                                        'inventory_warehouse_id',
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
                        <FormField
                            id="movement-quantity"
                            label={
                                form.data.type === 'adjustment'
                                    ? 'Saldo contado'
                                    : 'Quantidade'
                            }
                            error={form.errors.quantity}
                        >
                            <Input
                                id="movement-quantity"
                                inputMode="decimal"
                                autoComplete="off"
                                required
                                value={form.data.quantity}
                                onChange={(event) =>
                                    form.setData('quantity', event.target.value)
                                }
                            />
                        </FormField>
                        {canViewCost &&
                            (form.data.type === 'entry' ||
                                form.data.type === 'initial') && (
                                <FormField
                                    id="movement-cost"
                                    label="Custo unitário (opcional)"
                                    error={form.errors.unit_cost}
                                >
                                    <Input
                                        id="movement-cost"
                                        inputMode="decimal"
                                        autoComplete="off"
                                        placeholder="0,00"
                                        value={form.data.unit_cost}
                                        onChange={(event) =>
                                            form.setData(
                                                'unit_cost',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                            )}
                    </div>
                    <FormField
                        id="movement-reason"
                        label="Justificativa"
                        error={form.errors.reason}
                    >
                        <NativeTextarea
                            id="movement-reason"
                            maxLength={500}
                            required={
                                form.data.type === 'exit' ||
                                form.data.type === 'adjustment'
                            }
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    {(form.errors as Record<string, string>)
                        .idempotency_key && (
                        <p className="text-sm text-destructive">
                            {
                                (form.errors as Record<string, string>)
                                    .idempotency_key
                            }
                        </p>
                    )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button
                            disabled={form.processing || item === undefined}
                        >
                            Registrar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
