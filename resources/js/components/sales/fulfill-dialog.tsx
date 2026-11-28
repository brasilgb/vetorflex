import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDecimal, parseDecimal, toDecimalInput } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { store } from '@/routes/sales/fulfillments';
import type { Option, SaleOrder } from '@/types';

/** Atendimento integral ou parcial. As quantidades partem do saldo pendente; zero deixa o item para depois. */
export default function FulfillDialog({
    order,
    methods,
    today,
    stockEnabled,
}: {
    order: SaleOrder;
    methods: Option[];
    today: string;
    stockEnabled: boolean;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const pending = order.items.filter(
        (item) => Number(item.pending_quantity) > 0,
    );
    const initial = () => ({
        fulfilled_at: today,
        method: order.delivery_method.value,
        notes: '',
        idempotency_key: uuid(),
        items: pending.map((item) => ({
            sale_order_item_id: item.id,
            quantity: toDecimalInput(item.pending_quantity),
        })),
    });
    const form = useForm(initial());
    const { data, setData } = form;
    const errors = form.errors as Record<string, string>;

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);

                if (open) {
                    form.setData(initial());
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button type="button">Registrar atendimento</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((current) => ({
                            ...current,
                            items: current.items.map((item) => ({
                                ...item,
                                quantity: parseDecimal(item.quantity || '0'),
                            })),
                        }));
                        form.post(store.url(order.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>
                        Atendimento da venda #{order.number}
                    </DialogTitle>
                    <DialogDescription>
                        {stockEnabled
                            ? 'Itens com estoque controlado saem do estoque ao confirmar.'
                            : 'O estoque não está habilitado: nenhuma movimentação será registrada.'}{' '}
                        O atendimento não registra pagamento.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="fulfilled-at"
                            label="Data"
                            error={errors.fulfilled_at}
                        >
                            <Input
                                id="fulfilled-at"
                                type="date"
                                required
                                value={data.fulfilled_at}
                                onChange={(event) =>
                                    setData('fulfilled_at', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="fulfillment-method"
                            label="Forma de atendimento"
                            error={errors.method}
                        >
                            <NativeSelect
                                id="fulfillment-method"
                                value={data.method}
                                onChange={(event) =>
                                    setData('method', event.target.value)
                                }
                            >
                                {methods.map((method) => (
                                    <option
                                        key={method.value}
                                        value={method.value}
                                    >
                                        {method.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    </div>
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className="p-2">Item</th>
                                <th className="p-2 text-right">Pendente</th>
                                <th className="w-32 p-2">Atender</th>
                            </tr>
                        </thead>
                        <tbody>
                            {pending.map((item, index) => (
                                <tr
                                    key={item.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-2">{item.description}</td>
                                    <td className="p-2 text-right whitespace-nowrap">
                                        {formatDecimal(item.pending_quantity)}{' '}
                                        {item.unit_code}
                                    </td>
                                    <td className="p-2">
                                        <Input
                                            aria-label={`Quantidade atendida de ${item.description}`}
                                            inputMode="decimal"
                                            value={
                                                data.items[index]?.quantity ??
                                                ''
                                            }
                                            onChange={(event) =>
                                                setData(
                                                    'items',
                                                    data.items.map(
                                                        (row, rowIndex) =>
                                                            rowIndex === index
                                                                ? {
                                                                      ...row,
                                                                      quantity:
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : row,
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `items.${index}.quantity`
                                                ]
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <InputError
                        message={
                            errors.items ??
                            errors.quantity ??
                            errors.idempotency_key
                        }
                    />
                    <FormField
                        id="fulfillment-notes"
                        label="Observações"
                        error={errors.notes}
                    >
                        <NativeTextarea
                            id="fulfillment-notes"
                            value={data.notes}
                            onChange={(event) =>
                                setData('notes', event.target.value)
                            }
                        />
                    </FormField>
                    <DialogFooter>
                        <Button disabled={form.processing}>
                            Confirmar atendimento
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
