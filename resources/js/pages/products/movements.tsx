import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import MovementDialog from '@/components/products/movement-dialog';
import MovementTable from '@/components/products/movement-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { index as products } from '@/routes/products';
import { index } from '@/routes/products/movements';
import type { Movement, Option, Paginated } from '@/types';

export default function Movements({
    movements,
    filters,
    types,
}: {
    movements: Paginated<Movement>;
    filters: Partial<
        Record<'search' | 'type' | 'from' | 'until', string | null>
    >;
    types: Option[];
}) {
    const { can } = useTenant();
    const form = useForm({
        search: filters.search ?? '',
        type: filters.type ?? '',
        from: filters.from ?? '',
        until: filters.until ?? '',
    });
    const { data, setData } = form;

    return (
        <>
            <Head title="Movimentações" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Movimentações"
                        description="Histórico permanente de entradas, saídas, ajustes e estornos."
                    />
                    {can('products.adjust_inventory') && <MovementDialog />}
                </div>
                <form
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        className="sm:col-span-2"
                        type="search"
                        aria-label="Pesquisar"
                        placeholder="Nome, SKU ou código de barras"
                        value={data.search}
                        onChange={(event) =>
                            setData('search', event.target.value)
                        }
                    />
                    <NativeSelect
                        aria-label="Tipo"
                        value={data.type}
                        onChange={(event) =>
                            setData('type', event.target.value)
                        }
                    >
                        <option value="">Todos os tipos</option>
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input
                        type="date"
                        aria-label="De"
                        value={data.from}
                        onChange={(event) =>
                            setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Até"
                        value={data.until}
                        onChange={(event) =>
                            setData('until', event.target.value)
                        }
                    />
                    <div className="flex gap-2">
                        <Button variant="outline" disabled={form.processing}>
                            Filtrar
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index()} preserveState={false}>
                                Limpar
                            </Link>
                        </Button>
                    </div>
                    <InputError
                        className="sm:col-span-2 lg:col-span-6"
                        message={Object.values(form.errors)[0]}
                    />
                </form>
                <MovementTable movements={movements.data} />
                <Pagination links={movements.links} />
            </div>
        </>
    );
}

Movements.layout = {
    breadcrumbs: [
        { title: 'Produtos', href: products() },
        { title: 'Movimentações', href: index() },
    ],
};
