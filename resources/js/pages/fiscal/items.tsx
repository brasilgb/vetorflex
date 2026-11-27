import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import { fiscalBreadcrumb } from '@/components/fiscal/fiscal-nav';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index, update } from '@/routes/fiscal/items';
import type { Paginated } from '@/types';

type Item = {
    id: number;
    name: string;
    internal_code: string | null;
    is_service: boolean;
    ncm: string | null;
    cest: string | null;
    merchandise_origin: string | null;
    cfop: string | null;
    icms_situation: string | null;
    pis_situation: string | null;
    cofins_situation: string | null;
    service_code: string | null;
    service_city_code: string | null;
    tax_unit: string | null;
};

type Field = Exclude<
    keyof Item,
    'id' | 'name' | 'internal_code' | 'is_service'
>;

const GOODS: [Field, string][] = [
    ['ncm', 'NCM'],
    ['cest', 'CEST'],
    ['merchandise_origin', 'Origem'],
    ['cfop', 'CFOP'],
    ['icms_situation', 'CST/CSOSN'],
    ['pis_situation', 'PIS'],
    ['cofins_situation', 'COFINS'],
    ['tax_unit', 'Un. tributável'],
];
const SERVICES: [Field, string][] = [
    ['service_code', 'Item LC 116'],
    ['service_city_code', 'Código municipal'],
];

function ItemRow({ item, canEdit }: { item: Item; canEdit: boolean }) {
    const [isEditing, setIsEditing] = useState(false);
    const fields = item.is_service ? SERVICES : GOODS;
    const form = useForm(
        Object.fromEntries(
            [...GOODS, ...SERVICES].map(([field]) => [
                field,
                item[field] ?? '',
            ]),
        ) as Record<Field, string>,
    );
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <li className="space-y-2 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span>
                    <span className="font-medium">{item.name}</span>
                    {item.internal_code && (
                        <span className="text-xs text-muted-foreground">
                            {' '}
                            · {item.internal_code}
                        </span>
                    )}
                    <span className="block text-xs text-muted-foreground">
                        {fields
                            .map(
                                ([field, label]) =>
                                    `${label}: ${item[field] ?? '—'}`,
                            )
                            .join(' · ')}
                    </span>
                </span>
                {canEdit && !isEditing && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setIsEditing(true)}
                    >
                        Editar dados fiscais
                    </Button>
                )}
            </div>
            {isEditing && (
                <form
                    className="flex flex-wrap items-start gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(update.url(item.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsEditing(false),
                        });
                    }}
                >
                    {fields.map(([field, label]) => (
                        <div key={field} className="w-32 space-y-1">
                            <Input
                                aria-label={label}
                                placeholder={label}
                                value={form.data[field]}
                                onChange={(event) =>
                                    form.setData(field, event.target.value)
                                }
                            />
                            {errors[field] && (
                                <p className="text-xs text-destructive">
                                    {errors[field]}
                                </p>
                            )}
                        </div>
                    ))}
                    <Button size="sm" disabled={form.processing}>
                        Salvar
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setIsEditing(false)}
                    >
                        Cancelar
                    </Button>
                </form>
            )}
        </li>
    );
}

export default function FiscalItems({
    items,
    filters,
    canEdit,
    hasProducts,
}: {
    items: Paginated<Item>;
    filters: { search: string; missing: boolean };
    canEdit: boolean;
    hasProducts: boolean;
}) {
    const form = useForm({ search: filters.search, missing: filters.missing });

    return (
        <>
            <Head title="Dados fiscais dos itens" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Dados fiscais dos itens"
                    description="Classificação fiscal do catálogo compartilhado. Confira os códigos com o contador: o sistema valida só o formato."
                />
                {!hasProducts && (
                    <p className="text-sm text-muted-foreground">
                        O módulo Produtos não está habilitado: os itens podem
                        ser informados diretamente em cada documento.
                    </p>
                )}
                <form
                    className="flex flex-wrap items-center gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            search: data.search || undefined,
                            missing: data.missing ? 1 : undefined,
                        }));
                        form.get(index.url(), { preserveState: true });
                    }}
                >
                    <Input
                        aria-label="Buscar"
                        placeholder="Nome, código ou NCM"
                        className="w-64"
                        value={form.data.search}
                        onChange={(event) =>
                            form.setData('search', event.target.value)
                        }
                    />
                    <CheckboxField
                        id="missing"
                        label="Somente com dados fiscais incompletos"
                        checked={form.data.missing}
                        onChange={(checked) => form.setData('missing', checked)}
                    />
                    <Button variant="outline" disabled={form.processing}>
                        Filtrar
                    </Button>
                </form>
                {items.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nenhum item encontrado.
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border text-sm">
                        {items.data.map((item) => (
                            <ItemRow
                                key={item.id}
                                item={item}
                                canEdit={canEdit}
                            />
                        ))}
                    </ul>
                )}
                <Pagination links={items.links} />
            </div>
        </>
    );
}

FiscalItems.layout = {
    breadcrumbs: [
        fiscalBreadcrumb,
        { title: 'Dados fiscais dos itens', href: index() },
    ],
};
