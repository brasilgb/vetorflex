import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ApplicationIcon from '@/components/commercial/application-icon';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeTextarea from '@/components/native-textarea';
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
import { formatMoney, parseDecimal } from '@/lib/decimal';
import { home } from '@/routes/root-admin';
import { commercial, index, update } from '@/routes/root-admin/modules';

type PlatformModule = {
    id: number;
    identifier: string;
    name: string;
    description: string;
    tagline: string | null;
    benefits: string[] | null;
    icon: string | null;
    category: string;
    sort_order: number;
    monthly_price: string | null;
    version: string;
    development_status: string;
    commercially_available: boolean;
    dependencies: string[] | null;
    permissions: { key: string; label: string }[] | null;
};

function CommercialDialog({ module }: { module: PlatformModule }) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        name: module.name,
        tagline: module.tagline ?? '',
        description: module.description,
        benefits: (module.benefits ?? []).join('\n'),
        category: module.category,
        icon: module.icon ?? '',
        sort_order: String(module.sort_order),
        monthly_price: module.monthly_price ?? '',
    };
    const form = useForm(initial);
    const errors = form.errors as Record<string, string | undefined>;

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
                <Button size="sm" variant="outline">
                    Editar cardápio
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            benefits: data.benefits
                                .split('\n')
                                .map((line) => line.trim())
                                .filter(Boolean),
                            sort_order: Number(data.sort_order),
                            monthly_price:
                                data.monthly_price === ''
                                    ? null
                                    : parseDecimal(data.monthly_price),
                            icon: data.icon || null,
                            tagline: data.tagline || null,
                        }));
                        form.put(commercial.url(module.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>{module.name}: dados comerciais</DialogTitle>
                    <DialogDescription>
                        Exibidos na página pública, no cadastro e nas telas das
                        empresas. Sem valor mensal, a aplicação aparece como
                        “valor sob consulta”.
                    </DialogDescription>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id={`name-${module.id}`}
                            label="Nome comercial"
                            error={errors.name}
                        >
                            <Input
                                id={`name-${module.id}`}
                                required
                                maxLength={80}
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id={`price-${module.id}`}
                            label="Valor mensal (R$)"
                            hint="Em branco: sob consulta."
                            error={errors.monthly_price}
                        >
                            <Input
                                id={`price-${module.id}`}
                                inputMode="decimal"
                                value={form.data.monthly_price}
                                onChange={(event) =>
                                    form.setData(
                                        'monthly_price',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id={`tagline-${module.id}`}
                            label="Frase curta"
                            error={errors.tagline}
                            className="sm:col-span-2"
                        >
                            <Input
                                id={`tagline-${module.id}`}
                                maxLength={160}
                                value={form.data.tagline}
                                onChange={(event) =>
                                    form.setData('tagline', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id={`description-${module.id}`}
                            label="Descrição"
                            error={errors.description}
                            className="sm:col-span-2"
                        >
                            <NativeTextarea
                                id={`description-${module.id}`}
                                required
                                maxLength={1000}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id={`benefits-${module.id}`}
                            label="Benefícios (um por linha, até 8)"
                            error={errors.benefits ?? errors['benefits.0']}
                            className="sm:col-span-2"
                        >
                            <NativeTextarea
                                id={`benefits-${module.id}`}
                                value={form.data.benefits}
                                onChange={(event) =>
                                    form.setData('benefits', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id={`category-${module.id}`}
                            label="Categoria"
                            error={errors.category}
                        >
                            <Input
                                id={`category-${module.id}`}
                                required
                                maxLength={40}
                                value={form.data.category}
                                onChange={(event) =>
                                    form.setData('category', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id={`icon-${module.id}`}
                            label="Ícone"
                            hint="users, package, receipt, wallet, store…"
                            error={errors.icon}
                        >
                            <Input
                                id={`icon-${module.id}`}
                                maxLength={40}
                                value={form.data.icon}
                                onChange={(event) =>
                                    form.setData('icon', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id={`order-${module.id}`}
                            label="Ordem de apresentação"
                            error={errors.sort_order}
                        >
                            <Input
                                id={`order-${module.id}`}
                                type="number"
                                min={0}
                                value={form.data.sort_order}
                                onChange={(event) =>
                                    form.setData(
                                        'sort_order',
                                        event.target.value,
                                    )
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

export default function Modules({ modules }: { modules: PlatformModule[] }) {
    const { errors } = usePage().props;
    const nameOf = (identifier: string) =>
        modules.find((module) => module.identifier === identifier)?.name ??
        identifier;

    return (
        <>
            <Head title="Catálogo de aplicações" />
            <section className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Catálogo de aplicações"
                    description="Fonte única do cardápio público. Disponibilizar comercialmente não ativa a aplicação em nenhuma empresa."
                />
                <InputError message={errors.commercially_available} />
                <div className="grid gap-4 md:grid-cols-2">
                    {modules.map((module) => {
                        const isImplemented =
                            module.development_status === 'implemented';

                        return (
                            <article
                                className="space-y-3 rounded-lg border p-4"
                                key={module.id}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex items-start gap-3">
                                        <ApplicationIcon
                                            name={module.icon}
                                            className="mt-0.5 size-5 text-brand"
                                        />
                                        <div>
                                            <h2 className="font-medium">
                                                {module.name}
                                            </h2>
                                            <p className="text-sm text-muted-foreground">
                                                {module.tagline ??
                                                    module.description}
                                            </p>
                                        </div>
                                    </div>
                                    <Badge
                                        variant={
                                            module.commercially_available
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {module.commercially_available
                                            ? 'À venda'
                                            : 'Fora da venda'}
                                    </Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {module.identifier} · versão{' '}
                                    {module.version} ·{' '}
                                    {isImplemented
                                        ? 'implementado'
                                        : 'em desenvolvimento'}{' '}
                                    · {module.permissions?.length ?? 0}{' '}
                                    permissões · {module.category} · ordem{' '}
                                    {module.sort_order}
                                    {(module.dependencies ?? []).length > 0 &&
                                        ` · requer ${(module.dependencies ?? []).map(nameOf).join(', ')}`}
                                </p>
                                <p className="text-sm font-medium">
                                    {module.monthly_price
                                        ? `${formatMoney(module.monthly_price)}/mês`
                                        : 'Valor sob consulta'}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <CommercialDialog module={module} />
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={
                                            !isImplemented &&
                                            !module.commercially_available
                                        }
                                        onClick={() =>
                                            router.visit(update(module.id), {
                                                data: {
                                                    commercially_available:
                                                        !module.commercially_available,
                                                },
                                                preserveScroll: true,
                                            })
                                        }
                                    >
                                        {module.commercially_available
                                            ? 'Retirar da venda'
                                            : 'Disponibilizar comercialmente'}
                                    </Button>
                                </div>
                            </article>
                        );
                    })}
                </div>
            </section>
        </>
    );
}

Modules.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: home() },
        { title: 'Catálogo de aplicações', href: index() },
    ],
};
