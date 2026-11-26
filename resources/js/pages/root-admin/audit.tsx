import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { home } from '@/routes/root-admin';
import { index } from '@/routes/root-admin/audit';
import type { FinanceOption, Paginated, PlatformAuditEntry } from '@/types';

export default function PlatformAudit({
    logs,
    filters,
    companies,
}: {
    logs: Paginated<PlatformAuditEntry>;
    filters: { event: string | null; company: number | null; from: string | null; until: string | null };
    companies: FinanceOption[];
}) {
    const { formatDateTime } = useTenant();
    const form = useForm({
        event: filters.event ?? '',
        company: filters.company === null ? '' : String(filters.company),
        from: filters.from ?? '',
        until: filters.until ?? '',
    });

    return (
        <>
            <Head title="Auditoria" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Logs e auditoria"
                    description="Operações sensíveis de toda a plataforma, em ordem da mais recente. Os registros não podem ser alterados."
                />
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => Object.fromEntries(Object.entries(data).filter(([, value]) => value !== '')));
                        form.get(index.url(), { preserveScroll: true });
                    }}
                >
                    <Input
                        aria-label="Evento (início do nome)"
                        placeholder="Evento, ex.: finance. ou company."
                        className="w-full sm:w-64"
                        value={form.data.event}
                        onChange={(event) => form.setData('event', event.target.value)}
                    />
                    <NativeSelect
                        aria-label="Empresa"
                        className="w-52"
                        value={form.data.company}
                        onChange={(event) => form.setData('company', event.target.value)}
                    >
                        <option value="">Todas as empresas</option>
                        {companies.map((company) => (
                            <option key={company.id} value={company.id}>
                                {company.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input type="date" aria-label="A partir de" className="w-40" value={form.data.from} onChange={(event) => form.setData('from', event.target.value)} />
                    <Input type="date" aria-label="Até" className="w-40" value={form.data.until} onChange={(event) => form.setData('until', event.target.value)} />
                    <Button variant="outline" disabled={form.processing}>
                        Filtrar
                    </Button>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Quando</th>
                                <th className="p-3">Evento</th>
                                <th className="p-3">Responsável</th>
                                <th className="p-3">Empresa</th>
                                <th className="p-3">Detalhes</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-muted-foreground">
                                        Nenhum registro para os filtros escolhidos.
                                    </td>
                                </tr>
                            )}
                            {logs.data.map((log) => (
                                <tr key={log.id} className="border-t align-top">
                                    <td className="p-3 whitespace-nowrap">{formatDateTime(log.occurred_at)}</td>
                                    <td className="p-3 font-mono text-xs">{log.event}</td>
                                    <td className="p-3">{log.user ?? 'Sistema'}</td>
                                    <td className="p-3">{log.company ?? '—'}</td>
                                    <td className="p-3 text-xs text-muted-foreground">
                                        {log.subject}
                                        {Object.keys(log.context).length > 0 && (
                                            <code className="mt-1 block max-w-md break-all whitespace-pre-wrap">
                                                {JSON.stringify(log.context)}
                                            </code>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={logs.links} />
            </div>
        </>
    );
}

PlatformAudit.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: home() },
        { title: 'Auditoria', href: index() },
    ],
};
