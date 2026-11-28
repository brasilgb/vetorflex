import type { LucideIcon } from 'lucide-react';
import {
    FileText,
    LayoutGrid,
    Package,
    QrCode,
    Receipt,
    ShoppingCart,
    Store,
    UsersRound,
    Wallet,
    Warehouse,
} from 'lucide-react';

const ICONS: Record<string, LucideIcon> = {
    users: UsersRound,
    package: Package,
    'shopping-cart': ShoppingCart,
    receipt: Receipt,
    wallet: Wallet,
    store: Store,
    warehouse: Warehouse,
    'qr-code': QrCode,
    'file-text': FileText,
};

/** Ícone da aplicação a partir do nome configurado no catálogo. */
export default function ApplicationIcon({
    name,
    className,
}: {
    name: string | null;
    className?: string;
}) {
    const Icon = (name ? ICONS[name] : undefined) ?? LayoutGrid;

    return <Icon className={className} aria-hidden="true" />;
}
