import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import MoneyInput from '@/components/finance/money-input';
import PartyPicker from '@/components/finance/party-picker';
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
import { parseDecimal } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { store } from '@/routes/finance/titles';
import type { FinanceCategory, FinanceKind, FinanceParty } from '@/types';

/** Lançamento manual de conta a receber ou a pagar, à vista ou em parcelas mensais. */
export default function TitleFormDialog({
    kind,
    categories,
    today,
}: {
    kind: FinanceKind;
    categories: FinanceCategory[];
    today: string;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const [party, setParty] = useState<FinanceParty | null>(null);
    const form = useForm({
        kind,
        description: '',
        amount: '',
        competence_date: today,
        due_date: today,
        installments: '1',
        finance_category_id: '',
        party_id: '' as string | number,
        notes: '',
        idempotency_key: uuid(),
    });
    const label = kind === 'receivable' ? 'conta a receber' : 'conta a pagar';
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);

                if (!open) {
                    form.reset();
                    form.clearErrors();
                    setParty(null);
                    form.setData('idempotency_key', uuid());
                }
            }}
        >
            <DialogTrigger asChild>
                <Button>Nova {label}</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            amount: parseDecimal(data.amount),
                            installments: Number(data.installments) || 1,
                            party_id: party?.id ?? null,
                            finance_category_id:
                                data.finance_category_id || null,
                        }));
                        form.post(store.url(), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Nova {label}</DialogTitle>
                    <DialogDescription>
                        Em parcelas, o valor é dividido em vencimentos mensais;
                        os centavos restantes ficam na última parcela.
                    </DialogDescription>
                    <FormField
                        id="title-description"
                        label="Descrição"
                        error={errors.description}
                    >
                        <Input
                            id="title-description"
                            required
                            maxLength={255}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </FormField>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="title-amount"
                            label="Valor total"
                            error={errors.amount}
                        >
                            <MoneyInput
                                id="title-amount"
                                required
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="title-installments"
                            label="Parcelas"
                            error={errors.installments}
                        >
                            <Input
                                id="title-installments"
                                type="number"
                                min={1}
                                max={120}
                                value={form.data.installments}
                                onChange={(event) =>
                                    form.setData(
                                        'installments',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="title-due"
                            label="Vencimento (1ª parcela)"
                            error={errors.due_date}
                        >
                            <Input
                                id="title-due"
                                type="date"
                                required
                                value={form.data.due_date}
                                onChange={(event) =>
                                    form.setData('due_date', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="title-competence"
                            label="Competência"
                            hint="Data a que a receita ou despesa se refere."
                            error={errors.competence_date}
                        >
                            <Input
                                id="title-competence"
                                type="date"
                                required
                                value={form.data.competence_date}
                                onChange={(event) =>
                                    form.setData(
                                        'competence_date',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                    </div>
                    <FormField
                        id="title-category"
                        label="Categoria"
                        error={errors.finance_category_id}
                    >
                        <NativeSelect
                            id="title-category"
                            value={form.data.finance_category_id}
                            onChange={(event) =>
                                form.setData(
                                    'finance_category_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">Sem categoria</option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.full_name}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="party-lookup"
                        label={
                            kind === 'receivable'
                                ? 'Cliente ou pessoa (opcional)'
                                : 'Fornecedor ou pessoa (opcional)'
                        }
                        error={errors.party_id}
                    >
                        <PartyPicker value={party} onChange={setParty} />
                    </FormField>
                    <FormField
                        id="title-notes"
                        label="Observações"
                        error={errors.notes}
                    >
                        <NativeTextarea
                            id="title-notes"
                            maxLength={2000}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                    </FormField>
                    {errors.idempotency_key && (
                        <p className="text-sm text-destructive">
                            {errors.idempotency_key}
                        </p>
                    )}
                    <DialogFooter>
                        <Button disabled={form.processing}>Lançar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
