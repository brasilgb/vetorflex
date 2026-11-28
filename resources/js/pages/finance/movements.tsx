import { Head, Link, useForm } from '@inertiajs/react';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { exportMethod, index } from '@/routes/finance/movements';
import { show } from '@/routes/finance/titles';
import type {
    FinanceMovement,
    FinanceOption,
    Option,
    Paginated,
} from '@/types';

type Filters = {
    account: number | null;
    type: string | null;
    from: string | null;
    until: string | null;
    term: string | null;
};

export default function FinanceMovements({
    movements,
    filters,
    accounts,
    types,
}: {
    movements: Paginated<FinanceMovement>;
    filters: Filters;
    accounts: FinanceOption[];
    types: Option[];
}) {
    const { can, formatDateTime } = useTenant();
    const form = useForm({
        account: filters.account === null ? '' : String(filters.account),
        type: filters.type ?? '',
        from: filters.from ?? '',
        until: filters.until ?? '',
        term: filters.term ?? '',
    });
    const query = Object.fromEntries(
        Object.entries(form.data).filter(([, value]) => value !== ''),
    );

    return (
        <>
            <Head title="Movimentações" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Movimentações financeiras"
                        description="Livro de entradas, saídas, transferências, ajustes e estornos. Nada é editado ou excluído: correções são feitas por estorno."
                    />
                    {can('finance.export') && (
                        <Button variant="outline" asChild>
                            <a href={exportMethod.url({ query })}>
                                Exportar CSV
                            </a>
                        </Button>
                    )}
                </div>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform(() => query);
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        aria-label="Buscar na descrição"
                        placeholder="Descrição"
                        className="w-full sm:w-56"
                        value={form.data.term}
                        onChange={(event) =>
                            form.setData('term', event.target.value)
                        }
                    />
                    <NativeSelect
                        aria-label="Conta"
                        className="w-48"
                        value={form.data.account}
                        onChange={(event) =>
                            form.setData('account', event.target.value)
                        }
                    >
                        <option value="">Todas as contas</option>
                        {accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Tipo"
                        className="w-40"
                        value={form.data.type}
                        onChange={(event) =>
                            form.setData('type', event.target.value)
                        }
                    >
                        <option value="">Todos os tipos</option>
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
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
                                <th className="p-3">Data</th>
                                <th className="p-3">Descrição</th>
                                <th className="p-3">Conta</th>
                                <th className="p-3">Tipo</th>
                                <th className="p-3 text-right">Valor</th>
                                <th className="p-3">Conciliação</th>
                            </tr>
                        </thead>
                        <tbody>
                            {movements.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhuma movimentação encontrada. As
                                        movimentações surgem das baixas,
                                        transferências e ajustes.
                                    </td>
                                </tr>
                            )}
                            {movements.data.map((movement) => (
                                <tr
                                    key={movement.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3 whitespace-nowrap">
                                        {formatDate(movement.occurred_at)}
                                    </td>
                                    <td className="p-3">
                                        {movement.title_id === null ? (
                                            movement.description
                                        ) : (
                                            <Link
                                                className="underline-offset-4 hover:underline"
                                                href={show(movement.title_id)}
                                            >
                                                {movement.description}
                                            </Link>
                                        )}
                                        <span className="block text-xs text-muted-foreground">
                                            {[
                                                movement.category,
                                                movement.method,
                                                movement.user_name,
                                                movement.is_operational
                                                    ? null
                                                    : 'Fora do resultado',
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </td>
                                    <td className="p-3">{movement.account}</td>
                                    <td className="p-3">
                                        <Badge
                                            variant={
                                                movement.is_reversal
                                                    ? 'outline'
                                                    : 'secondary'
                                            }
                                        >
                                            {movement.type_label}
                                        </Badge>
                                    </td>
                                    <td
                                        className={`p-3 text-right whitespace-nowrap ${movement.amount.startsWith('-') ? 'text-destructive' : ''}`}
                                    >
                                        {formatMoney(movement.amount)}
                                    </td>
                                    <td className="p-3 text-xs text-muted-foreground">
                                        {movement.reconciled_at
                                            ? `${formatDateTime(movement.reconciled_at)} · ${movement.reconciled_by_name ?? ''}`
                                            : 'Pendente'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={movements.links} />
            </div>
        </>
    );
}

FinanceMovements.layout = {
    breadcrumbs: [financeBreadcrumb, { title: 'Movimentações', href: index() }],
};
