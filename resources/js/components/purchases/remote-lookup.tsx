import { useHttp } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Pesquisa por Enter ou botão. Um único resultado é selecionado de imediato, o que atende leitores de código de barras.
 */
export default function RemoteLookup<T extends { id: number }>({
    id,
    url,
    placeholder,
    render,
    onSelect,
}: {
    id: string;
    url: (term: string) => string;
    placeholder: string;
    render: (item: T) => ReactNode;
    onSelect: (item: T) => void;
}) {
    const [term, setTerm] = useState('');
    const [items, setItems] = useState<T[] | null>(null);
    const [error, setError] = useState<string>();
    const http = useHttp<Record<string, never>, T[]>({});

    function select(item: T) {
        setTerm('');
        setItems(null);
        onSelect(item);
    }

    function search() {
        if (term.trim() === '' || http.processing) {
            return;
        }

        setError(undefined);
        http.get(url(term.trim()), {
            onSuccess: (found) =>
                found.length === 1 ? select(found[0]) : setItems(found),
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
                    placeholder={placeholder}
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
                    Nenhum resultado ativo encontrado.
                </p>
            )}
            {items !== null && items.length > 0 && (
                <ul className="max-h-56 divide-y overflow-y-auto rounded-md border">
                    {items.map((item) => (
                        <li key={item.id}>
                            <button
                                type="button"
                                className="w-full p-2 text-left text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                onClick={() => select(item)}
                            >
                                {render(item)}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
