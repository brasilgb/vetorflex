import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import MoneyInput from '@/components/finance/money-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatMoney, parseDecimal, toDecimalInput } from '@/lib/decimal';
import { fromCents, toCents as cents } from '@/lib/money';
import { uuid } from '@/lib/uuid';
import { store } from '@/routes/finance/settlements';
import type { FinanceOption, FinanceTitleSummary, Option } from '@/types';

/** Recebimento ou pagamento, integral ou parcial. O valor movimentado é calculado no servidor. */
export default function SettleDialog({
    title,
    accounts,
    paymentMethods,
    today,
    size = 'default',
}: {
    title: FinanceTitleSummary;
    accounts: FinanceOption[];
    paymentMethods: Option[];
    today: string;
    size?: 'default' | 'sm';
}) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        finance_account_id: accounts.length === 1 ? String(accounts[0].id) : '',
        settled_at: today,
        method: '',
        principal: toDecimalInput(title.open, 2),
        interest: '',
        fine: '',
        discount: '',
        notes: '',
        idempotency_key: uuid(),
    };
    const form = useForm(initial);
    const errors = form.errors as Record<string, string | undefined>;
    const isReceivable = title.kind === 'receivable';
    const moved =
        cents(form.data.principal) +
        cents(form.data.interest) +
        cents(form.data.fine) -
        cents(form.data.discount);

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
                <Button size={size}>
                    {isReceivable ? 'Receber' : 'Pagar'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            method: data.method || null,
                            principal: parseDecimal(data.principal),
                            interest: parseDecimal(data.interest) || '0',
                            fine: parseDecimal(data.fine) || '0',
                            discount: parseDecimal(data.discount) || '0',
                        }));
                        form.post(store.url(title.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>
                        {isReceivable
                            ? 'Registrar recebimento'
                            : 'Registrar pagamento'}{' '}
                        — #{title.number}
                    </DialogTitle>
                    <DialogDescription>
                        Saldo em aberto: {formatMoney(title.open)}. Para baixa
                        parcial, informe um valor menor.
                    </DialogDescription>
                    {accounts.length === 0 && (
                        <p className="rounded-md border border-dashed p-3 text-sm">
                            Cadastre uma conta financeira ativa antes de
                            registrar baixas.
                        </p>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="settle-account"
                            label="Conta financeira"
                            error={errors.finance_account_id}
                        >
                            <NativeSelect
                                id="settle-account"
                                required
                                value={form.data.finance_account_id}
                                onChange={(event) =>
                                    form.setData(
                                        'finance_account_id',
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
                            id="settle-date"
                            label="Data efetiva"
                            error={errors.settled_at}
                        >
                            <Input
                                id="settle-date"
                                type="date"
                                required
                                value={form.data.settled_at}
                                onChange={(event) =>
                                    form.setData(
                                        'settled_at',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="settle-method"
                            label="Forma de pagamento"
                            error={errors.method}
                        >
                            <NativeSelect
                                id="settle-method"
                                value={form.data.method}
                                onChange={(event) =>
                                    form.setData('method', event.target.value)
                                }
                            >
                                <option value="">Não informada</option>
                                {paymentMethods.map((method) => (
                                    <option
                                        key={method.value}
                                        value={method.value}
                                    >
                                        {method.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="settle-principal"
                            label="Valor principal"
                            error={errors.principal}
                        >
                            <MoneyInput
                                id="settle-principal"
                                required
                                value={form.data.principal}
                                onChange={(event) =>
                                    form.setData(
                                        'principal',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        {(['interest', 'fine', 'discount'] as const).map(
                            (field) => (
                                <FormField
                                    key={field}
                                    id={`settle-${field}`}
                                    label={
                                        {
                                            interest: 'Juros',
                                            fine: 'Multa',
                                            discount: 'Desconto',
                                        }[field]
                                    }
                                    error={errors[field]}
                                >
                                    <MoneyInput
                                        id={`settle-${field}`}
                                        value={form.data[field]}
                                        onChange={(event) =>
                                            form.setData(
                                                field,
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                            ),
                        )}
                    </div>
                    <FormField
                        id="settle-notes"
                        label="Observações"
                        error={errors.notes}
                    >
                        <NativeTextarea
                            id="settle-notes"
                            maxLength={1000}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                    </FormField>
                    <p className="rounded-md bg-muted p-3 text-sm">
                        Valor {isReceivable ? 'recebido' : 'pago'}:{' '}
                        <strong>{formatMoney(fromCents(moved))}</strong>
                    </p>
                    {errors.idempotency_key && (
                        <p className="text-sm text-destructive">
                            {errors.idempotency_key}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            disabled={form.processing || accounts.length === 0}
                        >
                            Confirmar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
