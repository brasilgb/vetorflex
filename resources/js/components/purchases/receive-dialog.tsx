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
import { formatDecimal, parseDecimal, toDecimalInput } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { store } from '@/routes/purchases/receipts';
import type { PurchaseOrder } from '@/types';

/**
 * Conferência do recebimento. As quantidades partem do saldo pendente; zero deixa o item para depois.
 */
export default function ReceiveDialog({
    order,
    today,
}: {
    order: PurchaseOrder;
    today: string;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const pending = order.items.filter(
        (item) => Number(item.pending_quantity) > 0,
    );
    const initial = () => ({
        received_at: today,
        document_reference: '',
        notes: '',
        idempotency_key: uuid(),
        items: pending.map((item) => ({
            purchase_order_item_id: item.id,
            quantity: toDecimalInput(item.pending_quantity),
            divergence: '',
        })),
    });
    const form = useForm(initial());
    const { data, setData, errors } = form;

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
                <Button type="button">Registrar recebimento</Button>
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
                        Recebimento do pedido #{order.number}
                    </DialogTitle>
                    <DialogDescription>
                        Confira as quantidades. A confirmação atualiza o estoque
                        e o custo médio dos itens com estoque controlado.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="received-at"
                            label="Data do recebimento"
                            error={errors.received_at}
                        >
                            <Input
                                id="received-at"
                                type="date"
                                required
                                value={data.received_at}
                                onChange={(event) =>
                                    setData('received_at', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="document-reference"
                            label="Nota ou documento do fornecedor"
                            error={errors.document_reference}
                        >
                            <Input
                                id="document-reference"
                                maxLength={100}
                                value={data.document_reference}
                                onChange={(event) =>
                                    setData(
                                        'document_reference',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                    </div>
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">Item</th>
                                    <th className="p-2 text-right">Pendente</th>
                                    <th className="p-2">Recebido</th>
                                    <th className="p-2">Divergência</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pending.map((item, index) => (
                                    <tr
                                        key={item.id}
                                        className="border-t align-top"
                                    >
                                        <td className="p-2">
                                            {item.description}
                                            {item.is_service && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Serviço: não movimenta
                                                    estoque.
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-2 text-right whitespace-nowrap">
                                            {formatDecimal(
                                                item.pending_quantity,
                                            )}{' '}
                                            {item.unit_code}
                                        </td>
                                        <td className="w-32 p-2">
                                            <Input
                                                aria-label={`Quantidade recebida de ${item.description}`}
                                                inputMode="decimal"
                                                value={
                                                    data.items[index]
                                                        ?.quantity ?? ''
                                                }
                                                onChange={(event) =>
                                                    setData(
                                                        'items',
                                                        data.items.map(
                                                            (row, rowIndex) =>
                                                                rowIndex ===
                                                                index
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
                                                    (
                                                        errors as Record<
                                                            string,
                                                            string
                                                        >
                                                    )[`items.${index}.quantity`]
                                                }
                                            />
                                        </td>
                                        <td className="min-w-40 p-2">
                                            <Input
                                                aria-label={`Divergência de ${item.description}`}
                                                maxLength={500}
                                                placeholder="Avaria, falta…"
                                                value={
                                                    data.items[index]
                                                        ?.divergence ?? ''
                                                }
                                                onChange={(event) =>
                                                    setData(
                                                        'items',
                                                        data.items.map(
                                                            (row, rowIndex) =>
                                                                rowIndex ===
                                                                index
                                                                    ? {
                                                                          ...row,
                                                                          divergence:
                                                                              event
                                                                                  .target
                                                                                  .value,
                                                                      }
                                                                    : row,
                                                        ),
                                                    )
                                                }
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <FormField
                        id="receipt-notes"
                        label="Observações"
                        error={errors.notes}
                    >
                        <NativeTextarea
                            id="receipt-notes"
                            value={data.notes}
                            onChange={(event) =>
                                setData('notes', event.target.value)
                            }
                        />
                    </FormField>
                    <InputError
                        message={
                            (errors as Record<string, string>).items ??
                            (errors as Record<string, string>).idempotency_key
                        }
                    />
                    <DialogFooter>
                        <Button disabled={form.processing}>
                            Confirmar recebimento
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
