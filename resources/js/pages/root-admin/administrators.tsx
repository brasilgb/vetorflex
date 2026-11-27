import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
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
import { useTenant } from '@/hooks/use-tenant';
import { home } from '@/routes/root-admin';
import { destroy, index } from '@/routes/root-admin/administrators';

type Administrator = {
    id: number;
    name: string;
    email: string;
    created_at: string | null;
    is_current: boolean;
};

function RevokeDialog({ administrator }: { administrator: Administrator }) {
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({ reason: '' });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                form.reset();
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Revogar acesso
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.delete(destroy.url(administrator.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>
                        Revogar acesso de {administrator.name}?
                    </DialogTitle>
                    <DialogDescription>
                        {administrator.is_current
                            ? 'Você perderá o acesso ao painel administrativo imediatamente.'
                            : 'O usuário continua existindo e mantém os vínculos com empresas, mas perde o acesso ao painel.'}{' '}
                        O último administrador ativo não pode ser revogado.
                    </DialogDescription>
                    <FormField
                        id={`revoke-reason-${administrator.id}`}
                        label="Motivo"
                        error={
                            form.errors.reason ??
                            (form.errors as Record<string, string>)
                                .administrator
                        }
                    >
                        <NativeTextarea
                            id={`revoke-reason-${administrator.id}`}
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
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Revogar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function PlatformAdministrators({
    administrators,
}: {
    administrators: Administrator[];
}) {
    const { errors } = usePage().props;
    const { formatDateTime } = useTenant();

    return (
        <>
            <Head title="Administradores" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Administradores da plataforma"
                    description="Novos administradores são cadastrados no servidor, sem cadastro público."
                />
                <p className="rounded-lg border p-4 text-sm">
                    Para cadastrar um administrador, execute no servidor:{' '}
                    <code className="rounded bg-muted px-1.5 py-0.5">
                        php artisan rootadmin:create
                    </code>
                    . O comando solicita nome, e-mail e senha, e não eleva
                    usuários já existentes.
                </p>
                <InputError message={errors.administrator} />
                <ul className="divide-y rounded-lg border">
                    {administrators.map((administrator) => (
                        <li
                            key={administrator.id}
                            className="flex flex-wrap items-center justify-between gap-3 p-4"
                        >
                            <span>
                                <span className="font-medium">
                                    {administrator.name}
                                </span>{' '}
                                {administrator.is_current && (
                                    <Badge variant="secondary">Você</Badge>
                                )}
                                <span className="block text-sm text-muted-foreground">
                                    {administrator.email}
                                    {administrator.created_at &&
                                        ` · desde ${formatDateTime(administrator.created_at)}`}
                                </span>
                            </span>
                            {administrators.length > 1 && (
                                <RevokeDialog administrator={administrator} />
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

PlatformAdministrators.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: home() },
        { title: 'Administradores', href: index() },
    ],
};
