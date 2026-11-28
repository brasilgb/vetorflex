import { CalendarClock, LockKeyhole } from 'lucide-react';
import { useTenant } from '@/hooks/use-tenant';
import type { SubscriptionSummary } from '@/types';

/** Situação do período de teste ou da contratação, sempre calculada pelo servidor. */
export default function SubscriptionNotice({
    subscription,
}: {
    subscription: SubscriptionSummary;
}) {
    const { formatDateTime } = useTenant();

    if (subscription.status === 'active') {
        return null;
    }

    if (subscription.status === 'expired') {
        return (
            <p
                role="status"
                className="flex items-start gap-2 rounded-lg border border-amber-500/60 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
            >
                <LockKeyhole
                    className="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                O período de teste terminou. Seus dados estão preservados e
                podem ser consultados; para voltar a registrar operações, fale
                com a equipe comercial e contrate as aplicações.
            </p>
        );
    }

    return (
        <p
            role="status"
            className="flex items-start gap-2 rounded-lg bg-brand-soft p-3 text-sm text-brand"
        >
            <CalendarClock
                className="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            Período de teste:{' '}
            {subscription.days_left === 1
                ? 'resta 1 dia'
                : `restam ${subscription.days_left} dias`}
            {subscription.trial_ends_at &&
                ` (até ${formatDateTime(subscription.trial_ends_at)})`}
            . Nenhuma cobrança é feita automaticamente.
        </p>
    );
}
