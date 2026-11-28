import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { settings } from '@/routes/finance';
import { store, update } from '@/routes/finance/categories';
import { index as importIndex } from '@/routes/finance/import';
import type { FinanceCategory, Option } from '@/types';

function CategoryDialog({
    category,
    categories,
    kinds,
}: {
    category?: FinanceCategory;
    categories: FinanceCategory[];
    kinds: Option[];
}) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        kind: category?.kind ?? 'expense',
        name: category?.name ?? '',
        parent_id:
            category?.parent_id === null || category?.parent_id === undefined
                ? ''
                : String(category.parent_id),
    };
    const form = useForm(initial);
    const parents = categories.filter(
        (item) =>
            item.parent_id === null &&
            item.kind === form.data.kind &&
            item.id !== category?.id,
    );

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                form.setData(initial);
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button
                    variant={category ? 'outline' : 'default'}
                    size={category ? 'sm' : 'default'}
                >
                    {category ? 'Editar' : 'Nova categoria'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            parent_id: data.parent_id || null,
                            is_active: category?.is_active ?? true,
                        }));
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        };

                        if (category) {
                            form.put(update.url(category.id), options);
                        } else {
                            form.post(store.url(), options);
                        }
                    }}
                >
                    <DialogTitle>
                        {category
                            ? `Editar ${category.name}`
                            : 'Nova categoria'}
                    </DialogTitle>
                    <DialogDescription>
                        Categorias organizam receitas e despesas nos relatórios
                        gerenciais.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="category-kind"
                            label="Tipo"
                            error={form.errors.kind}
                        >
                            <NativeSelect
                                id="category-kind"
                                value={form.data.kind}
                                onChange={(event) =>
                                    form.setData({
                                        ...form.data,
                                        kind: event.target.value as
                                            | 'income'
                                            | 'expense',
                                        parent_id: '',
                                    })
                                }
                            >
                                {kinds.map((kind) => (
                                    <option key={kind.value} value={kind.value}>
                                        {kind.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="category-parent"
                            label="Categoria principal (opcional)"
                            error={form.errors.parent_id}
                        >
                            <NativeSelect
                                id="category-parent"
                                value={form.data.parent_id}
                                onChange={(event) =>
                                    form.setData(
                                        'parent_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Nenhuma</option>
                                {parents.map((parent) => (
                                    <option key={parent.id} value={parent.id}>
                                        {parent.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="category-name"
                            label="Nome"
                            error={form.errors.name}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="category-name"
                                required
                                maxLength={120}
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button disabled={form.processing}>Salvar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function FinanceSettings({
    categories,
    kinds,
}: {
    categories: FinanceCategory[];
    kinds: Option[];
}) {
    const { can } = useTenant();

    return (
        <>
            <Head title="Configurações financeiras" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Configurações do Financeiro"
                        description="Categorias sugeridas já vêm cadastradas e podem ser editadas ou inativadas. Categorias em uso nunca são excluídas."
                    />
                    <CategoryDialog categories={categories} kinds={kinds} />
                </div>
                {can('finance.import_documents') && (
                    <p className="rounded-lg border p-4 text-sm">
                        Já usava Vendas ou Compras antes do Financeiro?{' '}
                        <Link className="underline" href={importIndex()}>
                            Importe os documentos elegíveis
                        </Link>{' '}
                        com prévia e seleção.
                    </p>
                )}
                <div className="grid gap-4 lg:grid-cols-2">
                    {kinds.map((kind) => {
                        const rows = categories.filter(
                            (category) => category.kind === kind.value,
                        );

                        return (
                            <section
                                key={kind.value}
                                className="space-y-2 rounded-lg border p-4"
                                aria-label={kind.label}
                            >
                                <h3 className="font-medium">{kind.label}</h3>
                                {rows.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Nenhuma categoria.
                                    </p>
                                ) : (
                                    <ul className="divide-y text-sm">
                                        {rows
                                            .sort((a, b) =>
                                                a.full_name.localeCompare(
                                                    b.full_name,
                                                ),
                                            )
                                            .map((category) => (
                                                <li
                                                    key={category.id}
                                                    className="flex flex-wrap items-center justify-between gap-2 py-2"
                                                >
                                                    <span
                                                        className={
                                                            category.parent_id ===
                                                            null
                                                                ? ''
                                                                : 'pl-4 text-muted-foreground'
                                                        }
                                                    >
                                                        {category.parent_id ===
                                                        null
                                                            ? category.name
                                                            : `↳ ${category.name}`}
                                                        {!category.is_active && (
                                                            <Badge
                                                                variant="outline"
                                                                className="ml-2"
                                                            >
                                                                Inativa
                                                            </Badge>
                                                        )}
                                                    </span>
                                                    <span className="flex gap-2">
                                                        <CategoryDialog
                                                            category={category}
                                                            categories={
                                                                categories
                                                            }
                                                            kinds={kinds}
                                                        />
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.put(
                                                                    update.url(
                                                                        category.id,
                                                                    ),
                                                                    {
                                                                        kind: category.kind,
                                                                        name: category.name,
                                                                        parent_id:
                                                                            category.parent_id,
                                                                        is_active:
                                                                            !category.is_active,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {category.is_active
                                                                ? 'Inativar'
                                                                : 'Reativar'}
                                                        </Button>
                                                    </span>
                                                </li>
                                            ))}
                                    </ul>
                                )}
                            </section>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

FinanceSettings.layout = {
    breadcrumbs: [
        financeBreadcrumb,
        { title: 'Configurações', href: settings() },
    ],
};
