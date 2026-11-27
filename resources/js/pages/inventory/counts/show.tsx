import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { inventoryBreadcrumb } from '@/components/inventory/inventory-nav';
import ReasonDialog from '@/components/reason-dialog';
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
import { formatDecimal, parseDecimal, toDecimalInput } from '@/lib/decimal';
import { approve, cancel, index, update } from '@/routes/inventory/counts';
import type { StockCount } from '@/types';

type CountItem = {
    id: number;
    name: string;
    sku: string | null;
    unit: { code: string; allows_fraction: boolean };
    counted_quantity: string | null;
    system_quantity: string | null;
    difference: string | null;
    adjusted: boolean;
};

/** Diferença exibida antes da aprovação, calculada em décimos de milésimo (inteiros), sem ponto flutuante. */
function previewDifference(
    counted: string,
    system: string | null,
): string | null {
    const parsed = parseDecimal(counted);

    if (parsed === '' || system === null || !/^-?\d+(\.\d+)?$/.test(parsed)) {
        return null;
    }

    const scaled = (value: string): bigint => {
        const [integer, fraction = ''] = value.replace('-', '').split('.');
        const result = BigInt(integer + fraction.padEnd(4, '0').slice(0, 4));

        return value.startsWith('-') ? -result : result;
    };
    const difference = scaled(parsed) - scaled(system);
    const sign = difference < 0n ? '-' : '';
    const absolute = (difference < 0n ? -difference : difference)
        .toString()
        .padStart(5, '0');

    return `${sign}${absolute.slice(0, -4)}.${absolute.slice(-4)}`;
}

function ApproveDialog({
    countId,
    divergences,
}: {
    countId: number;
    divergences: number;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button>Aprovar inventário</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Aprovar inventário</DialogTitle>
                <DialogDescription>
                    Salve as contagens antes. A aprovação compara cada contagem
                    com o saldo atual do depósito e gera os ajustes (
                    {divergences} divergência(s) na prévia). Itens sem contagem
                    não são ajustados. Depois de aprovado, o inventário não pode
                    ser alterado.
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            Voltar
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        disabled={isProcessing}
                        onClick={() =>
                            router.post(
                                approve.url(countId),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setIsProcessing(true),
                                    onFinish: () => setIsProcessing(false),
                                    onSuccess: () => setIsOpen(false),
                                },
                            )
                        }
                    >
                        Aprovar e ajustar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function StockCountShow({
    count,
    items,
    canCount,
    canApprove,
}: {
    count: StockCount;
    items: CountItem[];
    canCount: boolean;
    canApprove: boolean;
}) {
    const { formatDateTime } = useTenant();
    const form = useForm<{ counts: Record<number, string> }>({
        counts: Object.fromEntries(
            items.map((item) => [
                item.id,
                toDecimalInput(item.counted_quantity),
            ]),
        ),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const isOpen = count.status === 'open';
    const rows = items.map((item) => ({
        ...item,
        preview: isOpen
            ? previewDifference(
                  form.data.counts[item.id] ?? '',
                  item.system_quantity,
              )
            : item.difference,
    }));
    const divergences = rows.filter(
        (row) => row.preview !== null && !/^-?0+(\.0+)?$/.test(row.preview),
    ).length;

    return (
        <>
            <Head title={`Inventário #${count.number}`} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <Heading
                            variant="small"
                            title={`Inventário #${count.number} · ${count.warehouse}`}
                            description={`${count.description ?? 'Sem descrição'}. Criado por ${count.created_by_name ?? '—'}${count.created_at ? ` em ${formatDateTime(count.created_at)}` : ''}.`}
                        />
                        <Badge variant={isOpen ? 'default' : 'secondary'}>
                            {count.status_label}
                        </Badge>
                        {count.approved_at && (
                            <p className="text-sm text-muted-foreground">
                                Aprovado por {count.approved_by_name} em{' '}
                                {formatDateTime(count.approved_at)}.
                            </p>
                        )}
                        {count.cancellation_reason && (
                            <p className="text-sm text-muted-foreground">
                                Cancelado por {count.cancelled_by_name}:{' '}
                                {count.cancellation_reason}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canCount && (
                            <ReasonDialog
                                url={cancel.url(count.id)}
                                title="Cancelar inventário"
                                description="Nenhum ajuste será gerado. O inventário fica no histórico."
                                confirmLabel="Cancelar inventário"
                                variant="outline"
                            >
                                Cancelar
                            </ReasonDialog>
                        )}
                        {canApprove && (
                            <ApproveDialog
                                countId={count.id}
                                divergences={divergences}
                            />
                        )}
                    </div>
                </div>
                {errors.count && (
                    <p className="text-sm text-destructive">{errors.count}</p>
                )}
                {errors.counts && (
                    <p className="text-sm text-destructive">{errors.counts}</p>
                )}
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            counts: Object.fromEntries(
                                Object.entries(data.counts).map(
                                    ([id, value]) => [
                                        id,
                                        value.trim() === ''
                                            ? null
                                            : parseDecimal(value),
                                    ],
                                ),
                            ),
                        }));
                        form.put(update.url(count.id), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Mercadoria</th>
                                    <th className="p-3 text-right">
                                        {isOpen
                                            ? 'Saldo atual'
                                            : 'Saldo no sistema'}
                                    </th>
                                    <th className="p-3 text-right">Contado</th>
                                    <th className="p-3 text-right">
                                        Diferença
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="p-3">
                                            {row.name}
                                            <span className="block text-xs text-muted-foreground">
                                                {row.sku ?? 'Sem SKU'}
                                                {row.adjusted
                                                    ? ' · ajustado'
                                                    : ''}
                                            </span>
                                            {errors[`counts.${row.id}`] && (
                                                <span className="block text-xs text-destructive">
                                                    {errors[`counts.${row.id}`]}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {row.system_quantity === null
                                                ? '—'
                                                : `${formatDecimal(row.system_quantity)} ${row.unit.code}`}
                                        </td>
                                        <td className="p-3 text-right">
                                            {canCount ? (
                                                <Input
                                                    aria-label={`Contagem de ${row.name}`}
                                                    inputMode="decimal"
                                                    className="ml-auto w-28 text-right"
                                                    value={
                                                        form.data.counts[
                                                            row.id
                                                        ] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        form.setData('counts', {
                                                            ...form.data.counts,
                                                            [row.id]:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                />
                                            ) : row.counted_quantity ===
                                              null ? (
                                                '—'
                                            ) : (
                                                formatDecimal(
                                                    row.counted_quantity,
                                                )
                                            )}
                                        </td>
                                        <td
                                            className={`p-3 text-right whitespace-nowrap ${row.preview?.startsWith('-') ? 'text-destructive' : ''}`}
                                        >
                                            {row.preview === null
                                                ? '—'
                                                : formatDecimal(row.preview)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {canCount && (
                        <Button variant="outline" disabled={form.processing}>
                            Salvar contagens
                        </Button>
                    )}
                </form>
            </div>
        </>
    );
}

StockCountShow.layout = {
    breadcrumbs: [
        inventoryBreadcrumb,
        { title: 'Inventários', href: index() },
        { title: 'Inventário', href: '#' },
    ],
};
