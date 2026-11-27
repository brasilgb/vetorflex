import { useState } from 'react';
import MoneyInput from '@/components/finance/money-input';
import AuthorizationFields, {
    type Credentials,
    emptyCredentials,
} from '@/components/pos/authorization-fields';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatMoney, parseDecimal, toDecimalInput } from '@/lib/decimal';
import { fromCents, toCents } from '@/lib/money';
import { uuid } from '@/lib/uuid';
import type { Option } from '@/types';

export type PaymentRow = {
    key: string;
    method: string;
    amount: string;
    is_term: boolean;
    due_date: string;
};

/** Pagamentos combinados. Troco só em dinheiro; Pix, cartões e demais são registrados como informados pelo operador. */
export default function PaymentDialog({
    open,
    onOpenChange,
    total,
    methods,
    today,
    hasCustomer,
    processing,
    errors,
    needsAuthorization,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    total: string;
    methods: Option[];
    today: string;
    hasCustomer: boolean;
    processing: boolean;
    errors: Record<string, string | undefined>;
    needsAuthorization: boolean;
    onSubmit: (
        payments: {
            method: string;
            amount: string;
            is_term: boolean;
            due_date: string | null;
        }[],
        credentials: Credentials,
    ) => void;
}) {
    const first = methods.some((method) => method.value === 'cash')
        ? 'cash'
        : (methods[0]?.value ?? 'cash');
    const newRow = (method: string, amount: string): PaymentRow => ({
        key: uuid(),
        method,
        amount,
        is_term: false,
        due_date: today,
    });
    const [rows, setRows] = useState<PaymentRow[]>([
        newRow(first, toDecimalInput(total, 2)),
    ]);
    const [credentials, setCredentials] =
        useState<Credentials>(emptyCredentials);
    const totalCents = toCents(total);
    const informed = rows.reduce((sum, row) => sum + toCents(row.amount), 0n);
    const cash = rows
        .filter((row) => row.method === 'cash')
        .reduce((sum, row) => sum + toCents(row.amount), 0n);
    const others = informed - cash;
    const remaining = totalCents - informed > 0n ? totalCents - informed : 0n;
    const change =
        cash > 0n && informed > totalCents && others <= totalCents
            ? informed - totalCents
            : 0n;
    const othersExceed = others > totalCents;
    const update = (key: string, changes: Partial<PaymentRow>) =>
        setRows(
            rows.map((row) => (row.key === key ? { ...row, ...changes } : row)),
        );
    const fillRemaining = (method: string) => {
        if (remaining === 0n) {
            return;
        }

        const empty = rows.find((row) => toCents(row.amount) === 0n);

        setRows(
            empty
                ? rows.map((row) =>
                      row.key === empty.key
                          ? {
                                ...row,
                                method,
                                amount: toDecimalInput(fromCents(remaining), 2),
                            }
                          : row,
                  )
                : [
                      ...rows,
                      newRow(method, toDecimalInput(fromCents(remaining), 2)),
                  ],
        );
    };
    const errorList = Object.entries(errors)
        .filter(([key]) => key !== 'authorization')
        .map(([, message]) => message)
        .filter(Boolean);

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (value) {
                    setRows([newRow(first, toDecimalInput(total, 2))]);
                    setCredentials(emptyCredentials);
                }

                onOpenChange(value);
            }}
        >
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSubmit(
                            rows
                                .filter((row) => toCents(row.amount) > 0n)
                                .map((row) => ({
                                    method: row.method,
                                    amount: parseDecimal(row.amount),
                                    is_term:
                                        row.method !== 'cash' && row.is_term,
                                    due_date:
                                        row.method !== 'cash' && row.is_term
                                            ? row.due_date
                                            : null,
                                })),
                            credentials,
                        );
                    }}
                    onKeyDown={(event) => {
                        if (
                            event.key === 'Enter' &&
                            (event.ctrlKey || event.metaKey)
                        ) {
                            event.preventDefault();
                            event.currentTarget.requestSubmit();
                        }
                    }}
                >
                    <DialogTitle>Pagamento — {formatMoney(total)}</DialogTitle>
                    <DialogDescription>
                        Registre como o cliente pagou. Sem integração bancária,
                        Pix, cartões e boletos ficam como informados pelo
                        operador.
                    </DialogDescription>
                    <div className="grid grid-cols-3 gap-2 text-center text-sm">
                        <div className="rounded-md border p-2">
                            <p className="text-muted-foreground">Informado</p>
                            <p className="text-lg font-semibold">
                                {formatMoney(fromCents(informed))}
                            </p>
                        </div>
                        <div
                            className={`rounded-md border p-2 ${remaining > 0n ? 'border-destructive' : ''}`}
                        >
                            <p className="text-muted-foreground">Falta</p>
                            <p className="text-lg font-semibold">
                                {formatMoney(fromCents(remaining))}
                            </p>
                        </div>
                        <div className="rounded-md border p-2">
                            <p className="text-muted-foreground">Troco</p>
                            <p className="text-lg font-semibold text-emerald-700 dark:text-emerald-400">
                                {formatMoney(fromCents(change))}
                            </p>
                        </div>
                    </div>
                    <ul className="space-y-2">
                        {rows.map((row, index) => (
                            <li
                                key={row.key}
                                className="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_9rem_auto]"
                            >
                                <NativeSelect
                                    aria-label={`Forma de pagamento ${index + 1}`}
                                    value={row.method}
                                    onChange={(event) =>
                                        update(row.key, {
                                            method: event.target.value,
                                            is_term: false,
                                        })
                                    }
                                >
                                    {methods.map((method) => (
                                        <option
                                            key={method.value}
                                            value={method.value}
                                        >
                                            {method.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <MoneyInput
                                    aria-label={`Valor ${index + 1}`}
                                    autoFocus={index === 0}
                                    value={row.amount}
                                    onChange={(event) =>
                                        update(row.key, {
                                            amount: event.target.value,
                                        })
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={rows.length === 1}
                                    onClick={() =>
                                        setRows(
                                            rows.filter(
                                                (item) => item.key !== row.key,
                                            ),
                                        )
                                    }
                                >
                                    Remover
                                </Button>
                                {row.method !== 'cash' && (
                                    <div className="flex flex-wrap items-center gap-3 text-sm sm:col-span-3">
                                        <label className="flex items-center gap-2">
                                            <Checkbox
                                                checked={row.is_term}
                                                onCheckedChange={(value) =>
                                                    update(row.key, {
                                                        is_term: value === true,
                                                    })
                                                }
                                            />
                                            A prazo (fica em aberto)
                                        </label>
                                        {row.is_term && (
                                            <Input
                                                type="date"
                                                aria-label="Vencimento"
                                                className="w-44"
                                                min={today}
                                                value={row.due_date}
                                                onChange={(event) =>
                                                    update(row.key, {
                                                        due_date:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                        {row.is_term && !hasCustomer && (
                                            <span className="text-destructive">
                                                Selecione o cliente para vender
                                                a prazo.
                                            </span>
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                    <div className="flex flex-wrap gap-2">
                        {methods.slice(0, 6).map((method) => (
                            <Button
                                key={method.value}
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={remaining === 0n}
                                onClick={() => fillRemaining(method.value)}
                            >
                                Restante em {method.label}
                            </Button>
                        ))}
                        {rows.length < 10 && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    setRows([...rows, newRow(first, '')])
                                }
                            >
                                + Forma de pagamento
                            </Button>
                        )}
                    </div>
                    {othersExceed && (
                        <p className="text-sm text-destructive">
                            Os pagamentos fora do dinheiro superam o total.
                            Troco só é dado em dinheiro.
                        </p>
                    )}
                    {errorList.length > 0 && (
                        <ul className="space-y-1 text-sm text-destructive">
                            {errorList.map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                        </ul>
                    )}
                    {needsAuthorization && (
                        <AuthorizationFields
                            id="payment-authorization"
                            value={credentials}
                            onChange={setCredentials}
                            error={errors.authorization}
                        />
                    )}
                    <div className="flex flex-wrap justify-between gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => onOpenChange(false)}
                        >
                            Voltar
                        </Button>
                        <Button
                            size="lg"
                            disabled={
                                processing || remaining > 0n || othersExceed
                            }
                        >
                            {processing
                                ? 'Registrando…'
                                : 'Finalizar venda (Ctrl+Enter)'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
