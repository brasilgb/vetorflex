import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import RemoteLookup from '@/components/remote-lookup';
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
import { lookup, store } from '@/routes/sales/customers';
import type { SaleCustomer } from '@/types';

type NewCustomer = {
    type: string;
    name: string;
    document: string;
    phone: string;
    email: string;
    link_existing: boolean;
    address: {
        postal_code: string;
        street: string;
        number: string;
        district: string;
        city: string;
        state: string;
    };
};

function CreateCustomerDialog({
    onCreated,
}: {
    onCreated: (customer: SaleCustomer) => void;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const http = useHttp<NewCustomer, SaleCustomer>({
        type: 'individual',
        name: '',
        document: '',
        phone: '',
        email: '',
        link_existing: false,
        address: {
            postal_code: '',
            street: '',
            number: '',
            district: '',
            city: '',
            state: '',
        },
    });
    const errors = http.errors as Record<string, string>;
    const setAddress = (field: keyof NewCustomer['address'], value: string) =>
        http.setData('address', { ...http.data.address, [field]: value });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                http.reset();
                http.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" variant="outline">
                    Novo cliente
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        http.post(store.url(), {
                            onSuccess: (customer) => {
                                onCreated(customer);
                                setIsOpen(false);
                            },
                        }).catch(() => undefined);
                    }}
                >
                    <DialogTitle>Cadastro simplificado de cliente</DialogTitle>
                    <DialogDescription>
                        Uma pessoa já cadastrada com o mesmo documento é
                        reaproveitada, sem alterar os dados dela.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <FormField
                            id="new-customer-type"
                            label="Tipo"
                            error={errors.type}
                        >
                            <NativeSelect
                                id="new-customer-type"
                                value={http.data.type}
                                onChange={(event) =>
                                    http.setData('type', event.target.value)
                                }
                            >
                                <option value="individual">
                                    Pessoa física
                                </option>
                                <option value="company">Pessoa jurídica</option>
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="new-customer-name"
                            label="Nome"
                            error={errors.name}
                        >
                            <Input
                                id="new-customer-name"
                                required
                                maxLength={255}
                                value={http.data.name}
                                onChange={(event) =>
                                    http.setData('name', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <FormField
                            id="new-customer-document"
                            label="CPF/CNPJ"
                            error={errors.document}
                        >
                            <Input
                                id="new-customer-document"
                                maxLength={20}
                                value={http.data.document}
                                onChange={(event) =>
                                    http.setData('document', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="new-customer-phone"
                            label="Telefone"
                            error={errors.phone}
                        >
                            <Input
                                id="new-customer-phone"
                                maxLength={30}
                                value={http.data.phone}
                                onChange={(event) =>
                                    http.setData('phone', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="new-customer-email"
                            label="E-mail"
                            error={errors.email}
                        >
                            <Input
                                id="new-customer-email"
                                type="email"
                                maxLength={255}
                                value={http.data.email}
                                onChange={(event) =>
                                    http.setData('email', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <fieldset className="grid gap-3 sm:grid-cols-6">
                        <legend className="mb-2 text-sm font-medium">
                            Endereço (opcional)
                        </legend>
                        {(
                            [
                                ['street', 'Logradouro', 'sm:col-span-4'],
                                ['number', 'Número', 'sm:col-span-2'],
                                ['district', 'Bairro', 'sm:col-span-2'],
                                ['city', 'Cidade', 'sm:col-span-2'],
                                ['state', 'UF', 'sm:col-span-1'],
                                ['postal_code', 'CEP', 'sm:col-span-1'],
                            ] as const
                        ).map(([field, label, span]) => (
                            <FormField
                                key={field}
                                id={`new-customer-${field}`}
                                label={label}
                                error={errors[`address.${field}`]}
                                className={span}
                            >
                                <Input
                                    id={`new-customer-${field}`}
                                    maxLength={field === 'state' ? 2 : 255}
                                    value={http.data.address[field]}
                                    onChange={(event) =>
                                        setAddress(field, event.target.value)
                                    }
                                />
                            </FormField>
                        ))}
                    </fieldset>
                    {errors.link_existing && (
                        <div className="space-y-2 rounded-md border border-amber-500/50 p-3">
                            <InputError message={errors.link_existing} />
                            <CheckboxField
                                id="new-customer-link"
                                label="Usar a pessoa já cadastrada como cliente"
                                checked={http.data.link_existing}
                                onChange={(checked) =>
                                    http.setData('link_existing', checked)
                                }
                            />
                        </div>
                    )}
                    <DialogFooter>
                        <Button disabled={http.processing}>
                            Cadastrar e selecionar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Cliente da venda: pesquisa por nome, documento ou telefone, com cadastro simplificado opcional. */
export default function CustomerPicker({
    customer,
    canRegister,
    allowsAnonymous,
    error,
    onChange,
}: {
    customer: SaleCustomer | null;
    canRegister: boolean;
    allowsAnonymous: boolean;
    error?: string;
    onChange: (customer: SaleCustomer | null) => void;
}) {
    if (customer) {
        return (
            <div className="space-y-1">
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted/50 p-2 text-sm">
                    <span>
                        <strong>{customer.name}</strong>
                        <span className="text-muted-foreground">
                            {customer.document && ` · ${customer.document}`}
                            {customer.phone && ` · ${customer.phone}`}
                        </span>
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onChange(null)}
                    >
                        Trocar
                    </Button>
                </div>
                <InputError message={error} />
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-start gap-2">
                <div className="min-w-64 flex-1">
                    <RemoteLookup<SaleCustomer>
                        id="customer-lookup"
                        placeholder="Nome, CPF/CNPJ ou telefone"
                        url={(term) => lookup.url({ query: { term } })}
                        render={(item) => (
                            <>
                                {item.name}
                                <span className="text-muted-foreground">
                                    {item.document && ` · ${item.document}`}
                                    {item.phone && ` · ${item.phone}`}
                                </span>
                            </>
                        )}
                        onSelect={onChange}
                    />
                </div>
                {canRegister && <CreateCustomerDialog onCreated={onChange} />}
            </div>
            <p className="text-xs text-muted-foreground">
                {allowsAnonymous
                    ? 'Sem cliente selecionado, a venda é registrada como consumidor não identificado.'
                    : 'O cliente é obrigatório.'}
            </p>
            <InputError message={error} />
        </div>
    );
}
