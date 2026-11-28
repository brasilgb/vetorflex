import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
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
import { formatDecimal, parseDecimal } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { store } from '@/routes/sales/returns';
import type { SaleFulfillment } from '@/types';

/** Devolução comercial de itens atendidos. O valor devolvido é o histórico da venda. */
export default function SaleReturnDialog({
    fulfillment,
    today,
    stockEnabled,
}: {
    fulfillment: SaleFulfillment;
    today: string;
    stockEnabled: boolean;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const returnable = fulfillment.items.filter(
        (item) => Number(item.quantity) > Number(item.returned_quantity),
    );
    const initial = () => ({
        returned_at: today,
        reason: '',
        restock: true,
        idempotency_key: uuid(),
        items: returnable.map((item) => ({
            sale_fulfillment_item_id: item.id,
            quantity: '0',
        })),
    });
    const form = useForm(initial());
    const { data, setData } = form;
    const errors = form.errors as Record<string, string>;

    if (returnable.length === 0) {
        return null;
    }

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
                <Button type="button" variant="outline" size="sm">
                    Devolução
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
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
                        form.post(store.url(fulfillment.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>
                        Devolução do atendimento #{fulfillment.number}
                    </DialogTitle>
                    <DialogDescription>
                        O tratamento financeiro (estorno ou crédito ao cliente)
                        é feito à parte, no Financeiro.
                    </DialogDescription>
                    <FormField
                        id="returned-at"
                        label="Data"
                        error={errors.returned_at}
                    >
                        <Input
                            id="returned-at"
                            type="date"
                            required
                            value={data.returned_at}
                            onChange={(event) =>
                                setData('returned_at', event.target.value)
                            }
                        />
                    </FormField>
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className="p-2">Item</th>
                                <th className="p-2 text-right">Disponível</th>
                                <th className="w-32 p-2">Devolver</th>
                            </tr>
                        </thead>
                        <tbody>
                            {returnable.map((item, index) => (
                                <tr
                                    key={item.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-2">{item.description}</td>
                                    <td className="p-2 text-right whitespace-nowrap">
                                        {formatDecimal(
                                            String(
                                                Number(item.quantity) -
                                                    Number(
                                                        item.returned_quantity,
                                                    ),
                                            ),
                                        )}{' '}
                                        {item.unit_code}
                                    </td>
                                    <td className="p-2">
                                        <Input
                                            aria-label={`Quantidade devolvida de ${item.description}`}
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
                        id="return-reason"
                        label="Motivo"
                        error={errors.reason}
                    >
                        <NativeTextarea
                            id="return-reason"
                            required
                            maxLength={400}
                            value={data.reason}
                            onChange={(event) =>
                                setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    {stockEnabled && (
                        <CheckboxField
                            id="return-restock"
                            label="Devolver as mercadorias ao estoque"
                            checked={data.restock}
                            onChange={(checked) => setData('restock', checked)}
                        />
                    )}
                    <DialogFooter>
                        <Button disabled={form.processing}>
                            Registrar devolução
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
