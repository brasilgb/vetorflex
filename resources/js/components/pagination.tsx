import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

/** Os rótulos "anterior" e "próxima" do Laravel chegam com entidades HTML; aqui são trocados por texto. */
export default function Pagination({
    links,
}: {
    links: Paginated<unknown>['links'];
}) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav aria-label="Paginação" className="flex flex-wrap gap-1">
            {links.map((link, index) => {
                const label =
                    index === 0
                        ? 'Anterior'
                        : index === links.length - 1
                          ? 'Próxima'
                          : link.label;
                const className = cn(
                    'rounded-md px-3 py-1 text-sm',
                    link.active && 'bg-muted font-medium',
                );

                return link.url === null ? (
                    <span
                        key={index}
                        className={cn(className, 'text-muted-foreground')}
                    >
                        {label}
                    </span>
                ) : (
                    <Link
                        key={index}
                        href={link.url}
                        aria-current={link.active ? 'page' : undefined}
                        className={cn(className, 'hover:bg-muted')}
                        preserveScroll
                    >
                        {label}
                    </Link>
                );
            })}
        </nav>
    );
}
