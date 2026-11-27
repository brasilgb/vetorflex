import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { paymentsBreadcrumb } from '@/components/payments/payments-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { csv, index } from '@/routes/payments/reports';
import type { PaymentIndicators } from '@/types';

export default function PaymentReports({
    filters,
    summary,
    days,
}: {
    filters: { from: string; until: string };
    summary: PaymentIndicators;
    days: { day: string; count: number; gross: string; refunded: string }[];
}) {
    const { can } = useTenant();
    const form = useForm({ from: filters.from, until: filters.until });

    return (
        <>
            <Head title="Relatórios de pagamentos" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Relatórios de pagamentos"
                        description="Recebimentos confirmados pelo provedor, por dia de pagamento."
                    />
                    {can('payments.export') && (
                        <Button variant="outline" asChild>
                            <a href={csv.url({ query: filters })}>
                                Exportar recebimentos (CSV)
                            </a>
                        </Button>
                    )}
                </div>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(index.url(), { preserveScroll: true });
                    }}
                >
                    <Input
                        type="date"
                        aria-label="Período: início"
                        className="w-40"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Período: fim"
                        className="w-40"
                        value={form.data.until}
                        onChange={(event) =>
                            form.setData('until', event.target.value)
                        }
                    />
                    <Button variant="outline" disabled={form.processing}>
                        Gerar
                    </Button>
                </form>
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        ['Bruto recebido', formatMoney(summary.gross)],
                        ['Devolvido', formatMoney(summary.refunded)],
                        [
                            'Recebido líquido de devoluções',
                            formatMoney(summary.received),
                        ],
                        [
                            'Taxas informadas pelo provedor',
                            summary.fees === null
                                ? 'Não informadas'
                                : formatMoney(summary.fees),
                        ],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-3">
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-lg font-semibold">{value}</dd>
                        </div>
                    ))}
                </dl>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Dia</th>
                                <th className="p-3 text-right">Pagamentos</th>
                                <th className="p-3 text-right">Bruto</th>
                                <th className="p-3 text-right">Devolvido</th>
                            </tr>
                        </thead>
                        <tbody>
                            {days.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhum recebimento no período.
                                    </td>
                                </tr>
                            )}
                            {days.map((day) => (
                                <tr key={day.day} className="border-t">
                                    <td className="p-3">
                                        {formatDate(day.day)}
                                    </td>
                                    <td className="p-3 text-right">
                                        {day.count}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(day.gross)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(day.refunded)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

PaymentReports.layout = {
    breadcrumbs: [paymentsBreadcrumb, { title: 'Relatórios', href: index() }],
};
