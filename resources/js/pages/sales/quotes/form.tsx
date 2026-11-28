import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import CustomerPicker from '@/components/sales/customer-picker';
import SaleEditor, {
    initialSaleData,
    serializeSale,
} from '@/components/sales/sale-editor';
import type { SaleFormOptions } from '@/components/sales/sale-form-options';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { uuid } from '@/lib/uuid';
import { dashboard } from '@/routes/sales';
import { index, show, store, update } from '@/routes/sales/quotes';
import type { SaleCustomer, SaleQuote } from '@/types';

export default function QuoteForm({
    quote,
    ...options
}: SaleFormOptions & { quote: SaleQuote | null }) {
    const [customer, setCustomer] = useState<SaleCustomer | null>(
        options.selectedCustomer,
    );
    const form = useForm({
        ...initialSaleData(quote, options.currentUserId, uuid()),
        issue_date: quote?.issue_date ?? options.today,
        valid_until: quote?.valid_until ?? options.defaultValidUntil,
    });
    const { data, setData } = form;
    const errors = form.errors as Record<string, string>;

    return (
        <>
            <Head
                title={quote ? `Orçamento #${quote.number}` : 'Novo orçamento'}
            />
            <form
                className="mx-auto max-w-6xl space-y-6 p-4 md:p-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform(
                        (current) => serializeSale(current) as typeof current,
                    );

                    if (quote) {
                        form.put(update.url(quote.id));
                    } else {
                        form.post(store.url());
                    }
                }}
            >
                <Heading
                    variant="small"
                    title={
                        quote
                            ? `Editar orçamento #${quote.number}`
                            : 'Novo orçamento'
                    }
                    description="O orçamento não reserva nem movimenta estoque. Emita-o quando estiver pronto para o cliente."
                />
                <section
                    className="grid gap-4 rounded-lg border p-4 md:grid-cols-4"
                    aria-label="Dados gerais"
                >
                    <FormField
                        id="customer-lookup"
                        label="Cliente"
                        className="md:col-span-4"
                    >
                        <CustomerPicker
                            customer={customer}
                            canRegister={options.abilities.registerCustomers}
                            allowsAnonymous={options.allowsAnonymous}
                            error={errors.customer_party_id}
                            onChange={(selected) => {
                                setCustomer(selected);
                                setData(
                                    'customer_party_id',
                                    selected?.id ?? null,
                                );
                            }}
                        />
                    </FormField>
                    <FormField
                        id="customer-contact"
                        label="Contato"
                        error={errors.customer_contact}
                    >
                        <Input
                            id="customer-contact"
                            maxLength={255}
                            value={data.customer_contact}
                            onChange={(event) =>
                                setData('customer_contact', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="seller"
                        label="Responsável"
                        error={errors.seller_id}
                    >
                        <NativeSelect
                            id="seller"
                            value={data.seller_id ?? ''}
                            onChange={(event) =>
                                setData('seller_id', Number(event.target.value))
                            }
                        >
                            {options.sellers.map((seller) => (
                                <option key={seller.id} value={seller.id}>
                                    {seller.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="issue-date"
                        label="Emissão"
                        error={errors.issue_date}
                    >
                        <Input
                            id="issue-date"
                            type="date"
                            required
                            value={data.issue_date}
                            onChange={(event) =>
                                setData('issue_date', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="valid-until"
                        label="Validade"
                        error={errors.valid_until}
                    >
                        <Input
                            id="valid-until"
                            type="date"
                            required
                            value={data.valid_until}
                            onChange={(event) =>
                                setData('valid_until', event.target.value)
                            }
                        />
                    </FormField>
                </section>
                <SaleEditor
                    form={form}
                    conditions={options.conditions}
                    methods={options.methods}
                    abilities={options.abilities}
                    baseDate={data.issue_date}
                />
                <InputError message={errors.idempotency_key ?? errors.status} />
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" asChild>
                        <Link href={quote ? show(quote.id) : index()}>
                            Cancelar
                        </Link>
                    </Button>
                    <Button disabled={form.processing}>
                        {quote ? 'Salvar alterações' : 'Salvar rascunho'}
                    </Button>
                </div>
            </form>
        </>
    );
}

QuoteForm.layout = {
    breadcrumbs: [
        { title: 'Vendas', href: dashboard() },
        { title: 'Orçamentos', href: index() },
        { title: 'Orçamento', href: '#' },
    ],
};
