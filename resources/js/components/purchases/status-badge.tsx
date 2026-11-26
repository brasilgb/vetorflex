import { Badge } from '@/components/ui/badge';
import type { Option } from '@/types';

const VARIANTS: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    draft: 'outline',
    confirmed: 'secondary',
    partially_received: 'secondary',
    received: 'default',
    cancelled: 'destructive',
    reversed: 'destructive',
};

export default function StatusBadge({
    status,
    isLate = false,
}: {
    status: Option;
    isLate?: boolean;
}) {
    return (
        <span className="inline-flex flex-wrap gap-1">
            <Badge variant={VARIANTS[status.value] ?? 'outline'}>
                {status.label}
            </Badge>
            {isLate && <Badge variant="destructive">Atrasado</Badge>}
        </span>
    );
}
