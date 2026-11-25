import { useForm } from '@inertiajs/react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import type {
    CatalogFormProps,
    CatalogRecord,
} from '@/components/products/catalog-page';
import CatalogPage from '@/components/products/catalog-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as products } from '@/routes/products';
import { index, store, update } from '@/routes/products/brands';
import type { Paginated } from '@/types';

function BrandForm({ record, onSaved }: CatalogFormProps<CatalogRecord>) {
    const form = useForm({
        name: record?.name ?? '',
        is_active: record?.is_active ?? true,
    });

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

export default function Brands({
    records,
    filters,
}: {
    records: Paginated<CatalogRecord>;
    filters: { search?: string | null };
}) {
    return (
        <CatalogPage
            title="Marcas"
            description="Marcas opcionais dos produtos da empresa."
            createLabel="Nova marca"
            permission="products.manage_brands"
            indexUrl={index.url()}
            records={records}
            search={filters.search ?? ''}
            renderForm={(props) => <BrandForm {...props} />}
        />
    );
}

Brands.layout = {
    breadcrumbs: [
        { title: 'Produtos', href: products() },
        { title: 'Marcas', href: index() },
    ],
};
