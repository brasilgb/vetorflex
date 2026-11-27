import { Link } from '@inertiajs/react';
import { FiscalStatusBadge } from '@/components/fiscal/fiscal-status';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/decimal';
import { create, show } from '@/routes/fiscal/documents';
import type { FiscalDocument } from '@/types';

export type OriginFiscalDocuments = {
    origin: 'sale' | 'pos' | 'finance';
    origin_id: number;
    can_create: boolean;
    documents: FiscalDocument[];
} | null;

const ACTIVE = [
    'draft',
    'queued',
    'processing',
    'authorized',
    'rejected',
    'cancel_requested',
    'communication_failure',
];

/**
 * Documentos fiscais de uma venda, atendimento do PDV ou conta a receber, exibidos somente com o módulo contratado.
 * Emitir o documento não altera a origem: não gera recebimento, baixa, estoque nem caixa.
 */
export default function OriginFiscalDocumentsSection({
    data,
    canCreate = true,
}: {
    data: OriginFiscalDocuments;
    canCreate?: boolean;
}) {
    if (data === null) {
        return null;
    }

    const hasActive = data.documents.some((document) =>
        ACTIVE.includes(document.status),
    );

    return (
        <section aria-label="Documentos fiscais" className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-medium">Documentos fiscais</h3>
                {data.can_create && canCreate && !hasActive && (
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={create({
                                query: {
                                    origin: data.origin,
                                    origin_id: data.origin_id,
                                },
                            })}
                        >
                            Preparar documento fiscal
                        </Link>
                    </Button>
                )}
            </div>
            {data.documents.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nenhum documento fiscal.
                    {data.origin === 'pos' &&
                        ' O comprovante do PDV não tem valor fiscal.'}
                </p>
            ) : (
                <ul className="divide-y rounded-lg border text-sm">
                    {data.documents.map((document) => (
                        <li
                            key={document.id}
                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                        >
                            <Link
                                href={show(document.id)}
                                className="underline-offset-4 hover:underline"
                            >
                                {document.type_label}{' '}
                                {document.number
                                    ? `nº ${document.number}`
                                    : `interno ${document.local_number}`}{' '}
                                · {formatMoney(document.total)}
                            </Link>
                            <FiscalStatusBadge
                                status={document.status}
                                label={document.status_label}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
