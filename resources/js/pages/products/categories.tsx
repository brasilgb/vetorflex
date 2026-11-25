import { useForm } from '@inertiajs/react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import NativeSelect from '@/components/native-select';
import type {
    CatalogFormProps,
    CatalogRecord,
} from '@/components/products/catalog-page';
import CatalogPage from '@/components/products/catalog-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as products } from '@/routes/products';
import { index, store, update } from '@/routes/products/categories';
import type { NamedRecord, Paginated } from '@/types';

type Category = CatalogRecord & {
    parent_id: number | null;
    parent: string | null;
};

function CategoryForm({
    record,
    parents,
    onSaved,
}: CatalogFormProps<Category> & { parents: NamedRecord[] }) {
    const form = useForm({
        name: record?.name ?? '',
        parent_id: String(record?.parent_id ?? ''),
        is_active: record?.is_active ?? true,
    });
    const hasInactiveParent =
        record !== null &&
        record.parent_id !== null &&
        !parents.some(({ id }) => id === record.parent_id);

    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.submit(record === null ? store() : update(record.id), {
                    preserveScroll: true,
                    onSuccess: onSaved,
                });
            }}
        >
            <FormField id="name" label="Nome" error={form.errors.name}>
                <Input
                    id="name"
                    required
                    maxLength={120}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </FormField>
            <FormField
                id="parent_id"
                label="Categoria superior"
                error={form.errors.parent_id}
            >
                <NativeSelect
                    id="parent_id"
                    value={form.data.parent_id}
                    onChange={(event) =>
                        form.setData('parent_id', event.target.value)
                    }
                >
                    <option value="">Nenhuma (categoria principal)</option>
                    {hasInactiveParent && (
                        <option value={String(record.parent_id)}>
                            {record.parent} (inativa)
                        </option>
                    )}
                    {parents
                        .filter(({ id }) => id !== record?.id)
                        .map((parent) => (
                            <option key={parent.id} value={parent.id}>
                                {parent.name}
                            </option>
                        ))}
                </NativeSelect>
            </FormField>
            <CheckboxField
                id="is_active"
                label="Ativa"
                checked={form.data.is_active}
                error={form.errors.is_active}
                onChange={(checked) => form.setData('is_active', checked)}
            />
            <Button disabled={form.processing}>Salvar</Button>
        </form>
    );
}

export default function Categories({
    records,
    parents,
    filters,
}: {
    records: Paginated<Category>;
    parents: NamedRecord[];
    filters: { search?: string | null };
}) {
    return (
        <CatalogPage
            title="Categorias"
            description="Categorias e subcategorias do catálogo."
            createLabel="Nova categoria"
            permission="products.manage_categories"
            indexUrl={index.url()}
            records={records}
            search={filters.search ?? ''}
            detail={({ parent }) =>
                parent === null ? null : `Subcategoria de ${parent}`
            }
            renderForm={(props) => (
                <CategoryForm {...props} parents={parents} />
            )}
        />
    );
}

Categories.layout = {
    breadcrumbs: [
        { title: 'Produtos', href: products() },
        { title: 'Categorias', href: index() },
    ],
};
