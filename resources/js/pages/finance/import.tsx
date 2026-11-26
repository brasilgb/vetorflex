import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { index, store } from '@/routes/finance/import';

type PreviewRow = {
    source_id: number;
    label: string;
    party: string | null;
    date: string;
    amount: string;
    due: string;
    is_cancelled: boolean;
    is_imported: boolean;
    is_eligible: boolean;
};

const TYPE_LABELS: Record<string, string> = { sales: 'Vendas', purchases: 'Compras' };

export default function FinanceImport({
    types,
    filters,
    preview,
}: {
    types: string[];
    filters: { type: string | null; from: string | null; until: string | null };
    preview: PreviewRow[] | null;
}) {
    const form = useForm({ type: filters.type ?? '', from: filters.from ?? '', until: filters.until ?? '' });
    const eligible = (preview ?? []).filter((row) => row.is_eligible);
    const [selected, setSelected] = useState<number[]>([]);
    const [settled, setSettled] = useState<number[]>([]);
    const [isSaving, setIsSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const toggle = (list: number[], id: number, checked: boolean): number[] =>
        checked ? [...list, id] : list.filter((item) => item !== id);
    const isReceivable = filters.type === 'sales';

    return (
        <>
            <Head title="Importar documentos" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Importação assistida"
                    description="Traga para o Financeiro vendas e compras anteriores à contratação. Nada é considerado recebido ou pago sem sua indicação."
                />
                {types.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Nenhum módulo comercial habilitado. A importação fica disponível quando Vendas ou
                        Compras estiverem ativos para a empresa.
                    </p>
                ) : (
                    <>
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.get(index.url(), {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setSelected([]);
                                        setSettled([]);
                                    },
                                });
                            }}
                        >
                            <NativeSelect
                                aria-label="Origem"
                                className="w-40"
                                value={form.data.type}
                                onChange={(event) => form.setData('type', event.target.value)}
                            >
                                {types.map((type) => (
                                    <option key={type} value={type}>
                                        {TYPE_LABELS[type] ?? type}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                type="date"
                                required
                                aria-label="Período: início"
                                className="w-40"
                                value={form.data.from}
                                onChange={(event) => form.setData('from', event.target.value)}
                            />
                            <Input
                                type="date"
                                required
                                aria-label="Período: fim"
                                className="w-40"
                                value={form.data.until}
                                onChange={(event) => form.setData('until', event.target.value)}
                            />
                            <Button variant="outline" disabled={form.processing}>
                                Ver prévia
                            </Button>
                        </form>
                        {preview === null ? (
                            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                Escolha a origem e o período para ver os documentos elegíveis.
                            </p>
                        ) : (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {preview.length} documento(s) no período, {eligible.length} elegível(is).
                                    Marque “Já {isReceivable ? 'recebido' : 'pago'}” para documentos liquidados
                                    fora do sistema: recebem baixa externa, sem movimentar contas.
                                </p>
                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full text-sm">
                                        <thead className="bg-muted/50 text-left">
                                            <tr>
                                                <th className="p-3">
                                                    <Checkbox
                                                        aria-label="Selecionar todos os elegíveis"
                                                        checked={eligible.length > 0 && selected.length === eligible.length}
                                                        onCheckedChange={(value) =>
                                                            setSelected(value === true ? eligible.map((row) => row.source_id) : [])
                                                        }
                                                    />
                                                </th>
                                                <th className="p-3">Documento</th>
                                                <th className="p-3">Data</th>
                                                <th className="p-3 text-right">Valor</th>
                                                <th className="p-3 text-right">A lançar</th>
                                                <th className="p-3">Já {isReceivable ? 'recebido' : 'pago'}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {preview.length === 0 && (
                                                <tr>
                                                    <td colSpan={6} className="p-6 text-center text-muted-foreground">
                                                        Nenhum documento confirmado no período.
                                                    </td>
                                                </tr>
                                            )}
                                            {preview.map((row) => (
                                                <tr key={row.source_id} className="border-t align-top">
                                                    <td className="p-3">
                                                        {row.is_eligible && (
                                                            <Checkbox
                                                                aria-label={`Selecionar ${row.label}`}
                                                                checked={selected.includes(row.source_id)}
                                                                onCheckedChange={(value) => {
                                                                    setSelected(toggle(selected, row.source_id, value === true));

                                                                    if (value !== true) {
                                                                        setSettled(toggle(settled, row.source_id, false));
                                                                    }
                                                                }}
                                                            />
                                                        )}
                                                    </td>
                                                    <td className="p-3">
                                                        {row.label}
                                                        <span className="block text-xs text-muted-foreground">
                                                            {row.party ?? 'Sem identificação'}
                                                        </span>
                                                        {row.is_imported && <Badge variant="secondary">Já importado</Badge>}
                                                        {row.is_cancelled && <Badge variant="outline">Cancelado</Badge>}
                                                    </td>
                                                    <td className="p-3 whitespace-nowrap">{formatDate(row.date)}</td>
                                                    <td className="p-3 text-right whitespace-nowrap">{formatMoney(row.amount)}</td>
                                                    <td className="p-3 text-right whitespace-nowrap">{formatMoney(row.due)}</td>
                                                    <td className="p-3">
                                                        {row.is_eligible && (
                                                            <Checkbox
                                                                aria-label={`Marcar ${row.label} como liquidado externamente`}
                                                                disabled={!selected.includes(row.source_id)}
                                                                checked={settled.includes(row.source_id)}
                                                                onCheckedChange={(value) =>
                                                                    setSettled(toggle(settled, row.source_id, value === true))
                                                                }
                                                            />
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {error && <p className="text-sm text-destructive">{error}</p>}
                                <Button
                                    disabled={selected.length === 0 || isSaving}
                                    onClick={() =>
                                        router.post(
                                            store.url(),
                                            { ...filters, selected, settled_externally: settled },
                                            {
                                                preserveScroll: true,
                                                onStart: () => {
                                                    setIsSaving(true);
                                                    setError(null);
                                                },
                                                onFinish: () => setIsSaving(false),
                                                onError: (errors) => setError(Object.values(errors)[0] ?? null),
                                                onSuccess: () => {
                                                    setSelected([]);
                                                    setSettled([]);
                                                },
                                            },
                                        )
                                    }
                                >
                                    Importar {selected.length > 0 ? selected.length : ''} documento(s)
                                </Button>
                            </>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

FinanceImport.layout = {
    breadcrumbs: [financeBreadcrumb, { title: 'Importação assistida', href: index() }],
};
