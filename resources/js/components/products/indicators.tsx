import { formatMoney } from '@/lib/decimal';

/** Indicadores de estoque e de valor só são enviados a quem tem permissão. */
export type ProductIndicators = {
    active: number;
    inactive: number;
    controlled?: number;
    low?: number;
    out?: number;
    stock_value?: string;
    stock_without_cost?: number;
};

export default function Indicators({
    indicators,
}: {
    indicators: ProductIndicators;
}) {
    const cards: [string, number | undefined][] = [
        ['Ativos', indicators.active],
        ['Inativos', indicators.inactive],
        ['Com estoque controlado', indicators.controlled],
        ['Abaixo do mínimo', indicators.low],
        ['Sem estoque', indicators.out],
    ];

    return (
        <section aria-label="Indicadores" className="space-y-2">
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {cards.map(
                    ([label, value]) =>
                        value !== undefined && (
                            <div key={label} className="rounded-lg border p-3">
                                <dt className="text-xs text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="text-xl font-semibold">
                                    {value}
                                </dd>
                            </div>
                        ),
                )}
                {indicators.stock_value !== undefined && (
                    <div className="rounded-lg border p-3">
                        <dt className="text-xs text-muted-foreground">
                            Valor estimado do estoque
                        </dt>
                        <dd className="text-xl font-semibold">
                            {formatMoney(indicators.stock_value)}
                        </dd>
                    </div>
                )}
            </dl>
            {indicators.stock_value !== undefined && (
                <p className="text-xs text-muted-foreground">
                    Estimativa operacional pelo custo cadastrado; não é valor
                    contábil auditado.
                    {(indicators.stock_without_cost ?? 0) > 0 &&
                        ` ${indicators.stock_without_cost} item(ns) com saldo e sem custo não entram no cálculo.`}
                </p>
            )}
        </section>
    );
}
