import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    Check,
    Layers,
    LockKeyhole,
    Menu,
    Sparkles,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import ApplicationIcon from '@/components/commercial/application-icon';
import FormField from '@/components/form-field';
import NativeTextarea from '@/components/native-textarea';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/decimal';
import { dashboard, login, register } from '@/routes';
import { store as storeContact } from '@/routes/contact';
import { privacy, terms } from '@/routes/legal';
import type { CatalogApplication, CommercialContact } from '@/types';

const BENEFITS = [
    {
        title: 'Feito para o pequeno negócio',
        text: 'Telas diretas, sem jargão técnico, para quem atende, vende e cuida das contas ao mesmo tempo.',
    },
    {
        title: 'Pague pelo que usa',
        text: 'Escolha só as aplicações de que precisa hoje e inclua outras quando a empresa crescer.',
    },
    {
        title: 'Tudo conversa entre si',
        text: 'Uma venda pode baixar o estoque, gerar a conta a receber e a cobrança Pix, se essas aplicações estiverem contratadas.',
    },
    {
        title: 'Seus dados separados',
        text: 'Cada empresa enxerga somente os próprios dados, com permissões por usuário.',
    },
];

const FAQ = [
    {
        question: 'Como funciona o período de teste?',
        answer: (days: number) =>
            `Você usa as aplicações escolhidas por ${days} dias, sem cartão de crédito. Ao final, os dados continuam guardados e disponíveis para consulta; para voltar a registrar operações, basta contratar.`,
    },
    {
        question: 'Preciso contratar todas as aplicações?',
        answer: () =>
            'Não. Cada aplicação funciona sozinha. Quando uma depende de outra, o sistema mostra e inclui a dependência automaticamente.',
    },
    {
        question: 'Posso incluir ou remover aplicações depois?',
        answer: () =>
            'Sim. O responsável pela empresa gerencia as aplicações no painel. Remover uma aplicação preserva os dados já registrados.',
    },
    {
        question: 'O sistema emite nota fiscal e recebe Pix?',
        answer: () =>
            'Sim, pelas aplicações Documentos Fiscais e Pagamentos, usando a conta da própria empresa nos provedores homologados. Elas exigem configuração (certificado digital, credenciais) e só liberam a operação real depois de prontas.',
    },
    {
        question: 'É cobrado algo automaticamente?',
        answer: () => 'Não. Nenhuma cobrança é feita sem contratação expressa.',
    },
];

function resolveDependencies(
    selected: string[],
    applications: CatalogApplication[],
): string[] {
    const byId = new Map(applications.map((app) => [app.identifier, app]));
    const resolved = new Set<string>();
    const visit = (identifier: string) => {
        if (resolved.has(identifier)) {
            return;
        }

        resolved.add(identifier);
        byId.get(identifier)?.dependencies.forEach(visit);
    };
    selected.forEach(visit);

    return [...resolved];
}

function ContactForm({ applications }: { applications: string[] }) {
    const form = useForm({
        name: '',
        email: '',
        phone: '',
        company_name: '',
        message: '',
        website: '',
    });

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({ ...data, modules: applications }));
                form.post(storeContact.url(), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <FormField id="contact-name" label="Nome" error={form.errors.name}>
                <Input
                    id="contact-name"
                    required
                    autoComplete="name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </FormField>
            <FormField
                id="contact-email"
                label="E-mail"
                error={form.errors.email}
            >
                <Input
                    id="contact-email"
                    type="email"
                    required
                    autoComplete="email"
                    value={form.data.email}
                    onChange={(event) =>
                        form.setData('email', event.target.value)
                    }
                />
            </FormField>
            <FormField
                id="contact-phone"
                label="Telefone (opcional)"
                error={form.errors.phone}
            >
                <Input
                    id="contact-phone"
                    autoComplete="tel"
                    value={form.data.phone}
                    onChange={(event) =>
                        form.setData('phone', event.target.value)
                    }
                />
            </FormField>
            <FormField
                id="contact-company"
                label="Empresa (opcional)"
                error={form.errors.company_name}
            >
                <Input
                    id="contact-company"
                    autoComplete="organization"
                    value={form.data.company_name}
                    onChange={(event) =>
                        form.setData('company_name', event.target.value)
                    }
                />
            </FormField>
            <FormField
                id="contact-message"
                label="Como podemos ajudar?"
                error={form.errors.message}
                className="sm:col-span-2"
            >
                <NativeTextarea
                    id="contact-message"
                    required
                    maxLength={2000}
                    value={form.data.message}
                    onChange={(event) =>
                        form.setData('message', event.target.value)
                    }
                />
            </FormField>
            <input
                type="text"
                name="website"
                tabIndex={-1}
                autoComplete="off"
                className="hidden"
                aria-hidden="true"
                value={form.data.website}
                onChange={(event) =>
                    form.setData('website', event.target.value)
                }
            />
            <div className="sm:col-span-2">
                <Button disabled={form.processing}>
                    {form.processing ? 'Enviando…' : 'Enviar mensagem'}
                </Button>
            </div>
        </form>
    );
}

export default function Welcome({
    applications,
    trialDays,
    contact,
    screenshots,
}: {
    applications: CatalogApplication[];
    trialDays: number;
    contact: CommercialContact;
    screenshots: { src: string; alt: string }[];
}) {
    const { auth } = usePage().props;
    const [selected, setSelected] = useState<string[]>([]);
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const resolved = useMemo(
        () => resolveDependencies(selected, applications),
        [selected, applications],
    );
    const nameOf = (identifier: string) =>
        applications.find((app) => app.identifier === identifier)?.name ??
        identifier;
    const toggle = (identifier: string) =>
        setSelected((current) =>
            current.includes(identifier)
                ? current.filter((value) => value !== identifier)
                : [...current, identifier],
        );
    const priced = resolved
        .map(
            (identifier) =>
                applications.find((app) => app.identifier === identifier)
                    ?.monthly_price,
        )
        .filter(
            (price): price is string => price !== null && price !== undefined,
        );
    const total = priced.reduce(
        (sum, price) => sum + Math.round(Number(price) * 100),
        0,
    );
    const [hero, ...gallery] = screenshots;
    const nav = [
        ['Aplicações', '#aplicacoes'],
        ['Como funciona', '#como-funciona'],
        ['Perguntas', '#perguntas'],
        ['Contato', '#contato'],
    ];

    return (
        <>
            <Head title="Gestão modular para pequenos negócios">
                <meta
                    name="description"
                    content="VetorFlex: escolha as aplicações de gestão que sua empresa precisa — vendas, caixa, estoque, financeiro, Pix e notas fiscais — e teste grátis."
                />
            </Head>
            <div className="min-h-screen bg-background text-foreground">
                <header className="sticky top-0 z-30 border-b bg-background/90 backdrop-blur">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
                        <Link
                            href="/"
                            className="flex items-center gap-2 font-semibold"
                        >
                            <span className="flex size-8 items-center justify-center rounded-md bg-brand text-brand-foreground">
                                <AppLogoIcon className="size-5 fill-current" />
                            </span>
                            VetorFlex
                        </Link>
                        <nav
                            aria-label="Seções"
                            className="hidden items-center gap-6 text-sm md:flex"
                        >
                            {nav.map(([label, href]) => (
                                <a
                                    key={href}
                                    href={href}
                                    className="text-muted-foreground hover:text-foreground"
                                >
                                    {label}
                                </a>
                            ))}
                        </nav>
                        <div className="hidden items-center gap-2 md:flex">
                            {auth.user ? (
                                <Button asChild>
                                    <Link href={dashboard()}>
                                        Ir para o painel
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button variant="ghost" asChild>
                                        <Link href={login()}>Entrar</Link>
                                    </Button>
                                    <Button
                                        asChild
                                        className="bg-brand text-brand-foreground hover:bg-brand/90"
                                    >
                                        <Link href={register()}>
                                            Teste grátis
                                        </Link>
                                    </Button>
                                </>
                            )}
                        </div>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="md:hidden"
                            aria-label={
                                isMenuOpen ? 'Fechar menu' : 'Abrir menu'
                            }
                            aria-expanded={isMenuOpen}
                            onClick={() => setIsMenuOpen((open) => !open)}
                        >
                            {isMenuOpen ? <X /> : <Menu />}
                        </Button>
                    </div>
                    {isMenuOpen && (
                        <nav
                            aria-label="Menu"
                            className="border-t px-4 py-3 md:hidden"
                        >
                            <ul className="grid gap-2 text-sm">
                                {nav.map(([label, href]) => (
                                    <li key={href}>
                                        <a
                                            href={href}
                                            className="block py-1"
                                            onClick={() => setIsMenuOpen(false)}
                                        >
                                            {label}
                                        </a>
                                    </li>
                                ))}
                                <li className="flex gap-2 pt-2">
                                    {auth.user ? (
                                        <Button asChild className="flex-1">
                                            <Link href={dashboard()}>
                                                Ir para o painel
                                            </Link>
                                        </Button>
                                    ) : (
                                        <>
                                            <Button
                                                variant="outline"
                                                asChild
                                                className="flex-1"
                                            >
                                                <Link href={login()}>
                                                    Entrar
                                                </Link>
                                            </Button>
                                            <Button
                                                asChild
                                                className="flex-1 bg-brand text-brand-foreground hover:bg-brand/90"
                                            >
                                                <Link href={register()}>
                                                    Teste grátis
                                                </Link>
                                            </Button>
                                        </>
                                    )}
                                </li>
                            </ul>
                        </nav>
                    )}
                </header>

                <main>
                    <section className="mx-auto grid max-w-6xl items-center gap-10 px-4 py-14 md:py-20 lg:grid-cols-[1.05fr_1fr]">
                        <div className="space-y-6">
                            <p className="inline-flex items-center gap-2 rounded-full bg-brand-soft px-3 py-1 text-sm font-medium text-brand">
                                <CalendarClock
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {trialDays} dias de teste grátis, sem cartão
                            </p>
                            <h1 className="text-4xl font-semibold tracking-tight text-balance md:text-5xl">
                                Sua empresa, suas aplicações, seu jeito de
                                trabalhar.
                            </h1>
                            <p className="max-w-xl text-lg text-muted-foreground">
                                Contrate apenas as ferramentas que seu negócio
                                precisa e amplie seu sistema conforme sua
                                empresa cresce. Gestão modular para pequenos
                                negócios e MEIs.
                            </p>
                            <div className="flex flex-wrap gap-3">
                                <Button
                                    size="lg"
                                    asChild
                                    className="bg-brand text-brand-foreground hover:bg-brand/90"
                                >
                                    <Link href={register()}>
                                        Começar teste grátis{' '}
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </Button>
                                <Button size="lg" variant="outline" asChild>
                                    <a href="#aplicacoes">Ver aplicações</a>
                                </Button>
                            </div>
                        </div>
                        {hero ? (
                            <figure className="overflow-hidden rounded-xl border bg-muted shadow-sm">
                                <img
                                    src={hero.src}
                                    alt={hero.alt}
                                    className="w-full"
                                    loading="eager"
                                />
                                <figcaption className="border-t px-3 py-2 text-xs text-muted-foreground">
                                    Interface real do VetorFlex com dados de
                                    demonstração.
                                </figcaption>
                            </figure>
                        ) : (
                            <div
                                className="grid gap-3 sm:grid-cols-2"
                                aria-hidden="true"
                            >
                                {applications.slice(0, 4).map((app) => (
                                    <div
                                        key={app.identifier}
                                        className="rounded-xl border p-4"
                                    >
                                        <ApplicationIcon
                                            name={app.icon}
                                            className="mb-2 size-6 text-brand"
                                        />
                                        <p className="font-medium">
                                            {app.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {app.tagline}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>

                    <section
                        aria-labelledby="beneficios"
                        className="border-y bg-muted/40"
                    >
                        <div className="mx-auto max-w-6xl px-4 py-14">
                            <h2
                                id="beneficios"
                                className="mb-8 text-2xl font-semibold"
                            >
                                Por que o VetorFlex
                            </h2>
                            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {BENEFITS.map((benefit) => (
                                    <li
                                        key={benefit.title}
                                        className="rounded-xl border bg-background p-5"
                                    >
                                        <Check
                                            className="mb-3 size-5 text-brand"
                                            aria-hidden="true"
                                        />
                                        <p className="font-medium">
                                            {benefit.title}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {benefit.text}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>

                    <section
                        id="aplicacoes"
                        aria-labelledby="titulo-aplicacoes"
                        className="mx-auto max-w-6xl scroll-mt-20 px-4 py-14"
                    >
                        <div className="mb-8 max-w-2xl space-y-2">
                            <h2
                                id="titulo-aplicacoes"
                                className="text-2xl font-semibold"
                            >
                                Cardápio de aplicações
                            </h2>
                            <p className="text-muted-foreground">
                                Marque as aplicações que interessam.
                                Dependências obrigatórias entram
                                automaticamente.
                            </p>
                        </div>
                        {applications.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-8 text-center text-muted-foreground">
                                As aplicações estão sendo preparadas para venda.
                                Fale com a nossa equipe pelo contato abaixo.
                            </p>
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                {applications.map((app) => {
                                    const isSelected = selected.includes(
                                        app.identifier,
                                    );
                                    const isRequired =
                                        !isSelected &&
                                        resolved.includes(app.identifier);

                                    return (
                                        <article
                                            key={app.identifier}
                                            className={`flex flex-col rounded-xl border p-5 transition-colors ${isSelected || isRequired ? 'border-brand bg-brand-soft/60' : ''}`}
                                        >
                                            <div className="mb-3 flex items-start justify-between gap-3">
                                                <span className="flex size-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                                    <ApplicationIcon
                                                        name={app.icon}
                                                        className="size-5"
                                                    />
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {app.category}
                                                </span>
                                            </div>
                                            <h3 className="font-semibold">
                                                {app.name}
                                            </h3>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {app.tagline ?? app.description}
                                            </p>
                                            <ul className="mt-3 space-y-1 text-sm">
                                                {app.benefits.map((benefit) => (
                                                    <li
                                                        key={benefit}
                                                        className="flex gap-2"
                                                    >
                                                        <Check
                                                            className="mt-0.5 size-4 shrink-0 text-brand"
                                                            aria-hidden="true"
                                                        />
                                                        {benefit}
                                                    </li>
                                                ))}
                                            </ul>
                                            {app.dependencies.length > 0 && (
                                                <p className="mt-3 text-xs text-muted-foreground">
                                                    Requer{' '}
                                                    {app.dependencies
                                                        .map(nameOf)
                                                        .join(', ')}
                                                    .
                                                </p>
                                            )}
                                            <div className="mt-auto flex items-center justify-between gap-2 pt-4">
                                                <span className="text-sm font-medium">
                                                    {app.monthly_price
                                                        ? `${formatMoney(app.monthly_price)}/mês`
                                                        : 'Valor sob consulta'}
                                                </span>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant={
                                                        isSelected
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    aria-pressed={isSelected}
                                                    disabled={isRequired}
                                                    onClick={() =>
                                                        toggle(app.identifier)
                                                    }
                                                >
                                                    {isRequired
                                                        ? 'Incluída (dependência)'
                                                        : isSelected
                                                          ? 'Selecionada'
                                                          : 'Selecionar'}
                                                </Button>
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        )}
                        {resolved.length > 0 && (
                            <div className="sticky bottom-4 mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-background p-4 shadow-lg">
                                <p className="text-sm">
                                    <strong>{resolved.length}</strong>{' '}
                                    aplicação(ões):{' '}
                                    {resolved.map(nameOf).join(', ')}
                                    {priced.length > 0 && (
                                        <span className="block text-muted-foreground">
                                            {formatMoney(
                                                (total / 100).toFixed(2),
                                            )}
                                            /mês
                                            {priced.length < resolved.length &&
                                                ' + itens sob consulta'}{' '}
                                            após o teste. O valor é confirmado
                                            no cadastro.
                                        </span>
                                    )}
                                </p>
                                <Button
                                    asChild
                                    className="bg-brand text-brand-foreground hover:bg-brand/90"
                                >
                                    <Link
                                        href={register({
                                            query: { apps: selected.join(',') },
                                        })}
                                    >
                                        Testar estas aplicações{' '}
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </section>

                    {gallery.length > 0 && (
                        <section
                            aria-labelledby="titulo-telas"
                            className="border-y bg-muted/40"
                        >
                            <div className="mx-auto max-w-6xl px-4 py-14">
                                <h2
                                    id="titulo-telas"
                                    className="mb-8 text-2xl font-semibold"
                                >
                                    Veja como é por dentro
                                </h2>
                                <div className="grid gap-4 md:grid-cols-3">
                                    {gallery.map((image) => (
                                        <figure
                                            key={image.src}
                                            className="overflow-hidden rounded-xl border bg-background"
                                        >
                                            <img
                                                src={image.src}
                                                alt={image.alt}
                                                className="w-full"
                                                loading="lazy"
                                            />
                                            <figcaption className="px-3 py-2 text-xs text-muted-foreground">
                                                {image.alt}
                                            </figcaption>
                                        </figure>
                                    ))}
                                </div>
                            </div>
                        </section>
                    )}

                    <section
                        id="como-funciona"
                        aria-labelledby="titulo-como"
                        className="mx-auto max-w-6xl scroll-mt-20 px-4 py-14"
                    >
                        <h2
                            id="titulo-como"
                            className="mb-8 text-2xl font-semibold"
                        >
                            Contratação modular, em três passos
                        </h2>
                        <ol className="grid gap-4 md:grid-cols-3">
                            {[
                                [
                                    Layers,
                                    'Escolha as aplicações',
                                    'Monte o sistema com o que sua empresa usa. As dependências são incluídas e mostradas para você.',
                                ],
                                [
                                    Sparkles,
                                    'Cadastre a empresa',
                                    'Informe seus dados e confirme o e-mail. O ambiente fica pronto na hora, só com o que você escolheu.',
                                ],
                                [
                                    LockKeyhole,
                                    `Teste por ${trialDays} dias`,
                                    'Use de verdade. Ao final, contrate para continuar registrando; seus dados ficam guardados.',
                                ],
                            ].map(([Icon, title, text], index) => {
                                const StepIcon = Icon as typeof Layers;

                                return (
                                    <li
                                        key={title as string}
                                        className="rounded-xl border p-5"
                                    >
                                        <span className="mb-3 flex size-9 items-center justify-center rounded-full bg-brand text-sm font-semibold text-brand-foreground">
                                            {index + 1}
                                        </span>
                                        <StepIcon
                                            className="mb-2 size-5 text-brand"
                                            aria-hidden="true"
                                        />
                                        <p className="font-medium">
                                            {title as string}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {text as string}
                                        </p>
                                    </li>
                                );
                            })}
                        </ol>
                        <div className="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-xl bg-brand p-6 text-brand-foreground">
                            <div>
                                <p className="text-xl font-semibold">
                                    {trialDays} dias para experimentar, sem
                                    compromisso.
                                </p>
                                <p className="text-sm opacity-90">
                                    Nenhuma cobrança automática. Você decide se
                                    contrata.
                                </p>
                            </div>
                            <Button size="lg" variant="secondary" asChild>
                                <Link href={register()}>Criar minha conta</Link>
                            </Button>
                        </div>
                    </section>

                    <section
                        id="perguntas"
                        aria-labelledby="titulo-perguntas"
                        className="border-t bg-muted/40"
                    >
                        <div className="mx-auto max-w-3xl scroll-mt-20 px-4 py-14">
                            <h2
                                id="titulo-perguntas"
                                className="mb-6 text-2xl font-semibold"
                            >
                                Perguntas frequentes
                            </h2>
                            <div className="divide-y rounded-xl border bg-background">
                                {FAQ.map((item) => (
                                    <details
                                        key={item.question}
                                        className="group p-4"
                                    >
                                        <summary className="cursor-pointer list-none font-medium marker:hidden">
                                            <span className="flex items-center justify-between gap-2">
                                                {item.question}
                                                <span
                                                    aria-hidden="true"
                                                    className="text-muted-foreground transition-transform group-open:rotate-45"
                                                >
                                                    +
                                                </span>
                                            </span>
                                        </summary>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {item.answer(trialDays)}
                                        </p>
                                    </details>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section
                        id="contato"
                        aria-labelledby="titulo-contato"
                        className="mx-auto grid max-w-6xl scroll-mt-20 gap-8 px-4 py-14 lg:grid-cols-[1fr_1.4fr]"
                    >
                        <div className="space-y-3">
                            <h2
                                id="titulo-contato"
                                className="text-2xl font-semibold"
                            >
                                Fale com a equipe comercial
                            </h2>
                            <p className="text-muted-foreground">
                                Tire dúvidas sobre as aplicações, valores e
                                implantação.
                            </p>
                            {contact.email && (
                                <p>
                                    E-mail:{' '}
                                    <a
                                        className="font-medium text-brand underline-offset-4 hover:underline"
                                        href={`mailto:${contact.email}`}
                                    >
                                        {contact.email}
                                    </a>
                                </p>
                            )}
                            {contact.whatsapp && (
                                <p>
                                    WhatsApp:{' '}
                                    <a
                                        className="font-medium text-brand underline-offset-4 hover:underline"
                                        href={`https://wa.me/${contact.whatsapp.replace(/\D/g, '')}`}
                                        rel="noopener noreferrer"
                                        target="_blank"
                                    >
                                        {contact.whatsapp}
                                    </a>
                                </p>
                            )}
                        </div>
                        <ContactForm applications={selected} />
                    </section>
                </main>

                <footer className="border-t">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-8 text-sm text-muted-foreground">
                        <p>
                            VetorFlex é um produto {contact.company}. ©{' '}
                            {new Date().getFullYear()}.
                        </p>
                        <nav
                            aria-label="Institucional"
                            className="flex flex-wrap gap-4"
                        >
                            <Link
                                href={privacy()}
                                className="hover:text-foreground"
                            >
                                Política de privacidade
                            </Link>
                            <Link
                                href={terms()}
                                className="hover:text-foreground"
                            >
                                Termos de uso
                            </Link>
                            <Link
                                href={login()}
                                className="hover:text-foreground"
                            >
                                Entrar
                            </Link>
                        </nav>
                    </div>
                </footer>
            </div>
        </>
    );
}
