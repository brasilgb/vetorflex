import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import RemoteLookup from '@/components/remote-lookup';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { lookup, store } from '@/routes/finance/parties';
import type { FinanceParty } from '@/types';

/**
 * Pessoa vinculada ao título: qualquer cliente, fornecedor ou contato da empresa, ou um cadastro simplificado,
 * sem exigir os módulos Clientes ou Compras.
 */
export default function PartyPicker({
    value,
    onChange,
}: {
    value: FinanceParty | null;
    onChange: (party: FinanceParty | null) => void;
}) {
    const [isCreating, setIsCreating] = useState(false);
    const http = useHttp<
        { type: string; name: string; document: string },
        FinanceParty
    >({ type: 'individual', name: '', document: '' });

    if (value !== null) {
        return (
            <div className="flex items-center justify-between gap-2 rounded-md border p-2 text-sm">
                <span>
                    {value.name}
                    {value.document && (
                        <span className="block text-xs text-muted-foreground">
                            {value.document}
                        </span>
                    )}
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => onChange(null)}
                >
                    Remover
                </Button>
            </div>
        );
    }

    if (isCreating) {
        return (
            <div className="space-y-2 rounded-md border p-3">
                <div className="grid gap-2 sm:grid-cols-[8rem_1fr]">
                    <select
                        aria-label="Tipo de pessoa"
                        className="h-9 rounded-md border bg-background px-2 text-sm"
                        value={http.data.type}
                        onChange={(event) =>
                            http.setData('type', event.target.value)
                        }
                    >
                        <option value="individual">Pessoa física</option>
                        <option value="company">Pessoa jurídica</option>
                    </select>
                    <Input
                        aria-label="Nome"
                        placeholder="Nome"
                        value={http.data.name}
                        onChange={(event) =>
                            http.setData('name', event.target.value)
                        }
                    />
                </div>
                <Input
                    aria-label="CPF ou CNPJ (opcional)"
                    placeholder="CPF ou CNPJ (opcional)"
                    value={http.data.document}
                    onChange={(event) =>
                        http.setData('document', event.target.value)
                    }
                />
                <InputError
                    message={http.errors.name ?? http.errors.document}
                />
                <div className="flex gap-2">
                    <Button
                        type="button"
                        size="sm"
                        disabled={http.processing}
                        onClick={() =>
                            http
                                .post(store.url(), {
                                    onSuccess: (party) => {
                                        setIsCreating(false);
                                        http.reset();
                                        onChange(party);
                                    },
                                })
                                .catch(() => undefined)
                        }
                    >
                        Cadastrar
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setIsCreating(false)}
                    >
                        Voltar
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-1">
            <RemoteLookup<FinanceParty>
                id="party-lookup"
                url={(term) => lookup.url({ query: { term } })}
                placeholder="Nome ou documento"
                render={(party) => (
                    <>
                        {party.name}
                        {party.document && (
                            <span className="block text-xs text-muted-foreground">
                                {party.document}
                            </span>
                        )}
                    </>
                )}
                onSelect={onChange}
            />
            <Button
                type="button"
                variant="link"
                size="sm"
                className="px-0"
                onClick={() => setIsCreating(true)}
            >
                Cadastrar pessoa simplificada
            </Button>
        </div>
    );
}
