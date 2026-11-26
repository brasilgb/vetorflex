import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmButton from '@/components/confirm-button';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import MoneyInput from '@/components/finance/money-input';
import PartyPicker from '@/components/finance/party-picker';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
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
import { end, index, store } from '@/routes/finance/recurrences';
import type {
    FinanceCategory,
    FinanceKind,
    FinanceParty,
    FinanceRecurrence,
    Option,
} from '@/types';

function RecurrenceDialog({
    frequencies,
    categories,
    today,
    kinds,
}: {
    frequencies: Option[];
    categories: FinanceCategory[];
    today: string;
    kinds: FinanceKind[];
}) {
    const [isOpen, setIsOpen] = useState(false);
    const [party, setParty] = useState<FinanceParty | null>(null);
    const initial = {
        kind: kinds[0] as string,
        description: '',
        amount: '',
        frequency: 'monthly',
        start_date: today,
        end_date: '',
        finance_category_id: '',
    };
    const form = useForm(initial);
    const categoryKind = form.data.kind === 'receivable' ? 'income' : 'expense';

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                form.setData(initial);
                setParty(null);
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button>Nova recorrência</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            amount: parseDecimal(data.amount),
                            end_date: data.end_date || null,
                            finance_category_id: data.finance_category_id || null,
                            party_id: party?.id ?? null,
                        }));
                        form.post(store.url(), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Nova receita ou despesa recorrente</DialogTitle>
                    <DialogDescription>
                        Os títulos são gerados automaticamente com antecedência,
                        sem baixa. Você registra o pagamento ou recebimento de
                        cada um.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField id="recurrence-kind" label="Tipo" error={form.errors.kind}>
                            <NativeSelect
                                id="recurrence-kind"
                                value={form.data.kind}
                                onChange={(event) =>
                                    form.setData({
                                        ...form.data,
                                        kind: event.target.value,
                                        finance_category_id: '',
                                    })
                                }
                            >
                                {kinds.includes('payable') && (
                                    <option value="payable">Despesa (a pagar)</option>
                                )}
                                {kinds.includes('receivable') && (
                                    <option value="receivable">Receita (a receber)</option>
                                )}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="recurrence-frequency"
                            label="Frequência"
                            error={form.errors.frequency}
                        >
                            <NativeSelect
                                id="recurrence-frequency"
                                value={form.data.frequency}
                                onChange={(event) =>
                                    form.setData('frequency', event.target.value)
                                }
                            >
                                {frequencies.map((frequency) => (
                                    <option key={frequency.value} value={frequency.value}>
                                        {frequency.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="recurrence-description"
                            label="Descrição"
                            error={form.errors.description}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="recurrence-description"
                                required
                                maxLength={255}
                                placeholder="Ex.: Aluguel da loja"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField id="recurrence-amount" label="Valor" error={form.errors.amount}>
                            <MoneyInput
                                id="recurrence-amount"
                                required
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="recurrence-category"
                            label="Categoria"
                            error={form.errors.finance_category_id}
                        >
                            <NativeSelect
                                id="recurrence-category"
                                value={form.data.finance_category_id}
                                onChange={(event) =>
                                    form.setData('finance_category_id', event.target.value)
                                }
                            >
                                <option value="">Sem categoria</option>
                                {categories
                                    .filter((category) => category.kind === categoryKind)
                                    .map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.full_name}
                                        </option>
                                    ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="recurrence-start"
                            label="Primeiro vencimento"
                            error={form.errors.start_date}
                        >
                            <Input
                                id="recurrence-start"
                                type="date"
                                required
                                value={form.data.start_date}
                                onChange={(event) =>
                                    form.setData('start_date', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="recurrence-end"
                            label="Data final (opcional)"
                            error={form.errors.end_date}
                        >
                            <Input
                                id="recurrence-end"
                                type="date"
                                value={form.data.end_date}
                                onChange={(event) =>
                                    form.setData('end_date', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="recurrence-party"
                            label="Pessoa (opcional)"
                            className="sm:col-span-2"
                        >
                            <PartyPicker value={party} onChange={setParty} />
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

export default function FinanceRecurrences({
    recurrences,
    frequencies,
    categories,
    today,
}: {
    recurrences: FinanceRecurrence[];
    frequencies: Option[];
    categories: FinanceCategory[];
    today: string;
}) {
    const { can } = useTenant();
    const kinds = [
        ...(can('finance.manage_payables') ? (['payable'] as const) : []),
        ...(can('finance.manage_receivables') ? (['receivable'] as const) : []),
    ];

    return (
        <>
            <Head title="Recorrências" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Receitas e despesas recorrentes"
                        description="Aluguel, internet, mensalidades e contratos. Títulos são gerados diariamente; nenhuma baixa é automática."
                    />
                    {kinds.length > 0 && (
                        <RecurrenceDialog
                            frequencies={frequencies}
                            categories={categories}
                            today={today}
                            kinds={kinds}
                        />
                    )}
                </div>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Descrição</th>
                                <th className="p-3">Frequência</th>
                                <th className="p-3">Vigência</th>
                                <th className="p-3 text-right">Valor</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3">
                                    <span className="sr-only">Ações</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {recurrences.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="p-6 text-center text-muted-foreground">
                                        Nenhuma recorrência cadastrada. Cadastre
                                        despesas fixas para vê-las no fluxo de
                                        caixa previsto.
                                    </td>
                                </tr>
                            )}
                            {recurrences.map((recurrence) => (
                                <tr key={recurrence.id} className="border-t align-top">
                                    <td className="p-3">
                                        {recurrence.description}
                                        <span className="block text-xs text-muted-foreground">
                                            {recurrence.kind === 'receivable' ? 'Receita' : 'Despesa'}
                                            {recurrence.category && ` · ${recurrence.category}`}
                                            {` · ${recurrence.titles_count} título(s) gerado(s)`}
                                        </span>
                                    </td>
                                    <td className="p-3">{recurrence.frequency_label}</td>
                                    <td className="p-3 whitespace-nowrap">
                                        {formatDate(recurrence.start_date)}
                                        {' a '}
                                        {recurrence.end_date ? formatDate(recurrence.end_date) : 'sem fim'}
                                        {recurrence.generated_until && (
                                            <span className="block text-xs text-muted-foreground">
                                                Gerado até {formatDate(recurrence.generated_until)}
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(recurrence.amount)}
                                    </td>
                                    <td className="p-3">
                                        <Badge variant={recurrence.ended_at ? 'outline' : 'secondary'}>
                                            {recurrence.ended_at ? 'Encerrada' : 'Ativa'}
                                        </Badge>
                                    </td>
                                    <td className="p-3 text-right">
                                        {recurrence.ended_at === null && (
                                            <ConfirmButton
                                                size="sm"
                                                route={end(recurrence.id)}
                                                title={`Encerrar ${recurrence.description}?`}
                                                description="Novos títulos deixam de ser gerados. Os já gerados continuam em aberto e podem ser cancelados individualmente."
                                                confirmLabel="Encerrar"
                                            >
                                                Encerrar
                                            </ConfirmButton>
                                        )}
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

FinanceRecurrences.layout = {
    breadcrumbs: [financeBreadcrumb, { title: 'Recorrências', href: index() }],
};
