import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
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
import { useTenant } from '@/hooks/use-tenant';
import { formatMoney } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { home } from '@/routes/root-admin';
import {
    contract,
    index,
    status,
    store,
    trial,
    update,
} from '@/routes/root-admin/companies';
import {
    destroy,
    store as activate,
} from '@/routes/root-admin/companies/modules';
import { index as users } from '@/routes/root-admin/users';
import type { SubscriptionSummary } from '@/types';

type Company = {
    id: number;
    name: string;
    slug: string;
    status: string;
    timezone: string;
    users_count: number;
    active_modules: number[];
    origin: string;
    subscription: SubscriptionSummary;
    contracted_at: string | null;
    monthly_total: string | null;
};

type PlatformModule = {
    id: number;
    identifier: string;
    name: string;
    development_status: string;
    dependencies: string[] | null;
    optional_integrations: string[] | null;
    monthly_price: string | null;
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
                <Button variant="outline" size="sm">
                    Editar
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

function NewCompanyDialog({
    modules,
    trialDays,
}: {
    modules: PlatformModule[];
    trialDays: number;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const implemented = modules.filter(
        (module) => module.development_status === 'implemented',
    );
    const initial = {
        name: '',
        slug: '',
        timezone: 'America/Sao_Paulo',
        document: '',
        owner_name: '',
        owner_email: '',
        modules: [] as string[],
        is_contracted: false,
        trial_days: String(trialDays),
    };
    const form = useForm(initial);
    const errors = form.errors as Record<string, string | undefined>;
    const nameOf = (identifier: string) =>
        modules.find((module) => module.identifier === identifier)?.name ??
        identifier;
    const required = new Set<string>();
    const visit = (identifier: string) =>
        (
            modules.find((module) => module.identifier === identifier)
                ?.dependencies ?? []
        ).forEach((dependency) => {
            if (!required.has(dependency)) {
                required.add(dependency);
                visit(dependency);
            }
        });
    form.data.modules.forEach(visit);
    const autoIncluded = [...required].filter(
        (identifier) => !form.data.modules.includes(identifier),
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
                <Button>Nova empresa</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            name: data.name,
                            slug: data.slug,
                            timezone: data.timezone,
                            document: data.document || null,
                            owner_name: data.owner_name || null,
                            owner_email: data.owner_email || null,
                            modules: data.modules,
                            trial_days: data.is_contracted
                                ? null
                                : Number(data.trial_days),
                            idempotency_key: uuid(),
                        }));
                        form.post(store.url(), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Nova empresa</DialogTitle>
                    <DialogDescription>
                        Usa o mesmo provisionamento do cadastro público:
                        empresa, proprietário, aplicações com dependências e
                        período de teste.
                    </DialogDescription>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id="new-name"
                            label="Nome"
                            error={errors.name}
                        >
                            <Input
                                id="new-name"
                                required
                                maxLength={255}
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="new-slug"
                            label="Identificador"
                            hint="Letras, números, hífen e sublinhado."
                            error={errors.slug}
                        >
                            <Input
                                id="new-slug"
                                required
                                maxLength={80}
                                value={form.data.slug}
                                onChange={(event) =>
                                    form.setData('slug', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="new-document"
                            label="CNPJ ou CPF (opcional)"
                            error={errors.document}
                        >
                            <Input
                                id="new-document"
                                value={form.data.document}
                                onChange={(event) =>
                                    form.setData('document', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="new-timezone"
                            label="Fuso horário"
                            error={errors.timezone}
                        >
                            <Input
                                id="new-timezone"
                                required
                                value={form.data.timezone}
                                onChange={(event) =>
                                    form.setData('timezone', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="new-owner-name"
                            label="Nome do responsável"
                            error={errors.owner_name}
                        >
                            <Input
                                id="new-owner-name"
                                value={form.data.owner_name}
                                onChange={(event) =>
                                    form.setData(
                                        'owner_name',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="new-owner-email"
                            label="E-mail do responsável"
                            hint="Conta nova recebe um link para definir a senha; conta existente é vinculada."
                            error={errors.owner_email}
                        >
                            <Input
                                id="new-owner-email"
                                type="email"
                                value={form.data.owner_email}
                                onChange={(event) =>
                                    form.setData(
                                        'owner_email',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                    </div>
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Aplicações
                        </legend>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {implemented.map((module) => (
                                <CheckboxField
                                    key={module.identifier}
                                    id={`new-module-${module.identifier}`}
                                    label={module.name}
                                    checked={
                                        form.data.modules.includes(
                                            module.identifier,
                                        ) || required.has(module.identifier)
                                    }
                                    disabled={
                                        required.has(module.identifier) &&
                                        !form.data.modules.includes(
                                            module.identifier,
                                        )
                                    }
                                    onChange={(checked) =>
                                        form.setData(
                                            'modules',
                                            checked
                                                ? [
                                                      ...form.data.modules,
                                                      module.identifier,
                                                  ]
                                                : form.data.modules.filter(
                                                      (value) =>
                                                          value !==
                                                          module.identifier,
                                                  ),
                                        )
                                    }
                                />
                            ))}
                        </div>
                        {autoIncluded.length > 0 && (
                            <p className="text-xs text-muted-foreground">
                                Incluídas por dependência:{' '}
                                {autoIncluded.map(nameOf).join(', ')}.
                            </p>
                        )}
                        <InputError message={errors.modules} />
                    </fieldset>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <CheckboxField
                            id="new-contracted"
                            label="Já contratada (sem período de teste)"
                            checked={form.data.is_contracted}
                            onChange={(checked) =>
                                form.setData('is_contracted', checked)
                            }
                        />
                        {!form.data.is_contracted && (
                            <FormField
                                id="new-trial"
                                label="Dias de teste"
                                error={errors.trial_days}
                            >
                                <Input
                                    id="new-trial"
                                    type="number"
                                    min={1}
                                    max={90}
                                    value={form.data.trial_days}
                                    onChange={(event) =>
                                        form.setData(
                                            'trial_days',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        )}
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button disabled={form.processing}>Cadastrar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CommercialActions({ company }: { company: Company }) {
    const [dialog, setDialog] = useState<'trial' | 'contract' | null>(null);
    const trialForm = useForm({ trial_ends_at: '', reason: '' });
    const contractForm = useForm({ reason: '' });

    return (
        <>
            {company.subscription.status !== 'active' && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setDialog('trial')}
                >
                    Prorrogar teste
                </Button>
            )}
            {company.subscription.status !== 'active' && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setDialog('contract')}
                >
                    Registrar contratação
                </Button>
            )}
            <Dialog
                open={dialog === 'trial'}
                onOpenChange={(open) => {
                    setDialog(open ? 'trial' : null);
                    trialForm.reset();
                    trialForm.clearErrors();
                }}
            >
                <DialogContent>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            trialForm.patch(trial.url(company.id), {
                                preserveScroll: true,
                                onSuccess: () => setDialog(null),
                            });
                        }}
                    >
                        <DialogTitle>
                            Prorrogar teste de {company.name}
                        </DialogTitle>
                        <DialogDescription>
                            A prorrogação fica registrada na auditoria com a
                            justificativa.
                        </DialogDescription>
                        <FormField
                            id={`trial-until-${company.id}`}
                            label="Novo vencimento"
                            error={trialForm.errors.trial_ends_at}
                        >
                            <Input
                                id={`trial-until-${company.id}`}
                                type="date"
                                required
                                value={trialForm.data.trial_ends_at}
                                onChange={(event) =>
                                    trialForm.setData(
                                        'trial_ends_at',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id={`trial-reason-${company.id}`}
                            label="Justificativa"
                            error={trialForm.errors.reason}
                        >
                            <NativeTextarea
                                id={`trial-reason-${company.id}`}
                                required
                                maxLength={400}
                                value={trialForm.data.reason}
                                onChange={(event) =>
                                    trialForm.setData(
                                        'reason',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    Voltar
                                </Button>
                            </DialogClose>
                            <Button disabled={trialForm.processing}>
                                Prorrogar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog
                open={dialog === 'contract'}
                onOpenChange={(open) => {
                    setDialog(open ? 'contract' : null);
                    contractForm.reset();
                    contractForm.clearErrors();
                }}
            >
                <DialogContent>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            contractForm.patch(contract.url(company.id), {
                                preserveScroll: true,
                                onSuccess: () => setDialog(null),
                            });
                        }}
                    >
                        <DialogTitle>
                            Registrar contratação de {company.name}
                        </DialogTitle>
                        <DialogDescription>
                            Libera o uso sem prazo de teste. Nenhuma cobrança é
                            feita pelo sistema: informe a referência do contrato
                            ou da proposta aceita.
                        </DialogDescription>
                        <FormField
                            id={`contract-reason-${company.id}`}
                            label="Referência da contratação"
                            error={contractForm.errors.reason}
                        >
                            <NativeTextarea
                                id={`contract-reason-${company.id}`}
                                required
                                maxLength={400}
                                value={contractForm.data.reason}
                                onChange={(event) =>
                                    contractForm.setData(
                                        'reason',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    Voltar
                                </Button>
                            </DialogClose>
                            <Button disabled={contractForm.processing}>
                                Registrar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
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
    counts,
    filter,
    trialDays,
}: {
    companies: Company[];
    modules: PlatformModule[];
    counts: Record<'trial' | 'active' | 'expired' | 'suspended', number>;
    filter: string | null;
    trialDays: number;
}) {
    const { formatDateTime } = useTenant();
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
                    <NewCompanyDialog modules={modules} trialDays={trialDays} />
                </div>
                <nav
                    aria-label="Filtrar por situação comercial"
                    className="flex flex-wrap gap-2 text-sm"
                >
                    {(
                        [
                            [null, 'Todas'],
                            ['trial', `Em teste (${counts.trial})`],
                            ['active', `Contratadas (${counts.active})`],
                            ['expired', `Teste encerrado (${counts.expired})`],
                            ['suspended', `Suspensas (${counts.suspended})`],
                        ] as [string | null, string][]
                    ).map(([value, label]) => (
                        <Link
                            key={label}
                            href={index({
                                query: value ? { commercial: value } : {},
                            })}
                            className={`rounded-full border px-3 py-1 ${filter === value ? 'border-brand bg-brand-soft text-brand' : ''}`}
                        >
                            {label}
                        </Link>
                    ))}
                </nav>
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
                                    </Badge>{' '}
                                    <Badge
                                        variant={
                                            company.subscription.status ===
                                            'active'
                                                ? 'default'
                                                : company.subscription
                                                        .status === 'trial'
                                                  ? 'outline'
                                                  : 'destructive'
                                        }
                                    >
                                        {company.subscription.label}
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
                                    <span className="block text-xs font-normal text-muted-foreground">
                                        Origem:{' '}
                                        {company.origin === 'public'
                                            ? 'cadastro público'
                                            : company.origin === 'demo'
                                              ? 'demonstração'
                                              : 'RootAdmin'}
                                        {company.subscription
                                            .trial_started_at &&
                                            ` · teste desde ${formatDateTime(company.subscription.trial_started_at)}`}
                                        {company.subscription.trial_ends_at &&
                                            ` · vence ${formatDateTime(company.subscription.trial_ends_at)}`}
                                        {company.contracted_at &&
                                            ` · contratada em ${formatDateTime(company.contracted_at)}`}
                                        {` · ${company.active_modules.length} aplicação(ões)`}
                                        {company.monthly_total &&
                                            ` · referência ${formatMoney(company.monthly_total)}/mês`}
                                    </span>
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <CompanyDialog company={company} />
                                    <CommercialActions company={company} />
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
