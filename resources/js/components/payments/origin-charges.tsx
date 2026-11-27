import { Link } from '@inertiajs/react';
import { ChargeStatusBadge } from '@/components/payments/charge-status';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/decimal';
import { create, show } from '@/routes/payments/charges';
import type { PaymentCharge } from '@/types';

export type OriginCharges = {
    origin: 'sale' | 'finance';
    origin_id: number;
    can_create: boolean;
    charges: PaymentCharge[];
} | null;

/** Cobranças de uma venda ou conta a receber, exibidas somente com o módulo Pagamentos contratado. */
export default function OriginChargesSection({
    data,
    isOpen,
}: {
    data: OriginCharges;
    isOpen: boolean;
}) {
    if (data === null) {
        return null;
    }

    const hasOpenCharge = data.charges.some((charge) =>
        ['draft', 'pending', 'processing', 'overdue'].includes(charge.status),
    );

    return (
        <section aria-label="Cobranças" className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-medium">Cobranças Pix</h3>
                {data.can_create && isOpen && !hasOpenCharge && (
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={create({
                                query: {
                                    origin: data.origin,
                                    origin_id: data.origin_id,
                                },
                            })}
                        >
                            Gerar cobrança do saldo
                        </Link>
                    </Button>
                )}
            </div>
            {data.charges.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nenhuma cobrança gerada.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border text-sm">
                    {data.charges.map((charge) => (
                        <li
                            key={charge.id}
                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                        >
                            <Link
                                href={show(charge.id)}
                                className="underline-offset-4 hover:underline"
                            >
                                #{charge.number} · {formatMoney(charge.amount)}
                            </Link>
                            <span className="flex items-center gap-2">
                                {charge.paid_amount !== '0.00' && (
                                    <span className="text-xs text-muted-foreground">
                                        Recebido{' '}
                                        {formatMoney(charge.paid_amount)}
                                    </span>
                                )}
                                <ChargeStatusBadge
                                    status={charge.status}
                                    label={charge.status_label}
                                />
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
