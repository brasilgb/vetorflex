import { Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTenant } from '@/hooks/use-tenant';
import { home } from '@/routes/root-admin';
import { index, update } from '@/routes/root-admin/contacts';
import type { Paginated } from '@/types';

type Contact = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    company_name: string | null;
    message: string;
    modules: string[] | null;
    created_at: string | null;
    handled_at: string | null;
};

export default function Contacts({
    contacts,
}: {
    contacts: Paginated<Contact>;
}) {
    const { formatDateTime } = useTenant();

    return (
        <>
            <Head title="Contatos comerciais" />
            <section className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Contatos comerciais"
                    description="Mensagens enviadas pela página pública."
                />
                {contacts.data.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Nenhum contato recebido.
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {contacts.data.map((contact) => (
                            <li key={contact.id} className="space-y-2 p-4">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <p className="font-medium">
                                        {contact.name}{' '}
                                        <Badge
                                            variant={
                                                contact.handled_at
                                                    ? 'secondary'
                                                    : 'default'
                                            }
                                        >
                                            {contact.handled_at
                                                ? 'Atendido'
                                                : 'Novo'}
                                        </Badge>
                                        <span className="block text-sm font-normal text-muted-foreground">
                                            <a
                                                className="underline"
                                                href={`mailto:${contact.email}`}
                                            >
                                                {contact.email}
                                            </a>
                                            {contact.phone &&
                                                ` · ${contact.phone}`}
                                            {contact.company_name &&
                                                ` · ${contact.company_name}`}
                                            {contact.created_at &&
                                                ` · ${formatDateTime(contact.created_at)}`}
                                        </span>
                                    </p>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.patch(
                                                update.url(contact.id),
                                                {
                                                    handled:
                                                        contact.handled_at ===
                                                        null,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {contact.handled_at
                                            ? 'Reabrir'
                                            : 'Marcar como atendido'}
                                    </Button>
                                </div>
                                <p className="text-sm whitespace-pre-line">
                                    {contact.message}
                                </p>
                                {(contact.modules ?? []).length > 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        Aplicações de interesse:{' '}
                                        {(contact.modules ?? []).join(', ')}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination links={contacts.links} />
            </section>
        </>
    );
}

Contacts.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: home() },
        { title: 'Contatos comerciais', href: index() },
    ],
};
