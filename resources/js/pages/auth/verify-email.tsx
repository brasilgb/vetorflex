import { Form, Head, Link } from '@inertiajs/react';
import { MailCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({
    status,
    email,
}: {
    status?: string;
    email?: string | null;
}) {
    return (
        <>
            <Head title="Confirme seu e-mail" />

            <div className="space-y-3 rounded-lg border p-4 text-sm">
                <MailCheck className="size-6 text-brand" aria-hidden="true" />
                <p>
                    Enviamos um link de confirmação para{' '}
                    <strong>{email ?? 'o seu e-mail'}</strong>. Abra a mensagem
                    e clique no link: o ambiente da sua empresa é criado logo em
                    seguida, com as aplicações escolhidas.
                </p>
                <p className="text-muted-foreground">
                    Não encontrou? Confira a caixa de spam ou peça um novo
                    envio.
                </p>
            </div>

            {status === 'verification-link-sent' && (
                <p
                    role="status"
                    className="rounded-md bg-brand-soft p-3 text-sm text-brand"
                >
                    Um novo link de confirmação foi enviado.
                </p>
            )}

            <Form {...send.form()} className="grid gap-3">
                {({ processing }) => (
                    <Button
                        disabled={processing}
                        variant="outline"
                        className="w-full"
                    >
                        {processing && <Spinner />}
                        Reenviar e-mail de confirmação
                    </Button>
                )}
            </Form>

            <p className="text-center text-sm text-muted-foreground">
                E-mail errado?{' '}
                <Link
                    href={logout()}
                    method="post"
                    as="button"
                    className="underline underline-offset-4"
                >
                    Sair e cadastrar de novo
                </Link>
            </p>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Confirme seu e-mail',
    description: 'Falta pouco para acessar o VetorFlex.',
};
