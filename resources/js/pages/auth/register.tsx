import { Head, useForm, useHttp } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useMemo, useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import ApplicationIcon from '@/components/commercial/application-icon';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { login } from '@/routes';
import { privacy, terms } from '@/routes/legal';
import { quote as quoteRoute, store } from '@/routes/register';
import type { ApplicationQuote, CatalogApplication } from '@/types';

const STEPS = ['Aplicações', 'Revisão', 'Seus dados', 'Empresa'];

export default function Register({
    applications,
    preselected,
    trialDays,
    termsVersion,
    passwordRules,
}: {
    applications: CatalogApplication[];
    preselected: string[];
    trialDays: number;
    termsVersion: string;
    passwordRules: string;
}) {
    const [step, setStep] = useState(0);
    const idempotencyKey = useMemo(() => uuid(), []);
    const available = applications.map((app) => app.identifier);
    const form = useForm({
        modules: preselected.filter((identifier) =>
            available.includes(identifier),
        ),
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        company_name: '',
        company_document: '',
        accept_terms: false,
    });
    const http = useHttp<{ modules: string[] }, ApplicationQuote>({
        modules: [],
    });
    const [quote, setQuote] = useState<ApplicationQuote | null>(null);
    const nameOf = (identifier: string) =>
        applications.find((app) => app.identifier === identifier)?.name ??
        identifier;
    const priceOf = (identifier: string) =>
        applications.find((app) => app.identifier === identifier)
            ?.monthly_price ?? null;
    const errors = form.errors as Record<string, string | undefined>;
    const stepOfError = (): number => {
        if (errors.modules) {
            return 0;
        }

        if (errors.name || errors.email || errors.password) {
            return 2;
        }

        return 3;
    };

    const review = () => {
        http.transform(() => ({ modules: form.data.modules }));
        // Erros de validação ficam em `http.errors`; a rejeição da promessa não precisa de outro tratamento.
        http.post(quoteRoute.url(), {
            onSuccess: (response) => {
                setQuote(response);
                setStep(1);
            },
        }).catch(() => undefined);
    };

    if (applications.length === 0) {
        return (
            <>
                <Head title="Cadastro" />
                <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                    O cadastro estará disponível assim que as aplicações forem
                    liberadas para venda. Fale com a nossa equipe pela página
                    inicial.
                </p>
            </>
        );
    }

    return (
        <>
            <Head title="Teste grátis" />
            <ol
                className="grid grid-cols-4 gap-2 text-xs"
                aria-label="Etapas do cadastro"
            >
                {STEPS.map((label, index) => (
                    <li
                        key={label}
                        aria-current={index === step ? 'step' : undefined}
                        className={`border-t-4 pt-2 ${index <= step ? 'border-brand font-medium text-foreground' : 'border-muted text-muted-foreground'}`}
                    >
                        {index + 1}. {label}
                    </li>
                ))}
            </ol>

            {step === 0 && (
                <section
                    aria-label="Escolha as aplicações"
                    className="space-y-4"
                >
                    <p className="text-sm text-muted-foreground">
                        Escolha uma ou mais aplicações. Você poderá incluir
                        outras depois.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {applications.map((app) => {
                            const isChecked = form.data.modules.includes(
                                app.identifier,
                            );

                            return (
                                <label
                                    key={app.identifier}
                                    className={`flex cursor-pointer gap-3 rounded-lg border p-3 ${isChecked ? 'border-brand bg-brand-soft/60' : ''}`}
                                >
                                    <input
                                        type="checkbox"
                                        className="mt-1 size-4 accent-[var(--brand)]"
                                        checked={isChecked}
                                        onChange={(event) =>
                                            form.setData(
                                                'modules',
                                                event.target.checked
                                                    ? [
                                                          ...form.data.modules,
                                                          app.identifier,
                                                      ]
                                                    : form.data.modules.filter(
                                                          (value) =>
                                                              value !==
                                                              app.identifier,
                                                      ),
                                            )
                                        }
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2 font-medium">
                                            <ApplicationIcon
                                                name={app.icon}
                                                className="size-4 text-brand"
                                            />
                                            {app.name}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {app.tagline ?? app.description}
                                        </span>
                                        <span className="mt-1 block text-xs">
                                            {app.monthly_price
                                                ? `${formatMoney(app.monthly_price)}/mês após o teste`
                                                : 'Valor sob consulta'}
                                            {app.dependencies.length > 0 &&
                                                ` · Requer ${app.dependencies.map(nameOf).join(', ')}`}
                                        </span>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                    <InputError
                        message={
                            errors.modules ??
                            (http.errors as Record<string, string | undefined>)
                                .modules
                        }
                    />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            Já tem conta?{' '}
                            <TextLink href={login()}>Entrar</TextLink>
                        </p>
                        <Button
                            type="button"
                            disabled={
                                form.data.modules.length === 0 ||
                                http.processing
                            }
                            onClick={review}
                        >
                            {http.processing && <Spinner />}
                            Revisar seleção
                        </Button>
                    </div>
                </section>
            )}

            {step === 1 && quote && (
                <section aria-label="Revisão" className="space-y-4">
                    <ul className="divide-y rounded-lg border text-sm">
                        {quote.modules.map((identifier) => (
                            <li
                                key={identifier}
                                className="flex items-center justify-between gap-3 p-3"
                            >
                                <span>
                                    <span className="font-medium">
                                        {nameOf(identifier)}
                                    </span>
                                    {quote.required.includes(identifier) && (
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            incluída por ser necessária
                                        </span>
                                    )}
                                </span>
                                <span>
                                    {priceOf(identifier)
                                        ? `${formatMoney(priceOf(identifier))}/mês`
                                        : 'Sob consulta'}
                                </span>
                            </li>
                        ))}
                        <li className="flex items-center justify-between gap-3 bg-muted/50 p-3 font-medium">
                            <span>Após o teste</span>
                            <span>
                                {quote.total
                                    ? `${formatMoney(quote.total)}/mês`
                                    : 'Sob consulta'}
                                {quote.total &&
                                    quote.unpriced.length > 0 &&
                                    ' + itens sob consulta'}
                            </span>
                        </li>
                    </ul>
                    <p className="flex items-start gap-2 rounded-lg bg-brand-soft p-3 text-sm text-brand">
                        <Check
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        Os primeiros {trialDays} dias são grátis. Nenhuma
                        cobrança é feita automaticamente.
                    </p>
                    <div className="flex justify-between gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStep(0)}
                        >
                            Alterar aplicações
                        </Button>
                        <Button type="button" onClick={() => setStep(2)}>
                            Continuar
                        </Button>
                    </div>
                </section>
            )}

            {step >= 2 && (
                <form
                    className="space-y-5"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (step === 2) {
                            setStep(3);

                            return;
                        }

                        form.transform((data) => ({
                            ...data,
                            idempotency_key: idempotencyKey,
                        }));
                        form.post(store.url(), {
                            preserveScroll: true,
                            onError: () => setStep(stepOfError()),
                        });
                    }}
                >
                    {step === 2 ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                id="name"
                                label="Seu nome"
                                error={errors.name}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="name"
                                    required
                                    autoFocus
                                    autoComplete="name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="email"
                                label="E-mail"
                                hint="Usado para entrar e para confirmar o cadastro."
                                error={errors.email}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    autoComplete="email"
                                    value={form.data.email}
                                    onChange={(event) =>
                                        form.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                id="password"
                                label="Senha"
                                error={errors.password}
                            >
                                <PasswordInput
                                    id="password"
                                    required
                                    autoComplete="new-password"
                                    passwordrules={passwordRules}
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData(
                                            'password',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                id="password_confirmation"
                                label="Confirme a senha"
                                error={errors.password_confirmation}
                            >
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    autoComplete="new-password"
                                    passwordrules={passwordRules}
                                    value={form.data.password_confirmation}
                                    onChange={(event) =>
                                        form.setData(
                                            'password_confirmation',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        </div>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                id="company_name"
                                label="Nome da empresa"
                                error={errors.company_name}
                            >
                                <Input
                                    id="company_name"
                                    required
                                    autoFocus
                                    autoComplete="organization"
                                    value={form.data.company_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'company_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                id="company_document"
                                label="CNPJ ou CPF (opcional)"
                                hint="MEI pode informar o CNPJ. Pode ser preenchido depois."
                                error={errors.company_document}
                            >
                                <Input
                                    id="company_document"
                                    inputMode="numeric"
                                    value={form.data.company_document}
                                    onChange={(event) =>
                                        form.setData(
                                            'company_document',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <div className="sm:col-span-2">
                                <CheckboxField
                                    id="accept_terms"
                                    label={`Li e aceito os termos de uso e a política de privacidade (versão ${termsVersion}).`}
                                    checked={form.data.accept_terms}
                                    error={errors.accept_terms}
                                    onChange={(checked) =>
                                        form.setData('accept_terms', checked)
                                    }
                                />
                                <p className="mt-1 pl-6 text-xs text-muted-foreground">
                                    <a
                                        href={terms.url()}
                                        target="_blank"
                                        rel="noopener"
                                        className="underline"
                                    >
                                        Termos de uso
                                    </a>{' '}
                                    ·{' '}
                                    <a
                                        href={privacy.url()}
                                        target="_blank"
                                        rel="noopener"
                                        className="underline"
                                    >
                                        Política de privacidade
                                    </a>
                                </p>
                            </div>
                        </div>
                    )}
                    <div className="flex justify-between gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStep(step - 1)}
                        >
                            Voltar
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="bg-brand text-brand-foreground hover:bg-brand/90"
                        >
                            {form.processing && <Spinner />}
                            {step === 2
                                ? 'Continuar'
                                : form.processing
                                  ? 'Criando sua conta…'
                                  : 'Criar conta e começar o teste'}
                        </Button>
                    </div>
                </form>
            )}
        </>
    );
}

Register.layout = {
    title: 'Comece seu teste grátis',
    description: 'Monte seu sistema em poucos passos. Sem cartão de crédito.',
    wide: true,
};
