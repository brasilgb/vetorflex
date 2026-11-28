import { Badge } from '@/components/ui/badge';

const VARIANTS: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    authorized: 'default',
    queued: 'secondary',
    processing: 'secondary',
    cancel_requested: 'secondary',
    draft: 'outline',
    rejected: 'destructive',
    denied: 'destructive',
    communication_failure: 'destructive',
    cancelled: 'outline',
};

/** Somente "Autorizado" aparece como documento válido; os estados pendentes nunca usam o destaque principal. */
export function FiscalStatusBadge({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    return <Badge variant={VARIANTS[status] ?? 'outline'}>{label}</Badge>;
}

/** Homologação: documento de teste, sem validade fiscal. */
export function FiscalEnvironmentBadge({
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
            Homologação (sem validade fiscal)
        </Badge>
    );
}
