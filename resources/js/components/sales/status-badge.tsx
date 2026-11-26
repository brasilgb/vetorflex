import { Badge } from '@/components/ui/badge';
import type { Option } from '@/types';

const VARIANTS: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    draft: 'outline',
    issued: 'secondary',
    approved: 'default',
    converted: 'default',
    rejected: 'destructive',
    expired: 'destructive',
    confirmed: 'secondary',
    partially_fulfilled: 'secondary',
    fulfilled: 'default',
    completed: 'default',
    cancelled: 'destructive',
    reversed: 'destructive',
};

export default function SaleStatusBadge({
    status,
    isOverdue = false,
}: {
    status: Option;
    isOverdue?: boolean;
}) {
    return (
        <span className="inline-flex flex-wrap gap-1">
            <Badge variant={VARIANTS[status.value] ?? 'outline'}>
                {status.label}
            </Badge>
            {isOverdue && <Badge variant="destructive">Validade vencida</Badge>}
        </span>
    );
}
