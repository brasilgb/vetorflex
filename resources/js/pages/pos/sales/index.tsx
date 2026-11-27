import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { posBreadcrumb } from '@/components/pos/pos-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatMoney } from '@/lib/decimal';
import { index, show } from '@/routes/pos/sales';
import type { NamedRecord, Paginated, PosSaleData } from '@/types';

type Filters = {
    status: string | null;
    terminal: number | null;
    from: string | null;
    until: string | null;
    number: number | null;
};

const STATUS_VARIANT: Record<
    PosSaleData['status'],
    'secondary' | 'outline' | 'destructive'
> = {
    completed: 'secondary',
    suspended: 'outline',
    cancelled: 'destructive',
};

function saleDate(sale: PosSaleData): string {
    return sale.completed_at ?? sale.cancelled_at ?? sale.suspended_at ?? '';
}

export default function PosSales({
    sales,
    filters,
    terminals,
}: {
    sales: Paginated<PosSaleData>;
    filters: Filters;
    terminals: NamedRecord[];
}) {
    const { formatDateTime } = useTenant();
    const form = useForm({
        status: filters.status ?? '',
        terminal: filters.terminal === null ? '' : String(filters.terminal),
        from: filters.from ?? '',
        until: filters.until ?? '',
        number: filters.number === null ? '' : String(filters.number),
    });

    return (
        <>
            <Head title="Vendas do caixa" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Vendas do caixa"
                    description="Vendas concluídas, suspensas e canceladas no PDV. As concluídas são as mesmas vendas comerciais exibidas em Vendas."
                />
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) =>
                            Object.fromEntries(
                                Object.entries(data).filter(
                                    ([, value]) => value !== '',
                                ),
                            ),
                        );
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        type="number"
                        min={1}
                        aria-label="Número da venda"
                        placeholder="Nº da venda"
                        className="w-32"
                        value={form.data.number}
                        onChange={(event) =>
                            form.setData('number', event.target.value)
                        }
                    />
                    <NativeSelect
                        aria-label="Situação"
                        className="w-36"
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData('status', event.target.value)
                        }
                    >
                        <option value="">Todas</option>
                        <option value="completed">Concluídas</option>
                        <option value="suspended">Suspensas</option>
                        <option value="cancelled">Canceladas</option>
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Terminal"
                        className="w-44"
                        value={form.data.terminal}
                        onChange={(event) =>
                            form.setData('terminal', event.target.value)
                        }
                    >
                        <option value="">Todos os terminais</option>
                        {terminals.map((terminal) => (
                            <option key={terminal.id} value={terminal.id}>
                                {terminal.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input
                        type="date"
                        aria-label="A partir de"
                        className="w-40"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Até"
                        className="w-40"
                        value={form.data.until}
                        onChange={(event) =>
                            form.setData('until', event.target.value)
                        }
                    />
                    <Button variant="outline" disabled={form.processing}>
                        Filtrar
                    </Button>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Venda</th>
                                <th className="p-3">Terminal e operador</th>
                                <th className="p-3">Cliente</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3 text-right">Total</th>
                                <th className="p-3 text-right">Devolvido</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sales.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhuma venda encontrada.
                                    </td>
                                </tr>
                            )}
                            {sales.data.map((sale) => (
                                <tr
                                    key={sale.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <Link
                                            className="font-medium underline-offset-4 hover:underline"
                                            href={show(sale.id)}
                                        >
                                            {sale.order_number
                                                ? `#${sale.order_number}`
                                                : (sale.label ??
                                                  `PDV ${sale.id}`)}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {saleDate(sale) !== '' &&
                                                formatDateTime(saleDate(sale))}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {sale.terminal} · sessão{' '}
                                        {sale.session_number}
                                        <span className="block text-xs text-muted-foreground">
                                            {sale.user_name}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {sale.customer_name ?? 'Consumidor'}
                                    </td>
                                    <td className="p-3">
                                        <Badge
                                            variant={
                                                STATUS_VARIANT[sale.status]
                                            }
                                        >
                                            {sale.status_label}
                                        </Badge>
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(sale.total)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(sale.refunded_total)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={sales.links} />
            </div>
        </>
    );
}

PosSales.layout = {
    breadcrumbs: [posBreadcrumb, { title: 'Vendas do caixa', href: index() }],
};
