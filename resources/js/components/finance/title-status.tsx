import { Badge } from '@/components/ui/badge';
import type { FinanceTitleSummary } from '@/types';

/** Situação do título; "Vencido" é derivado do vencimento e do saldo em aberto. */
export default function TitleStatus({
    title,
}: {
    title: Pick<FinanceTitleSummary, 'status' | 'status_label' | 'is_overdue'>;
}) {
    if (title.is_overdue) {
        return <Badge variant="destructive">Vencido</Badge>;
    }

    const variant =
        title.status === 'settled'
            ? 'default'
            : title.status === 'cancelled' || title.status === 'renegotiated'
              ? 'outline'
              : 'secondary';

    return <Badge variant={variant}>{title.status_label}</Badge>;
}
