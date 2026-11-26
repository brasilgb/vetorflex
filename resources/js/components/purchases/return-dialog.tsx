import { useForm } from '@inertiajs/react';
import { useState } from 'react';
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
import { store } from '@/routes/purchases/returns';
import type { PurchaseReceipt } from '@/types';

/** Devolução comercial ao fornecedor: gera saída de estoque e preserva o recebimento original. */
export default function ReturnDialog({
    receipt,
    today,
}: {
    receipt: PurchaseReceipt;
    today: string;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const returnable = receipt.items.filter(
        (item) => Number(item.returnable_quantity) > 0,
    );
    const initial = () => ({
        returned_at: today,
        reason: '',
        idempotency_key: uuid(),
        items: returnable.map((item) => ({
            purchase_receipt_item_id: item.id,
            quantity: '',
        })),
    });
    const form = useForm(initial());
    const { data, setData, errors } = form;

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
                    Devolver
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
                        form.post(store.url(receipt.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>
                        Devolução do recebimento #{receipt.number}
                    </DialogTitle>
                    <DialogDescription>
                        A saída é valorizada pelo custo médio vigente, que não é
                        alterado. Para corrigir um lançamento errado, use o
                        estorno do recebimento.
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
                    <ul className="space-y-2">
                        {returnable.map((item, index) => (
                            <li
                                key={item.id}
                                className="grid items-center gap-2 sm:grid-cols-[1fr_8rem]"
                            >
                                <span className="text-sm">
                                    {item.description}
                                    <span className="block text-xs text-muted-foreground">
                                        Disponível:{' '}
                                        {formatDecimal(
                                            item.returnable_quantity,
                                        )}{' '}
                                        {item.unit_code}
                                    </span>
                                </span>
                                <div>
                                    <Input
                                        aria-label={`Quantidade devolvida de ${item.description}`}
                                        inputMode="decimal"
                                        placeholder="0"
                                        value={
                                            data.items[index]?.quantity ?? ''
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
                                            (errors as Record<string, string>)[
                                                `items.${index}.quantity`
                                            ]
                                        }
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                    <FormField
                        id="return-reason"
                        label="Motivo"
                        error={errors.reason}
                    >
                        <NativeTextarea
                            id="return-reason"
                            required
                            maxLength={500}
                            value={data.reason}
                            onChange={(event) =>
                                setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    <InputError
                        message={(errors as Record<string, string>).items}
                    />
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
