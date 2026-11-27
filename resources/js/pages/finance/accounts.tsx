import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmButton from '@/components/confirm-button';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import MoneyInput from '@/components/finance/money-input';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
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
import { formatMoney, parseDecimal, toDecimalInput } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import {
    adjust,
    index as accountsIndex,
    store,
    toggle,
    update,
} from '@/routes/finance/accounts';
import { index as movements } from '@/routes/finance/movements';
import type { FinanceAccount, Option } from '@/types';

function AccountDialog({
    account,
    types,
    today,
}: {
    account?: FinanceAccount;
    types: Option[];
    today: string;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        name: account?.name ?? '',
        type: account?.type ?? 'checking',
        institution: account?.institution ?? '',
        opening_balance: toDecimalInput(account?.opening_balance ?? '0', 2),
        opening_date: account?.opening_date ?? today,
        notes: account?.notes ?? '',
    };
    const form = useForm(initial);

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                form.setData(initial);
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button
                    variant={account ? 'outline' : 'default'}
                    size={account ? 'sm' : 'default'}
                >
                    {account ? 'Editar' : 'Nova conta'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            opening_balance:
                                parseDecimal(data.opening_balance) || '0',
                        }));
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        };

                        if (account) {
                            form.put(update.url(account.id), options);
                        } else {
                            form.post(store.url(), options);
                        }
                    }}
                >
                    <DialogTitle>
                        {account
                            ? `Editar ${account.name}`
                            : 'Nova conta financeira'}
                    </DialogTitle>
                    <DialogDescription>
                        O saldo atual é calculado pelas movimentações. Para
                        corrigi-lo, use o ajuste de saldo.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="account-name"
                            label="Nome"
                            error={form.errors.name}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="account-name"
                                required
                                maxLength={120}
                                placeholder="Ex.: Caixa da loja"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="account-type"
                            label="Tipo"
                            error={form.errors.type}
                        >
                            <NativeSelect
                                id="account-type"
                                value={form.data.type}
                                onChange={(event) =>
                                    form.setData('type', event.target.value)
                                }
                            >
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="account-institution"
                            label="Instituição (opcional)"
                            error={form.errors.institution}
                        >
                            <Input
                                id="account-institution"
                                maxLength={120}
                                value={form.data.institution}
                                onChange={(event) =>
                                    form.setData(
                                        'institution',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="account-opening-balance"
                            label="Saldo inicial"
                            error={form.errors.opening_balance}
                        >
                            <MoneyInput
                                id="account-opening-balance"
                                value={form.data.opening_balance}
                                onChange={(event) =>
                                    form.setData(
                                        'opening_balance',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="account-opening-date"
                            label="Data do saldo inicial"
                            error={form.errors.opening_date}
                        >
                            <Input
                                id="account-opening-date"
                                type="date"
                                required
                                value={form.data.opening_date}
                                onChange={(event) =>
                                    form.setData(
                                        'opening_date',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="account-notes"
                            label="Observações"
                            error={form.errors.notes}
                            className="sm:col-span-2"
                        >
                            <NativeTextarea
                                id="account-notes"
                                maxLength={2000}
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button disabled={form.processing}>Salvar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AdjustDialog({
    account,
    today,
}: {
    account: FinanceAccount;
    today: string;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        balance: toDecimalInput(account.balance, 2),
        date: today,
        reason: '',
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
                <Button variant="outline" size="sm">
                    Ajustar saldo
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            balance: parseDecimal(data.balance),
                        }));
                        form.post(adjust.url(account.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Ajustar saldo de {account.name}</DialogTitle>
                    <DialogDescription>
                        Saldo calculado: {formatMoney(account.balance)}. Informe
                        o saldo correto; a diferença vira um lançamento de
                        ajuste identificado, fora do resultado.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="adjust-balance"
                            label="Saldo correto"
                            error={form.errors.balance}
                        >
                            <MoneyInput
                                id="adjust-balance"
                                required
                                value={form.data.balance}
                                onChange={(event) =>
                                    form.setData('balance', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="adjust-date"
                            label="Data"
                            error={form.errors.date}
                        >
                            <Input
                                id="adjust-date"
                                type="date"
                                required
                                value={form.data.date}
                                onChange={(event) =>
                                    form.setData('date', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="adjust-reason"
                            label="Justificativa"
                            error={form.errors.reason}
                            className="sm:col-span-2"
                        >
                            <NativeTextarea
                                id="adjust-reason"
                                required
                                maxLength={400}
                                value={form.data.reason}
                                onChange={(event) =>
                                    form.setData('reason', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button disabled={form.processing}>
                            Registrar ajuste
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function FinanceAccounts({
    accounts,
    types,
    today,
}: {
    accounts: FinanceAccount[];
    types: Option[];
    today: string;
}) {
    const { can } = useTenant();
    const canManage = can('finance.manage_accounts');

    return (
        <>
            <Head title="Contas financeiras" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Contas financeiras"
                        description="Caixa, contas bancárias e carteiras. O saldo vem das movimentações registradas."
                    />
                    {canManage && <AccountDialog types={types} today={today} />}
                </div>
                {accounts.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Nenhuma conta cadastrada. Cadastre ao menos o caixa para
                        registrar recebimentos e pagamentos.
                    </p>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {accounts.map((account) => (
                            <article
                                key={account.id}
                                className="space-y-3 rounded-lg border p-4"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <h3 className="font-medium">
                                            {account.name}
                                        </h3>
                                        <p className="text-xs text-muted-foreground">
                                            {[
                                                account.type_label,
                                                account.institution,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </div>
                                    {!account.is_active && (
                                        <Badge variant="outline">Inativa</Badge>
                                    )}
                                </div>
                                <p
                                    className={`text-2xl font-semibold ${account.balance.startsWith('-') ? 'text-destructive' : ''}`}
                                >
                                    {formatMoney(account.balance)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Saldo inicial{' '}
                                    {formatMoney(account.opening_balance)} em{' '}
                                    {formatDate(account.opening_date)}
                                </p>
                                {account.notes && (
                                    <p className="text-sm">{account.notes}</p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={movements({
                                                query: { account: account.id },
                                            })}
                                        >
                                            Extrato
                                        </Link>
                                    </Button>
                                    {canManage && (
                                        <AccountDialog
                                            account={account}
                                            types={types}
                                            today={today}
                                        />
                                    )}
                                    {can('finance.adjust_balances') &&
                                        account.is_active && (
                                            <AdjustDialog
                                                account={account}
                                                today={today}
                                            />
                                        )}
                                    {canManage && (
                                        <ConfirmButton
                                            size="sm"
                                            route={toggle(account.id)}
                                            title={
                                                account.is_active
                                                    ? `Inativar ${account.name}?`
                                                    : `Reativar ${account.name}?`
                                            }
                                            description={
                                                account.is_active
                                                    ? 'A conta deixa de aparecer nas baixas e transferências. O histórico é preservado.'
                                                    : 'A conta volta a aceitar baixas e transferências.'
                                            }
                                            confirmLabel={
                                                account.is_active
                                                    ? 'Inativar'
                                                    : 'Reativar'
                                            }
                                        >
                                            {account.is_active
                                                ? 'Inativar'
                                                : 'Reativar'}
                                        </ConfirmButton>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

FinanceAccounts.layout = {
    breadcrumbs: [
        financeBreadcrumb,
        { title: 'Contas financeiras', href: accountsIndex() },
    ],
};
