import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Entrar" />

            {status && (
                <p
                    role="status"
                    className="rounded-md bg-brand-soft p-3 text-sm text-brand"
                >
                    {status}
                </p>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="email">E-mail</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    autoComplete="email"
                                    placeholder="voce@empresa.com.br"
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Senha</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                        >
                                            Esqueci minha senha
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="current-password"
                                    aria-invalid={
                                        errors.password ? true : undefined
                                    }
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox id="remember" name="remember" />
                                <Label htmlFor="remember">
                                    Manter conectado neste dispositivo
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {processing ? 'Entrando…' : 'Entrar'}
                            </Button>
                        </div>

                        <p className="text-center text-sm text-muted-foreground">
                            Ainda não tem conta?{' '}
                            <TextLink href={register()}>Teste grátis</TextLink>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Entrar no VetorFlex',
    description:
        'Use o e-mail e a senha cadastrados. Administradores da plataforma entram pela mesma tela.',
};
