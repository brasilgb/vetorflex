import { Head, Link, useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import type { Paginated } from '@/types';

export type CatalogRecord = {
    id: number;
    name: string;
    is_active: boolean;
    products_count: number;
};

export type CatalogFormProps<T> = { record: T | null; onSaved: () => void };

function CatalogDialog({
    title,
    trigger,
    variant,
    children,
}: {
    title: string;
    trigger: string;
    variant: 'default' | 'outline';
    children: (close: () => void) => ReactNode;
}) {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant={variant}
                    size={variant === 'outline' ? 'sm' : 'default'}
                >
                    {trigger}
                </Button>
            </DialogTrigger>
            <DialogContent aria-describedby={undefined}>
                <DialogTitle>{title}</DialogTitle>
                {children(() => setIsOpen(false))}
            </DialogContent>
        </Dialog>
    );
}

/** Estrutura comum de categorias, marcas e unidades: pesquisa, lista e cadastro em diálogo. */
export default function CatalogPage<T extends CatalogRecord>({
    title,
    description,
    createLabel,
    permission,
    indexUrl,
    records,
    search,
    detail,
    renderForm,
}: {
    title: string;
    description: string;
    createLabel: string;
    permission: string;
    indexUrl: string;
    records: Paginated<T>;
    search: string;
    detail?: (record: T) => string | null;
    renderForm: (props: CatalogFormProps<T>) => ReactNode;
}) {
    const { can } = useTenant();
    const canManage = can(permission);
    const form = useForm({ search });

    return (
        <>
            <Head title={title} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title={title}
                        description={description}
                    />
                    {canManage && (
                        <CatalogDialog
                            title={createLabel}
                            trigger={createLabel}
                            variant="default"
                        >
                            {(close) =>
                                renderForm({ record: null, onSaved: close })
                            }
                        </CatalogDialog>
                    )}
                </div>
                <form
                    className="flex max-w-xl gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(indexUrl, { preserveState: true });
                    }}
                >
                    <Input
                        type="search"
                        aria-label="Pesquisar"
                        placeholder="Pesquisar"
                        value={form.data.search}
                        onChange={(event) =>
                            form.setData('search', event.target.value)
                        }
                    />
                    <Button variant="outline" disabled={form.processing}>
                        Pesquisar
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={indexUrl} preserveState={false}>
                            Limpar
                        </Link>
                    </Button>
                </form>
                <ul className="divide-y rounded-lg border">
                    {records.data.length === 0 && (
                        <li className="p-6 text-sm text-muted-foreground">
                            Nenhum registro encontrado.
                        </li>
                    )}
                    {records.data.map((record) => (
                        <li
                            key={record.id}
                            className="flex items-center justify-between gap-4 p-4"
                        >
                            <div className="min-w-0">
                                <p className="truncate font-medium">
                                    {record.name}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {[
                                        detail?.(record),
                                        `${record.products_count} produto(s)`,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <Badge
                                    variant={
                                        record.is_active
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {record.is_active ? 'Ativo' : 'Inativo'}
                                </Badge>
                                {canManage && (
                                    <CatalogDialog
                                        title={`Editar ${record.name}`}
                                        trigger="Editar"
                                        variant="outline"
                                    >
                                        {(close) =>
                                            renderForm({
                                                record,
                                                onSaved: close,
                                            })
                                        }
                                    </CatalogDialog>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
                <Pagination links={records.links} />
            </div>
        </>
    );
}
