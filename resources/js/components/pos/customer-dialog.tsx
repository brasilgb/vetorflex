import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { PosCustomer } from '@/lib/pos-cart';
import { lookup, store } from '@/routes/pos/customers';

type Found = {
    id: number;
    name: string;
    document: string | null;
    phone?: string | null;
};

/** Seleção de cliente por nome, documento ou telefone, com cadastro simplificado (pessoas compartilhadas). */
export default function CustomerDialog({
    open,
    onOpenChange,
    onSelect,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSelect: (customer: PosCustomer | null) => void;
}) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<Found[] | null>(null);
    const [searchError, setSearchError] = useState<string>();
    const search = useHttp<Record<string, never>, Found[]>({});
    const create = useHttp<
        { type: string; name: string; document: string; phone: string },
        Found
    >({ type: 'individual', name: '', document: '', phone: '' });

    function choose(customer: Found) {
        onSelect({
            id: customer.id,
            name: customer.name,
            document: customer.document,
        });
        setTerm('');
        setResults(null);
        create.reset();
        onOpenChange(false);
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogTitle>Cliente</DialogTitle>
                <DialogDescription>
                    Busque por nome, CPF/CNPJ ou telefone. A venda pode seguir
                    sem identificação, conforme a configuração do PDV.
                </DialogDescription>
                <form
                    className="flex gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (term.trim() === '') {
                            return;
                        }

                        setSearchError(undefined);
                        search
                            .get(lookup.url({ query: { term: term.trim() } }), {
                                onSuccess: setResults,
                            })
                            .catch(() =>
                                setSearchError(
                                    'Não foi possível pesquisar. Verifique a conexão.',
                                ),
                            );
                    }}
                >
                    <Input
                        autoFocus
                        aria-label="Buscar cliente"
                        placeholder="Nome, documento ou telefone"
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                    <Button variant="outline" disabled={search.processing}>
                        Buscar
                    </Button>
                </form>
                {searchError && (
                    <p className="text-sm text-destructive">{searchError}</p>
                )}
                {results !== null && (
                    <ul className="max-h-56 divide-y overflow-y-auto rounded-md border text-sm">
                        {results.length === 0 && (
                            <li className="p-3 text-muted-foreground">
                                Nenhum cliente encontrado. Cadastre abaixo.
                            </li>
                        )}
                        {results.map((customer) => (
                            <li key={customer.id}>
                                <button
                                    type="button"
                                    className="w-full p-3 text-left hover:bg-muted"
                                    onClick={() => choose(customer)}
                                >
                                    {customer.name}
                                    <span className="block text-xs text-muted-foreground">
                                        {[customer.document, customer.phone]
                                            .filter(Boolean)
                                            .join(' · ') || 'Sem documento'}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <form
                    className="space-y-3 rounded-md border p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        create
                            .post(store.url(), { onSuccess: choose })
                            .catch(() =>
                                setSearchError(
                                    'Não foi possível cadastrar. Verifique a conexão.',
                                ),
                            );
                    }}
                >
                    <p className="text-sm font-medium">Cadastro rápido</p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="pos-customer-name"
                            label="Nome"
                            error={create.errors.name}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="pos-customer-name"
                                required
                                value={create.data.name}
                                onChange={(event) =>
                                    create.setData('name', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField id="pos-customer-type" label="Tipo">
                            <NativeSelect
                                id="pos-customer-type"
                                value={create.data.type}
                                onChange={(event) =>
                                    create.setData('type', event.target.value)
                                }
                            >
                                <option value="individual">
                                    Pessoa física
                                </option>
                                <option value="company">Pessoa jurídica</option>
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="pos-customer-document"
                            label="CPF/CNPJ (opcional)"
                            error={create.errors.document}
                        >
                            <Input
                                id="pos-customer-document"
                                inputMode="numeric"
                                value={create.data.document}
                                onChange={(event) =>
                                    create.setData(
                                        'document',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="pos-customer-phone"
                            label="Telefone (opcional)"
                            error={create.errors.phone}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="pos-customer-phone"
                                inputMode="tel"
                                value={create.data.phone}
                                onChange={(event) =>
                                    create.setData('phone', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <div className="flex flex-wrap justify-between gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => {
                                onSelect(null);
                                onOpenChange(false);
                            }}
                        >
                            Vender sem identificação
                        </Button>
                        <Button disabled={create.processing}>
                            Cadastrar e selecionar
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
