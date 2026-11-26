import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import MoneyInput from '@/components/finance/money-input';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
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
import { formatDate } from '@/lib/date';
import { formatMoney, parseDecimal } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { index, reverse, store } from '@/routes/finance/transfers';
import type { FinanceOption, FinanceTransfer, Paginated } from '@/types';

function TransferDialog({
    accounts,
    today,
}: {
    accounts: FinanceOption[];
    today: string;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        from_account_id: '',
        to_account_id: '',
        amount: '',
        transferred_at: today,
        description: '',
        idempotency_key: uuid(),
    };
    const form = useForm(initial);

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                form.setData({ ...initial, idempotency_key: uuid() });
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button disabled={accounts.length < 2}>Nova transferência</Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            amount: parseDecimal(data.amount),
                        }));
                        form.post(store.url(), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Transferir entre contas</DialogTitle>
                    <DialogDescription>
                        Gera uma saída na origem e uma entrada no destino. Não
                        conta como receita nem como despesa.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="transfer-from"
                            label="Conta de origem"
                            error={form.errors.from_account_id}
                        >
                            <NativeSelect
                                id="transfer-from"
                                required
                                value={form.data.from_account_id}
                                onChange={(event) =>
                                    form.setData(
                                        'from_account_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Selecione</option>
                                {accounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="transfer-to"
                            label="Conta de destino"
                            error={form.errors.to_account_id}
                        >
                            <NativeSelect
                                id="transfer-to"
                                required
                                value={form.data.to_account_id}
                                onChange={(event) =>
                                    form.setData(
                                        'to_account_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Selecione</option>
                                {accounts
                                    .filter(
                                        (account) =>
                                            String(account.id) !==
                                            form.data.from_account_id,
                                    )
                                    .map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.name}
                                        </option>
                                    ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="transfer-amount"
                            label="Valor"
                            error={form.errors.amount}
                        >
                            <MoneyInput
                                id="transfer-amount"
                                required
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="transfer-date"
                            label="Data"
                            error={form.errors.transferred_at}
                        >
                            <Input
                                id="transfer-date"
                                type="date"
                                required
                                value={form.data.transferred_at}
                                onChange={(event) =>
                                    form.setData(
                                        'transferred_at',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="transfer-description"
                            label="Descrição (opcional)"
                            error={form.errors.description}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="transfer-description"
                                maxLength={255}
                                placeholder="Ex.: Depósito do caixa no banco"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                    </div>
                    {Object.keys(form.errors).length > 0 &&
                        !form.errors.from_account_id &&
                        !form.errors.to_account_id &&
                        !form.errors.amount && (
                            <p className="text-sm text-destructive">
                                {Object.values(form.errors)[0]}
                            </p>
                        )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button disabled={form.processing}>Transferir</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function FinanceTransfers({
    transfers,
    accounts,
    today,
}: {
    transfers: Paginated<FinanceTransfer>;
    accounts: FinanceOption[];
    today: string;
}) {
    const { can } = useTenant();
    const canTransfer = can('finance.transfer');

    return (
        <>
            <Head title="Transferências" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Transferências entre contas"
                        description="Movimentações internas da empresa, fora de receitas e despesas."
                    />
                    {canTransfer && (
                        <TransferDialog accounts={accounts} today={today} />
                    )}
                </div>
                {canTransfer && accounts.length < 2 && (
                    <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        Cadastre ao menos duas contas ativas para transferir.
                    </p>
                )}
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Data</th>
                                <th className="p-3">Origem → destino</th>
                                <th className="p-3">Descrição</th>
                                <th className="p-3 text-right">Valor</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3">
                                    <span className="sr-only">Ações</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {transfers.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhuma transferência registrada.
                                    </td>
                                </tr>
                            )}
                            {transfers.data.map((transfer) => (
                                <tr key={transfer.id} className="border-t align-top">
                                    <td className="p-3 whitespace-nowrap">
                                        {formatDate(transfer.transferred_at)}
                                    </td>
                                    <td className="p-3">
                                        {transfer.from} → {transfer.to}
                                    </td>
                                    <td className="p-3">
                                        {transfer.description ?? '—'}
                                        <span className="block text-xs text-muted-foreground">
                                            {transfer.user_name}
                                            {transfer.status === 'reversed' &&
                                                ` · estornada por ${transfer.reversed_by_name}: ${transfer.reversal_reason}`}
                                        </span>
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(transfer.amount)}
                                    </td>
                                    <td className="p-3">
                                        <Badge
                                            variant={
                                                transfer.status === 'reversed'
                                                    ? 'outline'
                                                    : 'secondary'
                                            }
                                        >
                                            {transfer.status === 'reversed'
                                                ? 'Estornada'
                                                : 'Confirmada'}
                                        </Badge>
                                    </td>
                                    <td className="p-3 text-right">
                                        {canTransfer &&
                                            transfer.status === 'confirmed' && (
                                                <ReasonDialog
                                                    size="sm"
                                                    url={reverse.url(transfer.id)}
                                                    title="Estornar transferência"
                                                    description="Gera os lançamentos inversos nas duas contas. A transferência original fica no histórico."
                                                    confirmLabel="Estornar"
                                                >
                                                    Estornar
                                                </ReasonDialog>
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={transfers.links} />
            </div>
        </>
    );
}

FinanceTransfers.layout = {
    breadcrumbs: [financeBreadcrumb, { title: 'Transferências', href: index() }],
};
