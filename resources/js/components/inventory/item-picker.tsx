import RemoteLookup from '@/components/remote-lookup';
import { formatDecimal } from '@/lib/decimal';
import { lookup } from '@/routes/inventory/items';
import type { StockItem } from '@/types';

/** Busca de mercadorias com controle de estoque; leitor de código de barras seleciona direto. */
export default function ItemPicker({
    id,
    warehouseId,
    onSelect,
}: {
    id: string;
    warehouseId?: number | null;
    onSelect: (item: StockItem) => void;
}) {
    return (
        <RemoteLookup<StockItem>
            id={id}
            placeholder="Nome, SKU ou código de barras"
            url={(term) =>
                lookup.url({
                    query: {
                        term,
                        ...(warehouseId ? { warehouse: warehouseId } : {}),
                    },
                })
            }
            render={(item) => (
                <span className="flex w-full justify-between gap-2">
                    <span>
                        {item.name}
                        <span className="block text-xs text-muted-foreground">
                            {item.sku ?? 'Sem SKU'}
                        </span>
                    </span>
                    <span className="text-xs whitespace-nowrap text-muted-foreground">
                        Saldo{' '}
                        {formatDecimal(item.warehouse_quantity ?? item.balance)}{' '}
                        {item.unit.code}
                    </span>
                </span>
            )}
            onSelect={onSelect}
        />
    );
}
