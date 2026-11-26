import { Head, Link, useForm } from '@inertiajs/react';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import SettleDialog from '@/components/finance/settle-dialog';
import TitleFormDialog from '@/components/finance/title-form-dialog';
import TitleStatus from '@/components/finance/title-status';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { exportMethod, index, show } from '@/routes/finance/titles';
import type {
    FinanceCategory,
    FinanceKind,
    FinanceOption,
    FinanceTitleSummary,
    Option,
    Paginated,
} from '@/types';

type Filters = {
    status: string;
    from: string | null;
    until: string | null;
    term: string | null;
    category: number | null;
};

export default function FinanceTitles({
    kind,
    titles,
    totals,
    filters,
    categories,
    accounts,
    paymentMethods,
    today,
}: {
    kind: FinanceKind;
    titles: Paginated<FinanceTitleSummary>;
    totals: { open: string; overdue: string };
    filters: Filters;
    categories: FinanceCategory[];
    accounts: FinanceOption[];
    paymentMethods: Option[];
    today: string;
}) {
    const { can } = useTenant();
    const isReceivable = kind === 'receivable';
    const slug = isReceivable ? 'receivables' : 'payables';
    const pageTitle = isReceivable ? 'Contas a receber' : 'Contas a pagar';
    const form = useForm({
        status: filters.status,
        from: filters.from ?? '',
        until: filters.until ?? '',
        term: filters.term ?? '',
        category: filters.category === null ? '' : String(filters.category),
    });
    const query = Object.fromEntries(
        Object.entries(form.data).filter(([, value]) => value !== ''),
    );

    return (
        <>
            <Head title={pageTitle} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title={pageTitle}
                        description={
                            isReceivable
                                ? 'Valores previstos só viram dinheiro recebido depois da baixa.'
                                : 'Recebimento de mercadoria não é pagamento: registre a baixa quando pagar.'
                        }
                    />
                    <div className="flex flex-wrap gap-2">
                        {can('finance.export') && (
                            <Button variant="outline" asChild>
                                <a href={exportMethod.url(slug, { query })}>
                                    Exportar CSV
                                </a>
                            </Button>
                        )}
                        {can(
                            isReceivable
                                ? 'finance.manage_receivables'
                                : 'finance.manage_payables',
                        ) && (
                            <TitleFormDialog
                                kind={kind}
                                categories={categories}
                                today={today}
                            />
                        )}
                    </div>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="rounded-lg border p-4">
                        <p className="text-sm text-muted-foreground">
                            Em aberto no filtro
                        </p>
                        <p className="mt-1 text-2xl font-semibold">
                            {formatMoney(totals.open)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="text-sm text-muted-foreground">
                            Vencido no filtro
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-destructive">
                            {formatMoney(totals.overdue)}
                        </p>
                    </div>
                </div>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(index.url(slug), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        aria-label="Buscar por descrição, pessoa ou número"
                        placeholder="Descrição, pessoa ou número"
                        className="w-full sm:w-64"
                        value={form.data.term}
                        onChange={(event) =>
                            form.setData('term', event.target.value)
                        }
                    />
                    <NativeSelect
                        aria-label="Situação"
                        className="w-44"
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData('status', event.target.value)
                        }
                    >
                        <option value="open">Em aberto</option>
                        <option value="overdue">Vencidos</option>
                        <option value="settled">Liquidados</option>
                        <option value="cancelled">Cancelados</option>
                        <option value="all">Todos</option>
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Categoria"
                        className="w-52"
                        value={form.data.category}
                        onChange={(event) =>
                            form.setData('category', event.target.value)
                        }
                    >
                        <option value="">Todas as categorias</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.full_name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input
                        type="date"
                        aria-label="Vencimento a partir de"
                        className="w-40"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Vencimento até"
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
                                <th className="p-3">Título</th>
                                <th className="p-3">Descrição</th>
                                <th className="p-3">Vencimento</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3 text-right">Valor</th>
                                <th className="p-3 text-right">Em aberto</th>
                                <th className="p-3">
                                    <span className="sr-only">Ações</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {titles.data.length === 0 && (
                                <tr>
                                    <td
                                        className="p-6 text-center text-muted-foreground"
                                        colSpan={7}
                                    >
                                        Nenhum título encontrado. Ajuste os
                                        filtros ou lance uma nova conta.
                                    </td>
                                </tr>
                            )}
                            {titles.data.map((title) => (
                                <tr key={title.id} className="border-t align-top">
                                    <td className="p-3">
                                        <Link
                                            className="font-medium underline-offset-4 hover:underline"
                                            href={show(title.id)}
                                        >
                                            #{title.number}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {title.origin_label}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {title.description}
                                        <span className="block text-xs text-muted-foreground">
                                            {[title.party_name, title.category]
                                                .filter(Boolean)
                                                .join(' · ') || '—'}
                                        </span>
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {formatDate(title.due_date)}
                                    </td>
                                    <td className="p-3">
                                        <TitleStatus title={title} />
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(title.amount)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(title.open)}
                                    </td>
                                    <td className="p-3 text-right">
                                        {(title.status === 'pending' ||
                                            title.status === 'partial') &&
                                            can(
                                                isReceivable
                                                    ? 'finance.receive'
                                                    : 'finance.pay',
                                            ) && (
                                                <SettleDialog
                                                    size="sm"
                                                    title={title}
                                                    accounts={accounts}
                                                    paymentMethods={paymentMethods}
                                                    today={today}
                                                />
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={titles.links} />
            </div>
        </>
    );
}

FinanceTitles.layout = { breadcrumbs: [financeBreadcrumb] };
