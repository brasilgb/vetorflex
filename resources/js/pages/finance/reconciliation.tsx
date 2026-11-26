import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import ReasonDialog from '@/components/reason-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { destroy, index, store } from '@/routes/finance/reconciliation';
import type { FinanceMovement, FinanceOption } from '@/types';

export default function FinanceReconciliation({
    accounts,
    account,
    movements,
    filters,
}: {
    accounts: FinanceOption[];
    account: {
        id: number;
        name: string;
        opening: string;
        closing: string;
        unreconciled: number;
    } | null;
    movements: FinanceMovement[];
    filters: { from: string; until: string; pending: boolean };
}) {
    const { formatDateTime } = useTenant();
    const [selected, setSelected] = useState<number[]>([]);
    const [isSaving, setIsSaving] = useState(false);
    const form = useForm({
        account: account === null ? '' : String(account.id),
        from: filters.from,
        until: filters.until,
        pending: filters.pending,
    });
    const pending = movements.filter((movement) => movement.reconciled_at === null);

    return (
        <>
            <Head title="Conciliação" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Conciliação manual"
                    description="Confira as movimentações com o extrato da instituição e marque as que conferem."
                />
                {account === null ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Cadastre uma conta financeira para conciliar.
                    </p>
                ) : (
                    <>
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.transform((data) => ({ ...data, pending: data.pending ? 1 : 0 }));
                                form.get(index.url(), {
                                    preserveScroll: true,
                                    onSuccess: () => setSelected([]),
                                });
                            }}
                        >
                            <NativeSelect
                                aria-label="Conta"
                                className="w-52"
                                value={form.data.account}
                                onChange={(event) => form.setData('account', event.target.value)}
                            >
                                {accounts.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                type="date"
                                aria-label="Período: início"
                                className="w-40"
                                value={form.data.from}
                                onChange={(event) => form.setData('from', event.target.value)}
                            />
                            <Input
                                type="date"
                                aria-label="Período: fim"
                                className="w-40"
                                value={form.data.until}
                                onChange={(event) => form.setData('until', event.target.value)}
                            />
                            <label className="flex h-9 items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.pending}
                                    onCheckedChange={(value) => form.setData('pending', value === true)}
                                />
                                Só pendentes
                            </label>
                            <Button variant="outline" disabled={form.processing}>
                                Aplicar
                            </Button>
                        </form>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="rounded-lg border p-4">
                                <p className="text-sm text-muted-foreground">
                                    Saldo em {formatDate(filters.from)} (início do dia)
                                </p>
                                <p className="mt-1 text-2xl font-semibold">{formatMoney(account.opening)}</p>
                            </div>
                            <div className="rounded-lg border p-4">
                                <p className="text-sm text-muted-foreground">
                                    Saldo em {formatDate(filters.until)}
                                </p>
                                <p className="mt-1 text-2xl font-semibold">{formatMoney(account.closing)}</p>
                            </div>
                            <div className="rounded-lg border p-4">
                                <p className="text-sm text-muted-foreground">Pendentes de conciliação</p>
                                <p className="mt-1 text-2xl font-semibold">{account.unreconciled}</p>
                                <p className="mt-1 text-xs text-muted-foreground">Em toda a conta</p>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                disabled={selected.length === 0 || isSaving}
                                onClick={() =>
                                    router.post(
                                        store.url(account.id),
                                        { movements: selected },
                                        {
                                            preserveScroll: true,
                                            onStart: () => setIsSaving(true),
                                            onFinish: () => setIsSaving(false),
                                            onSuccess: () => setSelected([]),
                                        },
                                    )
                                }
                            >
                                Marcar {selected.length > 0 ? selected.length : ''} como conciliada(s)
                            </Button>
                            {pending.length > 0 && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setSelected(
                                            selected.length === pending.length
                                                ? []
                                                : pending.map((movement) => movement.id),
                                        )
                                    }
                                >
                                    {selected.length === pending.length ? 'Limpar seleção' : 'Selecionar pendentes'}
                                </Button>
                            )}
                        </div>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">
                                            <span className="sr-only">Selecionar</span>
                                        </th>
                                        <th className="p-3">Data</th>
                                        <th className="p-3">Descrição</th>
                                        <th className="p-3 text-right">Valor</th>
                                        <th className="p-3">Conciliação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {movements.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="p-6 text-center text-muted-foreground">
                                                Nenhuma movimentação no período.
                                            </td>
                                        </tr>
                                    )}
                                    {movements.map((movement) => (
                                        <tr key={movement.id} className="border-t align-top">
                                            <td className="p-3">
                                                {movement.reconciled_at === null && (
                                                    <Checkbox
                                                        aria-label={`Selecionar ${movement.description}`}
                                                        checked={selected.includes(movement.id)}
                                                        onCheckedChange={(value) =>
                                                            setSelected(
                                                                value === true
                                                                    ? [...selected, movement.id]
                                                                    : selected.filter((id) => id !== movement.id),
                                                            )
                                                        }
                                                    />
                                                )}
                                            </td>
                                            <td className="p-3 whitespace-nowrap">{formatDate(movement.occurred_at)}</td>
                                            <td className="p-3">
                                                {movement.description}
                                                <span className="block text-xs text-muted-foreground">
                                                    {movement.type_label}
                                                    {movement.method && ` · ${movement.method}`}
                                                </span>
                                            </td>
                                            <td
                                                className={`p-3 text-right whitespace-nowrap ${movement.amount.startsWith('-') ? 'text-destructive' : ''}`}
                                            >
                                                {formatMoney(movement.amount)}
                                            </td>
                                            <td className="p-3">
                                                {movement.reconciled_at === null ? (
                                                    <span className="text-muted-foreground">Pendente</span>
                                                ) : (
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="text-xs text-muted-foreground">
                                                            {formatDateTime(movement.reconciled_at)} ·{' '}
                                                            {movement.reconciled_by_name}
                                                        </span>
                                                        <ReasonDialog
                                                            size="sm"
                                                            url={destroy.url(movement.id)}
                                                            title="Desfazer conciliação"
                                                            description="A movimentação volta a ficar pendente. O motivo fica registrado na auditoria."
                                                            confirmLabel="Desfazer"
                                                        >
                                                            Desfazer
                                                        </ReasonDialog>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

FinanceReconciliation.layout = {
    breadcrumbs: [financeBreadcrumb, { title: 'Conciliação', href: index() }],
};
