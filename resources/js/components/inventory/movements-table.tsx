import ReasonDialog from '@/components/reason-dialog';
import { useTenant } from '@/hooks/use-tenant';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import { reverse } from '@/routes/inventory/movements';
import type { StockMovement } from '@/types';

/** Histórico de movimentações; lançamentos manuais ainda não estornados podem ser estornados com motivo. */
export default function MovementsTable({
    movements,
    canAdjust,
    showItem = true,
}: {
    movements: StockMovement[];
    canAdjust: boolean;
    showItem?: boolean;
}) {
    const { formatDateTime } = useTenant();
    const hasCost = movements.some((movement) => movement.unit_cost !== null);

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="p-3">Data</th>
                        {showItem && <th className="p-3">Mercadoria</th>}
                        <th className="p-3">Tipo e origem</th>
                        <th className="p-3">Depósito</th>
                        <th className="p-3 text-right">Quantidade</th>
                        <th className="p-3 text-right">Saldo após</th>
                        {hasCost && (
                            <th className="p-3 text-right">Custo un.</th>
                        )}
                        <th className="p-3">Responsável e justificativa</th>
                        {canAdjust && <th className="p-3" />}
                    </tr>
                </thead>
                <tbody>
                    {movements.length === 0 && (
                        <tr>
                            <td
                                colSpan={9}
                                className="p-6 text-center text-muted-foreground"
                            >
                                Nenhuma movimentação encontrada.
                            </td>
                        </tr>
                    )}
                    {movements.map((movement) => (
                        <tr key={movement.id} className="border-t align-top">
                            <td className="p-3 whitespace-nowrap">
                                {formatDateTime(movement.occurred_at)}
                            </td>
                            {showItem && (
                                <td className="p-3">
                                    {movement.item}
                                    <span className="block text-xs text-muted-foreground">
                                        {movement.sku ?? ''}
                                    </span>
                                </td>
                            )}
                            <td className="p-3">
                                {movement.type_label}
                                <span className="block text-xs text-muted-foreground">
                                    {movement.origin_label}
                                    {movement.is_reversed ? ' · estornada' : ''}
                                </span>
                            </td>
                            <td className="p-3">
                                {movement.warehouse ?? '—'}
                                {movement.warehouse_balance_after !== null && (
                                    <span className="block text-xs text-muted-foreground">
                                        Saldo no depósito:{' '}
                                        {formatDecimal(
                                            movement.warehouse_balance_after,
                                        )}
                                    </span>
                                )}
                            </td>
                            <td
                                className={`p-3 text-right whitespace-nowrap ${movement.quantity.startsWith('-') ? 'text-destructive' : ''}`}
                            >
                                {formatDecimal(movement.quantity)}{' '}
                                {movement.unit}
                            </td>
                            <td className="p-3 text-right whitespace-nowrap">
                                {formatDecimal(movement.balance_after)}
                            </td>
                            {hasCost && (
                                <td className="p-3 text-right whitespace-nowrap">
                                    {movement.unit_cost === null
                                        ? '—'
                                        : formatMoney(movement.unit_cost)}
                                </td>
                            )}
                            <td className="p-3">
                                {movement.user_name ?? 'Sistema'}
                                {movement.reason && (
                                    <span className="block text-xs text-muted-foreground">
                                        {movement.reason}
                                    </span>
                                )}
                            </td>
                            {canAdjust && (
                                <td className="p-3 text-right">
                                    {movement.is_reversible && (
                                        <ReasonDialog
                                            url={reverse.url(movement.id)}
                                            title="Estornar movimentação"
                                            description="O estorno cria uma movimentação inversa; o registro original é preservado."
                                            confirmLabel="Estornar"
                                            size="sm"
                                        >
                                            Estornar
                                        </ReasonDialog>
                                    )}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
