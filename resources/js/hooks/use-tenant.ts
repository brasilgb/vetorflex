import { usePage } from '@inertiajs/react';

type Tenant = {
    activeCompany: { id: number; name: string; timezone: string } | null;
    companies: { id: number; name: string }[];
    modules: { identifier: string; name: string }[];
    permissions: string[];
};

export function useTenant() {
    const { tenant } = usePage<{ tenant: Tenant }>().props;

    return {
        ...tenant,
        can: (permission: string): boolean =>
            tenant.permissions.includes(permission),
        hasModule: (identifier: string): boolean =>
            tenant.modules.some((module) => module.identifier === identifier),
        formatDateTime: (value: string): string =>
            new Date(value).toLocaleString('pt-BR', {
                timeZone: tenant.activeCompany?.timezone,
                dateStyle: 'short',
                timeStyle: 'short',
            }),
    };
}
