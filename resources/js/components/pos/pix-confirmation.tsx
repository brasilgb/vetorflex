import { HttpNetworkError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { cancel, show, store } from '@/routes/pos/pix';

export type PixCharge = {
    id: number;
    status: string;
    status_label: string;
    amount: string;
    paid_amount: string;
    is_confirmed: boolean;
    expires_at: string | null;
    pix_copy_paste: string | null;
    qr_code_image: string | null;
    environment: string | null;
};

/** Intervalo de consulta da situação enquanto o caixa aguarda o pagamento. */
const POLL_MILLISECONDS = 3000;

/**
 * Pix dinâmico confirmado pelo provedor. Gerar o QR Code não confirma o pagamento: a linha só é aceita depois que a
 * consulta ao provedor (no servidor) indicar a cobrança paga. Expiração, falha e cancelamento liberam a linha para
 * nova tentativa ou para outra forma de pagamento.
 */
export default function PixConfirmation({
    sessionId,
    cartKey,
    amount,
    charge,
    onChange,
}: {
    sessionId: number;
    cartKey: string;
    amount: string;
    charge: PixCharge | null;
    onChange: (charge: PixCharge | null) => void;
}) {
    const http = useHttp<
        { amount: string; cart_key: string; idempotency_key: string },
        PixCharge
    >({ amount, cart_key: cartKey, idempotency_key: uuid() });
    const poll = useHttp<Record<string, never>, PixCharge>({});
    const [failure, setFailure] = useState<string>();
    const errors = http.errors as Record<string, string | undefined>;
    const isWaiting =
        charge !== null &&
        !charge.is_confirmed &&
        ['pending', 'processing', 'partially_paid'].includes(charge.status);

    useEffect(() => {
        if (!isWaiting || charge === null) {
            return;
        }

        const timer = window.setInterval(() => {
            poll.get(show.url({ session: sessionId, charge: charge.id }), {
                onSuccess: (updated) => onChange(updated),
            }).catch((error: unknown) => {
                if (error instanceof HttpNetworkError) {
                    setFailure(
                        'Sem conexão: a confirmação será verificada quando a conexão voltar.',
                    );
                }
            });
        }, POLL_MILLISECONDS);

        return () => window.clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isWaiting, charge?.id, sessionId]);

    if (
        charge === null ||
        ['cancelled', 'expired', 'failed'].includes(charge.status)
    ) {
        return (
            <div className="space-y-1 text-sm sm:col-span-3">
                {charge !== null && (
                    <p className="text-destructive">
                        Pix {charge.status_label.toLowerCase()}. Gere um novo
                        Pix ou use outra forma de pagamento.
                    </p>
                )}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={http.processing}
                    onClick={() => {
                        setFailure(undefined);
                        http.transform(() => ({
                            amount,
                            cart_key: cartKey,
                            idempotency_key: uuid(),
                        }));
                        http.post(store.url(sessionId), {
                            onSuccess: (created) => onChange(created),
                        }).catch((error: unknown) =>
                            setFailure(
                                error instanceof HttpNetworkError
                                    ? 'Sem conexão: o Pix não foi gerado.'
                                    : 'Não foi possível gerar o Pix.',
                            ),
                        );
                    }}
                >
                    Gerar Pix com confirmação automática
                </Button>
                {(failure || errors.pix || errors.amount) && (
                    <p className="text-destructive">
                        {failure ?? errors.pix ?? errors.amount}
                    </p>
                )}
            </div>
        );
    }

    if (charge.is_confirmed) {
        return (
            <p className="text-sm font-medium text-emerald-700 sm:col-span-3 dark:text-emerald-400">
                Pix de {formatMoney(charge.paid_amount)} confirmado pelo
                provedor.
            </p>
        );
    }

    return (
        <div className="space-y-2 rounded-md border p-3 text-center text-sm sm:col-span-3">
            {charge.environment === 'homologation' && (
                <p className="text-xs text-amber-700 dark:text-amber-400">
                    Ambiente de homologação: pagamento de teste.
                </p>
            )}
            {charge.qr_code_image && (
                <img
                    src={charge.qr_code_image}
                    alt="QR Code Pix"
                    className="mx-auto w-48 rounded bg-white p-2"
                />
            )}
            <code className="block max-h-16 overflow-y-auto rounded bg-muted p-2 text-left text-xs break-all">
                {charge.pix_copy_paste}
            </code>
            <p className="text-muted-foreground" aria-live="polite">
                Aguardando a confirmação do provedor… (
                {formatMoney(charge.amount)})
            </p>
            {failure && <p className="text-destructive">{failure}</p>}
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() =>
                    http
                        .post(
                            cancel.url({
                                session: sessionId,
                                charge: charge.id,
                            }),
                            { onSuccess: (updated) => onChange(updated) },
                        )
                        .catch(() =>
                            setFailure(
                                'Não foi possível cancelar o Pix. Ele pode ter sido pago: consulte antes de tentar de novo.',
                            ),
                        )
                }
            >
                Cancelar este Pix
            </Button>
        </div>
    );
}
