import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { home } from '@/routes/root-admin';
import { index } from '@/routes/root-admin/users';
import type { FinanceOption, Paginated } from '@/types';

type PlatformUser = {
    id: number;
    name: string;
    email: string;
    is_root_admin: boolean;
    email_verified: boolean;
    created_at: string | null;
    companies: { id: number; name: string; role: string; is_active: boolean }[];
};

const ROLES: Record<string, string> = {
    owner: 'Proprietário',
    administrator: 'Administrador',
    operator: 'Operador',
};

export default function PlatformUsers({
    users,
    filters,
    companies,
}: {
    users: Paginated<PlatformUser>;
    filters: { term: string | null; company: number | null };
    companies: FinanceOption[];
}) {
    const form = useForm({
        term: filters.term ?? '',
        company: filters.company === null ? '' : String(filters.company),
    });

    return (
        <>
            <Head title="Usuários" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Usuários da plataforma"
                    description="Consulta de usuários e vínculos com empresas. Papéis e permissões são definidos por cada empresa."
                />
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) =>
                            Object.fromEntries(
                                Object.entries(data).filter(
                                    ([, value]) => value !== '',
                                ),
                            ),
                        );
                        form.get(index.url(), { preserveScroll: true });
                    }}
                >
                    <Input
                        aria-label="Buscar por nome ou e-mail"
                        placeholder="Nome ou e-mail"
                        className="w-full sm:w-64"
                        value={form.data.term}
                        onChange={(event) =>
                            form.setData('term', event.target.value)
                        }
                    />
                    <NativeSelect
                        aria-label="Empresa"
                        className="w-52"
                        value={form.data.company}
                        onChange={(event) =>
                            form.setData('company', event.target.value)
                        }
                    >
                        <option value="">Todas as empresas</option>
                        {companies.map((company) => (
                            <option key={company.id} value={company.id}>
                                {company.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button variant="outline" disabled={form.processing}>
                        Filtrar
                    </Button>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Usuário</th>
                                <th className="p-3">Empresas</th>
                                <th className="p-3">Situação</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={3}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhum usuário encontrado.
                                    </td>
                                </tr>
                            )}
                            {users.data.map((user) => (
                                <tr
                                    key={user.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        {user.name}
                                        <span className="block text-xs text-muted-foreground">
                                            {user.email}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {user.companies.length === 0 ? (
                                            <span className="text-muted-foreground">
                                                Sem vínculo
                                            </span>
                                        ) : (
                                            <ul className="space-y-1">
                                                {user.companies.map(
                                                    (company) => (
                                                        <li key={company.id}>
                                                            {company.name}{' '}
                                                            <span className="text-xs text-muted-foreground">
                                                                {ROLES[
                                                                    company.role
                                                                ] ??
                                                                    company.role}
                                                                {!company.is_active &&
                                                                    ' · vínculo inativo'}
                                                            </span>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        <span className="flex flex-wrap gap-1">
                                            {user.is_root_admin && (
                                                <Badge>RootAdmin</Badge>
                                            )}
                                            <Badge
                                                variant={
                                                    user.email_verified
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {user.email_verified
                                                    ? 'E-mail verificado'
                                                    : 'E-mail não verificado'}
                                            </Badge>
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={users.links} />
            </div>
        </>
    );
}

PlatformUsers.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: home() },
        { title: 'Usuários', href: index() },
    ],
};
