import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
import NativeTextarea from '@/components/native-textarea';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTenant } from '@/hooks/use-tenant';
import { formatDecimal } from '@/lib/decimal';
import { show } from '@/routes/products';
import { reverse } from '@/routes/products/movements';
import type { Movement } from '@/types';

function ReverseDialog({ movement }: { movement: Movement }) {
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({ reason: '' });
    // O serviço de estoque também devolve erros que não pertencem ao campo do formulário.
    const errors: Partial<Record<string, string>> = form.errors;

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                form.resetAndClearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    Estornar
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Estornar movimentação</DialogTitle>
                <DialogDescription>
                    Uma movimentação compensatória será criada para{' '}
                    {movement.item}. O lançamento original é mantido.
                </DialogDescription>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(reverse(movement.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <FormField
                        id={`reason-${movement.id}`}
                        label="Motivo"
                        error={errors.reason}
                    >
                        <NativeTextarea
                            id={`reason-${movement.id}`}
                            required
                            maxLength={500}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    <InputError
                        message={
                            errors.movement ??
                            errors.quantity ??
                            errors.product_variant_id
                        }
                    />
                    <DialogFooter>
                        <Button
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Confirmar estorno
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function MovementTable({
    movements,
}: {
    movements: Movement[];
}) {
    const { can, formatDateTime } = useTenant();
    const canReverse = can('products.adjust_inventory');

    if (movements.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Nenhuma movimentação encontrada.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="p-3 font-medium">Data</th>
                        <th className="p-3 font-medium">Item</th>
                        <th className="p-3 font-medium">Tipo</th>
                        <th className="p-3 text-right font-medium">
                            Quantidade
                        </th>
                        <th className="p-3 text-right font-medium">Saldo</th>
                        <th className="p-3 font-medium">Origem</th>
                        <th className="p-3 font-medium">Responsável</th>
                        <th className="p-3 font-medium">Motivo</th>
                        {canReverse && (
                            <th className="p-3">
                                <span className="sr-only">Ações</span>
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {movements.map((movement) => (
                        <tr key={movement.id}>
                            <td className="p-3 whitespace-nowrap">
                                {formatDateTime(movement.occurred_at)}
                            </td>
                            <td className="p-3">
                                <Link
                                    href={show(movement.product_id)}
                                    className="hover:underline"
                                >
                                    {movement.item}
                                </Link>
                                {movement.sku !== null && (
                                    <p className="text-muted-foreground">
                                        {movement.sku}
                                    </p>
                                )}
                            </td>
                            <td className="p-3">
                                {movement.type_label}
                                {movement.reversal_of_id !== null && (
                                    <p className="text-muted-foreground">
                                        do lançamento #{movement.reversal_of_id}
                                    </p>
                                )}
                                {movement.is_reversed && (
                                    <Badge variant="outline">Estornada</Badge>
                                )}
                            </td>
                            <td className="p-3 text-right whitespace-nowrap">
                                {movement.quantity.startsWith('-') ? '' : '+'}
                                {formatDecimal(movement.quantity)}{' '}
                                {movement.unit}
                            </td>
                            <td className="p-3 text-right whitespace-nowrap">
                                {formatDecimal(movement.balance_before)} →{' '}
                                {formatDecimal(movement.balance_after)}
                            </td>
                            <td className="p-3">
                                {movement.origin_label}
                                {movement.reference_id !== null && (
                                    <p className="text-muted-foreground">
                                        {movement.reference_type} #
                                        {movement.reference_id}
                                    </p>
                                )}
                            </td>
                            <td className="p-3">{movement.user_name ?? '—'}</td>
                            <td className="p-3">{movement.reason ?? '—'}</td>
                            {canReverse && (
                                <td className="p-3 text-right">
                                    {movement.type !== 'reversal' &&
                                        !movement.is_reversed && (
                                            <ReverseDialog
                                                movement={movement}
                                            />
                                        )}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
