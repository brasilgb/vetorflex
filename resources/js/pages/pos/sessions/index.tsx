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
import { index, show } from '@/routes/pos/sessions';
import type { NamedRecord, Paginated, PosSessionData } from '@/types';

type Filters = {
    terminal: number | null;
    status: string | null;
    from: string | null;
    until: string | null;
};

export default function PosSessions({
    sessions,
    filters,
    terminals,
}: {
    sessions: Paginated<PosSessionData>;
    filters: Filters;
    terminals: NamedRecord[];
}) {
    const { formatDateTime } = useTenant();
    const form = useForm({
        terminal: filters.terminal === null ? '' : String(filters.terminal),
        status: filters.status ?? '',
        from: filters.from ?? '',
        until: filters.until ?? '',
    });

    return (
        <>
            <Head title="Sessões de caixa" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Sessões de caixa"
                    description="Histórico de aberturas e fechamentos, com o dinheiro esperado, o contado e a divergência."
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
                    <NativeSelect
                        aria-label="Situação"
                        className="w-36"
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData('status', event.target.value)
                        }
                    >
                        <option value="">Todas</option>
                        <option value="open">Abertas</option>
                        <option value="closed">Fechadas</option>
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
                                <th className="p-3">Sessão</th>
                                <th className="p-3">Operador</th>
                                <th className="p-3">Abertura</th>
                                <th className="p-3">Fechamento</th>
                                <th className="p-3 text-right">Inicial</th>
                                <th className="p-3 text-right">Esperado</th>
                                <th className="p-3 text-right">Contado</th>
                                <th className="p-3 text-right">Divergência</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sessions.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhuma sessão encontrada.
                                    </td>
                                </tr>
                            )}
                            {sessions.data.map((session) => (
                                <tr
                                    key={session.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <Link
                                            className="font-medium underline-offset-4 hover:underline"
                                            href={show(session.id)}
                                        >
                                            {session.terminal.name} · nº{' '}
                                            {session.number}
                                        </Link>
                                        <span className="mt-1 block">
                                            <Badge
                                                variant={
                                                    session.status === 'open'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {session.status_label}
                                            </Badge>
                                        </span>
                                    </td>
                                    <td className="p-3">{session.user_name}</td>
                                    <td className="p-3 whitespace-nowrap">
                                        {formatDateTime(session.opened_at)}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {session.closed_at
                                            ? formatDateTime(session.closed_at)
                                            : '—'}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(session.opening_amount)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(session.expected_cash)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(session.counted_cash)}
                                    </td>
                                    <td
                                        className={`p-3 text-right whitespace-nowrap ${session.divergence !== null && !/^-?0+(\.0+)?$/.test(session.divergence) ? 'font-medium text-destructive' : ''}`}
                                    >
                                        {formatMoney(session.divergence)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={sessions.links} />
            </div>
        </>
    );
}

PosSessions.layout = {
    breadcrumbs: [posBreadcrumb, { title: 'Sessões', href: index() }],
};
