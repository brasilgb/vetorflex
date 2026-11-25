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
import { index, store, update } from '@/routes/products/units';
import type { Paginated } from '@/types';

type Unit = CatalogRecord & {
    code: string;
    allows_fraction: boolean;
    is_default: boolean;
};

function UnitForm({ record, onSaved }: CatalogFormProps<Unit>) {
    const form = useForm({
        code: record?.code ?? '',
        name: record?.name ?? '',
        allows_fraction: record?.allows_fraction ?? false,
        is_active: record?.is_active ?? true,
    });
    const isDefault = record?.is_default ?? false;

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
            {isDefault && (
                <p className="text-sm text-muted-foreground">
                    Unidades padrão só podem ser ativadas ou inativadas.
                </p>
            )}
            <FormField id="code" label="Sigla" error={form.errors.code}>
                <Input
                    id="code"
                    required
                    maxLength={10}
                    disabled={isDefault}
                    value={form.data.code}
                    onChange={(event) =>
                        form.setData('code', event.target.value)
                    }
                />
            </FormField>
            <FormField id="name" label="Nome" error={form.errors.name}>
                <Input
                    id="name"
                    required
                    maxLength={60}
                    disabled={isDefault}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </FormField>
            <CheckboxField
                id="allows_fraction"
                label="Aceita quantidade fracionária"
                disabled={isDefault}
                checked={form.data.allows_fraction}
                error={form.errors.allows_fraction}
                onChange={(checked) => form.setData('allows_fraction', checked)}
            />
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

export default function Units({
    records,
    filters,
}: {
    records: Paginated<Unit>;
    filters: { search?: string | null };
}) {
    return (
        <CatalogPage
            title="Unidades de medida"
            description="Unidades comuns e personalizadas da empresa."
            createLabel="Nova unidade"
            permission="products.manage_units"
            indexUrl={index.url()}
            records={records}
            search={filters.search ?? ''}
            detail={(unit) =>
                [
                    unit.code,
                    unit.allows_fraction ? 'Fracionária' : 'Inteira',
                    unit.is_default ? 'Padrão' : 'Personalizada',
                ].join(' · ')
            }
            renderForm={(props) => <UnitForm {...props} />}
        />
    );
}

Units.layout = {
    breadcrumbs: [
        { title: 'Produtos', href: products() },
        { title: 'Unidades de medida', href: index() },
    ],
};
