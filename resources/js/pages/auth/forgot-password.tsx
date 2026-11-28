import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="Recuperar senha" />

            {status && (
                <p
                    role="status"
                    className="rounded-md bg-brand-soft p-3 text-sm text-brand"
                >
                    {status}
                </p>
            )}

            <Form {...email.form()} className="grid gap-5">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">E-mail</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoComplete="email"
                                autoFocus
                                placeholder="voce@empresa.com.br"
                            />
                            <InputError message={errors.email} />
                        </div>
                        <Button
                            className="w-full"
                            disabled={processing}
                            data-test="email-password-reset-link-button"
                        >
                            {processing && <Spinner />}
                            Enviar link de redefinição
                        </Button>
                    </>
                )}
            </Form>

            <p className="text-center text-sm text-muted-foreground">
                Lembrou a senha?{' '}
                <TextLink href={login()}>Voltar para o login</TextLink>
            </p>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Recuperar senha',
    description:
        'Informe o e-mail da sua conta. Enviaremos um link para criar uma nova senha.',
};
