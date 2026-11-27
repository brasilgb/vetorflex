import { Head, Link, router } from '@inertiajs/react';
import ConfirmButton from '@/components/confirm-button';
import { fiscalBreadcrumb } from '@/components/fiscal/fiscal-nav';
import {
    FiscalEnvironmentBadge,
    FiscalStatusBadge,
} from '@/components/fiscal/fiscal-status';
import Heading from '@/components/heading';
import ReasonDialog from '@/components/reason-dialog';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { useTenant } from '@/hooks/use-tenant';
import { formatMoney } from '@/lib/decimal';
import {
    cancel,
    destroy,
    download,
    edit,
    index,
    issue,
    refresh,
} from '@/routes/fiscal/documents';
import type { FiscalDocumentDetail, FiscalDocumentEvent } from '@/types';

export default function FiscalDocumentShow({
    document,
    events,
    preview,
    abilities,
}: {
    document: FiscalDocumentDetail;
    events: FiscalDocumentEvent[];
    preview: { blockers: string[]; problems: string[]; ready: boolean } | null;
    abilities: {
        edit: boolean;
        delete: boolean;
        issue: boolean;
        refresh: boolean;
        cancel: boolean;
        download: boolean;
        xml: boolean;
        pdf: boolean;
    };
}) {
    const { formatDateTime } = useTenant();
    const [copied, copy] = useClipboard();
    const title = `${document.type_label} ${document.number ? `nº ${document.number}` : `interno ${document.local_number}`}`;
    const receiver = document.receiver ?? {};
    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    return (
        <>
            <Head title={title} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <Heading
                            variant="small"
                            title={title}
                            description={`${document.origin_label}${document.origin_reference ? ` — ${document.origin_reference}` : ''} · Interno ${document.local_number}`}
                        />
                        <div className="flex flex-wrap items-center gap-2">
                            <FiscalStatusBadge
                                status={document.status}
                                label={document.status_label}
                            />
                            <FiscalEnvironmentBadge
                                environment={document.environment}
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {abilities.edit && (
                            <Button variant="outline" asChild>
                                <Link href={edit(document.id)}>Editar</Link>
                            </Button>
                        )}
                        {abilities.issue && (
                            <Button
                                disabled={preview !== null && !preview.ready}
                                onClick={() => post(issue.url(document.id))}
                            >
                                {document.status === 'draft'
                                    ? 'Emitir documento'
                                    : 'Reenviar documento'}
                            </Button>
                        )}
                        {abilities.refresh && (
                            <Button
                                variant="outline"
                                onClick={() => post(refresh.url(document.id))}
                            >
                                Consultar situação
                            </Button>
                        )}
                        {abilities.download && abilities.xml && (
                            <Button variant="outline" asChild>
                                <a
                                    href={download.url({
                                        document: document.id,
                                        format: 'xml',
                                    })}
                                >
                                    Baixar XML
                                </a>
                            </Button>
                        )}
                        {abilities.download && abilities.pdf && (
                            <Button variant="outline" asChild>
                                <a
                                    href={download.url({
                                        document: document.id,
                                        format: 'pdf',
                                    })}
                                >
                                    Baixar PDF
                                </a>
                            </Button>
                        )}
                        {document.access_key && (
                            <Button
                                variant="outline"
                                onClick={() => copy(document.access_key ?? '')}
                            >
                                {copied === document.access_key
                                    ? 'Chave copiada'
                                    : 'Copiar chave de acesso'}
                            </Button>
                        )}
                        {abilities.cancel && (
                            <ReasonDialog
                                url={cancel.url(document.id)}
                                title="Cancelar documento fiscal"
                                description="O cancelamento é pedido à autoridade fiscal pelo provedor e depende do prazo legal. Justificativa entre 15 e 255 caracteres. A venda, o estoque e o financeiro não são alterados."
                                confirmLabel="Solicitar cancelamento"
                                variant="outline"
                            >
                                Cancelar documento
                            </ReasonDialog>
                        )}
                        {abilities.delete && (
                            <ConfirmButton
                                variant="ghost"
                                title="Excluir rascunho?"
                                description="O rascunho nunca foi transmitido e será excluído."
                                confirmLabel="Excluir"
                                route={destroy(document.id)}
                            >
                                Excluir rascunho
                            </ConfirmButton>
                        )}
                    </div>
                </div>

                {document.error_message && (
                    <p className="rounded-lg border border-destructive/40 p-3 text-sm">
                        {document.status === 'communication_failure'
                            ? 'Falha de comunicação: '
                            : 'Motivo informado: '}
                        {document.error_message}
                    </p>
                )}

                {preview &&
                    (preview.blockers.length > 0 ||
                        preview.problems.length > 0) && (
                        <section
                            aria-label="Pendências para emitir"
                            className="space-y-2 rounded-lg border border-amber-500/50 p-4 text-sm"
                        >
                            <h3 className="font-medium">
                                Pendências para emitir
                            </h3>
                            <ul className="list-disc space-y-1 pl-4">
                                {[...preview.blockers, ...preview.problems].map(
                                    (problem) => (
                                        <li key={problem}>{problem}</li>
                                    ),
                                )}
                            </ul>
                        </section>
                    )}
                {preview?.ready && (
                    <p className="rounded-lg border p-3 text-sm text-emerald-700 dark:text-emerald-400">
                        Validação concluída: o documento pode ser emitido.
                    </p>
                )}

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {[
                        ['Valor dos itens', formatMoney(document.items_total)],
                        ['Descontos', formatMoney(document.discount_total)],
                        ['Total', formatMoney(document.total)],
                        [
                            document.type === 'nfse'
                                ? 'Tomador'
                                : 'Destinatário',
                            receiver.name
                                ? `${receiver.name}${receiver.document ? ` — ${receiver.document}` : ''}`
                                : 'Não identificado',
                        ],
                        [
                            'Número / série',
                            document.number
                                ? `${document.number} / ${document.series ?? '—'}`
                                : '—',
                        ],
                        ['Protocolo', document.authorization_protocol ?? '—'],
                        [
                            'Emitido em',
                            document.issued_at
                                ? formatDateTime(document.issued_at)
                                : '—',
                        ],
                        [
                            'Enviado em',
                            document.submitted_at
                                ? formatDateTime(document.submitted_at)
                                : '—',
                        ],
                        [
                            'Cancelado em',
                            document.cancelled_at
                                ? formatDateTime(document.cancelled_at)
                                : '—',
                        ],
                        ['Chave de acesso', document.access_key ?? '—'],
                        [
                            'Identificador de integração',
                            document.integration_id,
                        ],
                        ['Criado por', document.created_by_name ?? '—'],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-3">
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="font-medium break-all">{value}</dd>
                        </div>
                    ))}
                </dl>

                {document.cancel_reason && (
                    <p className="text-sm">
                        Justificativa do cancelamento: {document.cancel_reason}
                    </p>
                )}

                <section aria-label="Itens" className="space-y-2">
                    <h3 className="font-medium">Itens</h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Descrição</th>
                                    <th className="p-3">Dados fiscais</th>
                                    <th className="p-3 text-right">Qtd.</th>
                                    <th className="p-3 text-right">Unitário</th>
                                    <th className="p-3 text-right">Desconto</th>
                                </tr>
                            </thead>
                            <tbody>
                                {document.items.map((item, position) => (
                                    <tr
                                        key={position}
                                        className="border-t align-top"
                                    >
                                        <td className="p-3">
                                            {item.description}
                                        </td>
                                        <td className="p-3 text-xs text-muted-foreground">
                                            {document.type === 'nfse'
                                                ? `LC 116: ${item.service_code ?? document.tax_data?.service_code ?? 'padrão'}`
                                                : [
                                                      `NCM ${item.ncm ?? '—'}`,
                                                      `CFOP ${item.cfop ?? 'padrão'}`,
                                                      `Origem ${item.origin ?? 'padrão'}`,
                                                      `ICMS ${item.icms_situation ?? 'padrão'}`,
                                                  ].join(' · ')}
                                        </td>
                                        <td className="p-3 text-right">
                                            {item.quantity} {item.unit ?? ''}
                                        </td>
                                        <td className="p-3 text-right">
                                            {formatMoney(item.unit_price)}
                                        </td>
                                        <td className="p-3 text-right">
                                            {formatMoney(item.discount ?? '0')}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {document.description && (
                        <p className="text-sm">
                            Discriminação: {document.description}
                        </p>
                    )}
                    {document.notes && (
                        <p className="text-sm">
                            Informações complementares: {document.notes}
                        </p>
                    )}
                </section>

                {(document.xml_sha256 || document.pdf_sha256) && (
                    <p className="text-xs break-all text-muted-foreground">
                        Integridade (SHA-256) — XML:{' '}
                        {document.xml_sha256 ?? '—'} · PDF:{' '}
                        {document.pdf_sha256 ?? '—'}
                    </p>
                )}

                <section aria-label="Histórico" className="space-y-2">
                    <h3 className="font-medium">Histórico</h3>
                    <ol className="divide-y rounded-lg border text-sm">
                        {events.map((event) => (
                            <li key={event.id} className="p-3">
                                <span className="text-xs text-muted-foreground">
                                    {event.created_at
                                        ? formatDateTime(event.created_at)
                                        : ''}
                                    {event.user_name
                                        ? ` · ${event.user_name}`
                                        : ' · Sistema'}
                                </span>
                                <span className="block">
                                    {event.description}
                                </span>
                            </li>
                        ))}
                    </ol>
                </section>
            </div>
        </>
    );
}

FiscalDocumentShow.layout = {
    breadcrumbs: [
        fiscalBreadcrumb,
        { title: 'Documentos', href: index() },
        { title: 'Documento', href: '#' },
    ],
};
