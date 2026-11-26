import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useTenant } from '@/hooks/use-tenant';
import { index } from '@/routes/company/members';
import { update } from '@/routes/company/members/permissions';

type Member = {
    id: number;
    name: string;
    email: string;
    role: { value: string; label: string };
    is_active: boolean;
    is_self: boolean;
    permissions: string[] | 'all';
    can_manage: boolean;
};

type Module = {
    identifier: string;
    name: string;
    permissions: { key: string; label: string }[];
};

type HistoryRow = {
    id: number;
    user_name: string | null;
    actor_name: string | null;
    granted: string[];
    revoked: string[];
    occurred_at: string;
};

function MemberPermissions({
    member,
    modules,
    grantable,
}: {
    member: Member;
    modules: Module[];
    grantable: string[];
}) {
    const current = member.permissions === 'all' ? [] : member.permissions;
    const form = useForm({
        permissions: current.filter((key) => grantable.includes(key)),
    });
    const [isOpen, setIsOpen] = useState(false);

    if (!isOpen) {
        return (
            <Button size="sm" variant="outline" onClick={() => setIsOpen(true)}>
                Permissões
            </Button>
        );
    }

    return (
        <form
            className="mt-3 space-y-4 rounded-lg border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.put(update.url(member.id), {
                    preserveScroll: true,
                    onSuccess: () => setIsOpen(false),
                });
            }}
        >
            {modules.map((module) => (
                <fieldset key={module.identifier} className="space-y-2">
                    <legend className="text-sm font-medium">
                        {module.name}
                    </legend>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {module.permissions.map((permission) => {
                            const isGrantable = grantable.includes(
                                permission.key,
                            );
                            const isChecked = isGrantable
                                ? form.data.permissions.includes(permission.key)
                                : current.includes(permission.key);

                            return (
                                <label
                                    key={permission.key}
                                    className={`flex items-start gap-2 text-sm ${isGrantable ? '' : 'opacity-60'}`}
                                >
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={isChecked}
                                        disabled={!isGrantable}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'permissions',
                                                checked === true
                                                    ? [
                                                          ...form.data
                                                              .permissions,
                                                          permission.key,
                                                      ]
                                                    : form.data.permissions.filter(
                                                          (key) =>
                                                              key !==
                                                              permission.key,
                                                      ),
                                            )
                                        }
                                    />
                                    <span>
                                        {permission.label}
                                        {!isGrantable && (
                                            <span className="block text-xs text-muted-foreground">
                                                Você não possui esta permissão
                                                para concedê-la.
                                            </span>
                                        )}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </fieldset>
            ))}
            <InputError message={form.errors.permissions} />
            <div className="flex gap-2">
                <Button size="sm" disabled={form.processing}>
                    Salvar permissões
                </Button>
                <Button
                    size="sm"
                    type="button"
                    variant="ghost"
                    onClick={() => {
                        form.reset();
                        setIsOpen(false);
                    }}
                >
                    Cancelar
                </Button>
            </div>
        </form>
    );
}

/** Permissões individuais por módulo contratado. Proprietários têm acesso integral e não são editados aqui. */
export default function Members({
    members,
    modules,
    grantable,
    history,
}: {
    members: Member[];
    modules: Module[];
    grantable: string[];
    history: HistoryRow[];
}) {
    const { formatDateTime } = useTenant();
    const labels = Object.fromEntries(
        modules.flatMap((module) =>
            module.permissions.map((permission) => [
                permission.key,
                `${module.name}: ${permission.label}`,
            ]),
        ),
    );

    return (
        <>
            <Head title="Usuários e permissões" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Usuários e permissões"
                    description="Conceda ou revogue permissões dos módulos contratados. Ninguém concede o que não possui."
                />
                <ul className="divide-y rounded-lg border">
                    {members.map((member) => (
                        <li key={member.id} className="p-4">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p className="font-medium">
                                        {member.name}{' '}
                                        {member.is_self && (
                                            <span className="text-xs text-muted-foreground">
                                                (você)
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {member.email}
                                    </p>
                                    <span className="mt-1 inline-flex flex-wrap gap-1">
                                        <Badge variant="secondary">
                                            {member.role.label}
                                        </Badge>
                                        {!member.is_active && (
                                            <Badge variant="outline">
                                                Vínculo inativo
                                            </Badge>
                                        )}
                                        <Badge variant="outline">
                                            {member.permissions === 'all'
                                                ? 'Acesso integral'
                                                : `${member.permissions.length} permissão(ões)`}
                                        </Badge>
                                    </span>
                                </div>
                                {member.can_manage && (
                                    <MemberPermissions
                                        member={member}
                                        modules={modules}
                                        grantable={grantable}
                                    />
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
                <section
                    className="space-y-2"
                    aria-labelledby="history-heading"
                >
                    <h3 id="history-heading" className="text-sm font-medium">
                        Alterações recentes
                    </h3>
                    {history.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Nenhuma alteração registrada.
                        </p>
                    )}
                    <ol className="space-y-2 text-sm">
                        {history.map((row) => (
                            <li key={row.id} className="border-l-2 pl-3">
                                <span className="text-xs text-muted-foreground">
                                    {formatDateTime(row.occurred_at)} ·{' '}
                                    {row.actor_name} alterou {row.user_name}
                                </span>
                                {row.granted.length > 0 && (
                                    <span className="block">
                                        Concedidas:{' '}
                                        {row.granted
                                            .map((key) => labels[key] ?? key)
                                            .join(', ')}
                                    </span>
                                )}
                                {row.revoked.length > 0 && (
                                    <span className="block">
                                        Revogadas:{' '}
                                        {row.revoked
                                            .map((key) => labels[key] ?? key)
                                            .join(', ')}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ol>
                </section>
            </div>
        </>
    );
}

Members.layout = {
    breadcrumbs: [{ title: 'Usuários e permissões', href: index() }],
};
