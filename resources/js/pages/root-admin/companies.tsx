import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmButton from '@/components/confirm-button';
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
import { home } from '@/routes/root-admin';
import { index, status, store, update } from '@/routes/root-admin/companies';
import {
    destroy,
    store as activate,
} from '@/routes/root-admin/companies/modules';
import { index as users } from '@/routes/root-admin/users';

type Company = {
    id: number;
    name: string;
    slug: string;
    status: string;
    timezone: string;
    users_count: number;
    active_modules: number[];
};

type PlatformModule = {
    id: number;
    identifier: string;
    name: string;
    development_status: string;
    dependencies: string[] | null;
    optional_integrations: string[] | null;
};

function CompanyDialog({ company }: { company?: Company }) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        name: company?.name ?? '',
        slug: company?.slug ?? '',
        timezone: company?.timezone ?? 'America/Sao_Paulo',
    };
    const form = useForm(initial);

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
                    variant={company ? 'outline' : 'default'}
                    size={company ? 'sm' : 'default'}
                >
                    {company ? 'Editar' : 'Nova empresa'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        };

                        if (company) {
                            form.put(update.url(company.id), options);
                        } else {
                            form.post(store.url(), options);
                        }
                    }}
                >
                    <DialogTitle>
                        {company ? `Editar ${company.name}` : 'Nova empresa'}
                    </DialogTitle>
                    <DialogDescription>
                        {company
                            ? 'Os dados da empresa e dos módulos são preservados.'
                            : 'A empresa é criada sem nenhum módulo ativo.'}
                    </DialogDescription>
                    <FormField
                        id="company-name"
                        label="Nome"
                        error={form.errors.name}
                    >
                        <Input
                            id="company-name"
                            required
                            maxLength={255}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="company-slug"
                        label="Identificador"
                        hint="Letras, números, hífen e sublinhado."
                        error={form.errors.slug}
                    >
                        <Input
                            id="company-slug"
                            required
                            maxLength={80}
                            value={form.data.slug}
                            onChange={(event) =>
                                form.setData('slug', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="company-timezone"
                        label="Fuso horário"
                        error={form.errors.timezone}
                    >
                        <Input
                            id="company-timezone"
                            required
                            value={form.data.timezone}
                            onChange={(event) =>
                                form.setData('timezone', event.target.value)
                            }
                        />
                    </FormField>
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

function StatusDialog({ company }: { company: Company }) {
    const [isOpen, setIsOpen] = useState(false);
    const isActive = company.status === 'active';
    const form = useForm({
        status: isActive ? 'suspended' : 'active',
        reason: '',
    });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                form.setData({
                    status: isActive ? 'suspended' : 'active',
                    reason: '',
                });
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    {isActive ? 'Suspender' : 'Reativar'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(status.url(company.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>
                        {isActive ? 'Suspender' : 'Reativar'} {company.name}?
                    </DialogTitle>
                    <DialogDescription>
                        {isActive
                            ? 'Os usuários da empresa perdem o acesso imediatamente. Nenhum dado é apagado.'
                            : 'Os usuários com vínculo ativo voltam a acessar a empresa e os módulos já autorizados.'}
                    </DialogDescription>
                    <FormField
                        id={`status-reason-${company.id}`}
                        label="Motivo"
                        error={form.errors.reason}
                    >
                        <NativeTextarea
                            id={`status-reason-${company.id}`}
                            required
                            maxLength={400}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Voltar
                            </Button>
                        </DialogClose>
                        <Button
                            variant={isActive ? 'destructive' : 'default'}
                            disabled={form.processing}
                        >
                            {isActive ? 'Suspender' : 'Reativar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Companies({
    companies,
    modules,
}: {
    companies: Company[];
    modules: PlatformModule[];
}) {
    const { errors } = usePage().props;
    const nameOf = (identifier: string): string =>
        modules.find((module) => module.identifier === identifier)?.name ??
        identifier;

    return (
        <>
            <Head title="Empresas" />
            <section className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Empresas"
                        description="Cadastro, situação e módulos autorizados por empresa. Desativar um módulo preserva os dados."
                    />
                    <CompanyDialog />
                </div>
                <InputError message={errors.module} />
                {companies.length === 0 && (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Nenhuma empresa cadastrada.
                    </p>
                )}
                <ul className="divide-y rounded-lg border">
                    {companies.map((company) => (
                        <li className="space-y-3 p-4" key={company.id}>
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <p className="font-medium">
                                    {company.name}{' '}
                                    <Badge
                                        variant={
                                            company.status === 'active'
                                                ? 'secondary'
                                                : 'destructive'
                                        }
                                    >
                                        {company.status === 'active'
                                            ? 'Ativa'
                                            : 'Suspensa'}
                                    </Badge>
                                    <span className="block text-sm font-normal text-muted-foreground">
                                        {company.slug} · {company.timezone} ·{' '}
                                        <Link
                                            className="underline"
                                            href={users({
                                                query: { company: company.id },
                                            })}
                                        >
                                            {company.users_count} usuário(s)
                                        </Link>
                                    </span>
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <CompanyDialog company={company} />
                                    <StatusDialog company={company} />
                                </div>
                            </div>
                            <ul className="flex flex-wrap gap-2">
                                {modules.map((module) => {
                                    const parameters = {
                                        company: company.id,
                                        module: module.id,
                                    };
                                    const details = [
                                        (module.dependencies ?? []).length > 0
                                            ? `Requer ${(module.dependencies ?? []).map(nameOf).join(', ')}`
                                            : null,
                                        (module.optional_integrations ?? [])
                                            .length > 0
                                            ? `Integra com ${(module.optional_integrations ?? []).map(nameOf).join(', ')}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ');

                                    return (
                                        <li
                                            key={module.id}
                                            title={details || undefined}
                                        >
                                            {company.active_modules.includes(
                                                module.id,
                                            ) ? (
                                                <ConfirmButton
                                                    size="sm"
                                                    route={destroy(parameters)}
                                                    title={`Desativar ${module.name}`}
                                                    description={`Usuários de ${company.name} perdem o acesso ao módulo. Os dados são preservados.`}
                                                    confirmLabel="Desativar"
                                                >
                                                    {module.name}: ativo
                                                </ConfirmButton>
                                            ) : (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.visit(
                                                            activate(
                                                                parameters,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Ativar {module.name}
                                                </Button>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </li>
                    ))}
                </ul>
            </section>
        </>
    );
}

Companies.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: home() },
        { title: 'Empresas', href: index() },
    ],
};
