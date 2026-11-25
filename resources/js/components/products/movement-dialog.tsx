import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import ItemLookup from '@/components/products/item-lookup';
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
import { store } from '@/routes/products/movements';

type Item = { id: number; name: string; unit: string; balance: string | null };

const TYPES = [
    { value: 'entry', label: 'Entrada' },
    { value: 'exit', label: 'Saída' },
    { value: 'adjustment', label: 'Ajuste (saldo contado)' },
    { value: 'initial', label: 'Estoque inicial' },
];

function MovementForm({
    fixedItem,
    onSaved,
}: {
    fixedItem?: Item;
    onSaved: () => void;
}) {
    const [item, setItem] = useState<Item | null>(fixedItem ?? null);
    const form = useForm({
        product_variant_id: fixedItem?.id ?? null,
        type: 'entry',
        quantity: '',
        reason: '',
        // A chave acompanha o formulário aberto: reenviar o mesmo lançamento não o duplica.
        idempotency_key: uuid(),
    });
    const { data, setData, errors } = form;
    const isAdjustment = data.type === 'adjustment';

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((current) => ({
            ...current,
            quantity: parseDecimal(current.quantity),
        }));
        form.submit(store(), { preserveScroll: true, onSuccess: onSaved });
    }

    return (
        <form className="space-y-4" onSubmit={submit}>
            <FormField
                id="movement-item"
                label="Item"
                error={errors.product_variant_id}
            >
                {item !== null && (
                    <p className="text-sm">
                        {item.name}
                        {item.balance !== null && (
                            <span className="text-muted-foreground">
                                {' '}
                                · Saldo: {formatDecimal(item.balance)}{' '}
                                {item.unit}
                            </span>
                        )}
                    </p>
                )}
                {fixedItem === undefined && (
                    <ItemLookup
                        id="movement-item"
                        onSelect={(found) => {
                            setItem({
                                id: found.id,
                                name: found.name,
                                unit: found.unit.code,
                                balance: found.balance,
                            });
                            setData('product_variant_id', found.id);
                        }}
                    />
                )}
            </FormField>
            <div className="grid gap-4 sm:grid-cols-2">
                <FormField id="movement-type" label="Tipo" error={errors.type}>
                    <NativeSelect
                        id="movement-type"
                        value={data.type}
                        onChange={(event) =>
                            setData('type', event.target.value)
                        }
                    >
                        {TYPES.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </NativeSelect>
                </FormField>
                <FormField
                    id="movement-quantity"
                    label={isAdjustment ? 'Saldo contado' : 'Quantidade'}
                    error={errors.quantity}
                >
                    <Input
                        id="movement-quantity"
                        inputMode="decimal"
                        required
                        value={data.quantity}
                        onChange={(event) =>
                            setData('quantity', event.target.value)
                        }
                    />
                </FormField>
            </div>
            <FormField
                id="movement-reason"
                label={isAdjustment ? 'Motivo' : 'Motivo (opcional)'}
                error={errors.reason}
            >
                <NativeTextarea
                    id="movement-reason"
                    maxLength={500}
                    required={isAdjustment}
                    value={data.reason}
                    onChange={(event) => setData('reason', event.target.value)}
                />
            </FormField>
            <InputError message={errors.idempotency_key} />
            <DialogFooter>
                <Button disabled={form.processing || item === null}>
                    Registrar
                </Button>
            </DialogFooter>
        </form>
    );
}

export default function MovementDialog({ item }: { item?: Item }) {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    size={item === undefined ? 'default' : 'sm'}
                    variant={item === undefined ? 'default' : 'outline'}
                >
                    {item === undefined ? 'Nova movimentação' : 'Movimentar'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Registrar movimentação</DialogTitle>
                <DialogDescription>
                    O histórico é preservado. Para corrigir um lançamento, use o
                    estorno.
                </DialogDescription>
                <MovementForm
                    fixedItem={item}
                    onSaved={() => setIsOpen(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
