import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { lookup } from '@/routes/products';
import type { SellableUnit } from '@/types';

/** Aceita digitação ou leitor de código de barras, que envia o código seguido de Enter. */
export default function ItemLookup({
    id,
    onSelect,
}: {
    id: string;
    onSelect: (item: SellableUnit) => void;
}) {
    const [term, setTerm] = useState('');
    const [items, setItems] = useState<SellableUnit[] | null>(null);
    const [error, setError] = useState<string>();
    const http = useHttp<Record<string, never>, SellableUnit[]>({});

    function search() {
        if (term.trim() === '' || http.processing) {
            return;
        }

        setError(undefined);
        http.get(lookup.url({ query: { term: term.trim() } }), {
            onSuccess: (found) => {
                if (found.length === 1) {
                    setTerm('');
                    setItems(null);
                    onSelect(found[0]);

                    return;
                }

                setItems(found);
            },
            onError: () => setError('Informe um termo de até 100 caracteres.'),
        }).catch(() =>
            setError('Não foi possível pesquisar. Tente novamente.'),
        );
    }

    return (
        <div className="space-y-2">
            <div className="flex gap-2">
                <Input
                    id={id}
                    autoComplete="off"
                    placeholder="Nome, SKU ou código de barras"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            search();
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    disabled={http.processing}
                    onClick={search}
                >
                    Buscar
                </Button>
            </div>
            <InputError message={error} />
            {items !== null && items.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Nenhum item ativo encontrado.
                </p>
            )}
            {items !== null && items.length > 0 && (
                <ul className="max-h-48 divide-y overflow-y-auto rounded-md border">
                    {items.map((item) => (
                        <li key={item.id}>
                            <button
                                type="button"
                                className="w-full p-2 text-left text-sm hover:bg-muted"
                                onClick={() => {
                                    setItems(null);
                                    setTerm('');
                                    onSelect(item);
                                }}
                            >
                                {item.name}
                                {item.sku !== null && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {item.sku}
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
