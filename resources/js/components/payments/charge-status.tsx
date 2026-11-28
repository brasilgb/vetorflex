import { Badge } from '@/components/ui/badge';

const VARIANTS: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    paid: 'default',
    partially_paid: 'secondary',
    pending: 'secondary',
    processing: 'secondary',
    draft: 'outline',
    overdue: 'destructive',
    failed: 'destructive',
    cancelled: 'outline',
    expired: 'outline',
    refunded: 'outline',
};

export function ChargeStatusBadge({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    return <Badge variant={VARIANTS[status] ?? 'outline'}>{label}</Badge>;
}

/** Indicação explícita de ambiente de homologação: cobranças de teste não movimentam dinheiro real. */
export function EnvironmentBadge({
    environment,
}: {
    environment: string | null;
}) {
    if (environment !== 'homologation') {
        return null;
    }

    return (
        <Badge
            variant="outline"
            className="border-amber-500 text-amber-700 dark:text-amber-400"
        >
            Homologação (teste)
        </Badge>
    );
}
