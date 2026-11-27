<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryWarehouse;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores e relatórios do Estoque, somente com dados reais. O valor estimado só aparece com permissão de custo e
 * conta apenas unidades com custo médio conhecido; as demais são informadas à parte, nunca estimadas.
 *
 * @phpstan-type Table array{type: string, title: string, columns: list<array{key: string, label: string, align?: 'right'}>, rows: list<array<string, string|null>>}
 * @phpstan-type Filters array{from: string, until: string, warehouse: int|null, days: int}
 */
class StockReports
{
    public const TYPES = [
        'balances' => 'Posição de estoque',
        'low_stock' => 'Abaixo do mínimo',
        'above_max' => 'Acima do máximo',
        'no_movement' => 'Sem movimentação',
        'movements' => 'Entradas e saídas por mercadoria',
        'by_warehouse' => 'Saldo por depósito',
        'valuation' => 'Valor estimado do estoque',
    ];

    public function __construct(private StockItems $items) {}

    /**
     * @param  Filters  $filters
     * @return array<string, mixed>
     */
    public function dashboard(Company $company, array $filters, bool $canViewCost): array
    {
        $items = $this->items->query($company);
        $total = (clone $items)->count();
        $withBalance = (clone $items)->whereRaw('coalesce(inventory_balances.quantity, 0) > 0')->count();
        [$from, $until] = $this->range($company, $filters);
        $movements = InventoryMovement::query()->where('company_id', $company->id)->whereBetween('occurred_at', [$from, $until]);

        return [
            'items' => $total,
            'with_balance' => $withBalance,
            'without_balance' => $total - $withBalance,
            'low_stock' => (clone $items)->where('product_variants.minimum_stock', '>', 0)->whereRaw('coalesce(inventory_balances.quantity, 0) < product_variants.minimum_stock')->count(),
            'entries' => (clone $movements)->where('quantity', '>', 0)->whereNotIn('type', [MovementType::TransferIn->value])->count(),
            'exits' => (clone $movements)->where('quantity', '<', 0)->whereNotIn('type', [MovementType::TransferOut->value])->count(),
            'no_movement' => $this->noMovementCount($company, $filters['days']),
            'value' => $canViewCost ? $this->valuation($company) : null,
            'by_warehouse' => $this->warehouseDistribution($company),
        ];
    }

    /**
     * @param  Filters  $filters
     * @return Table
     */
    public function build(Company $company, string $type, array $filters, bool $canViewCost): array
    {
        $type = $type === 'valuation' && ! $canViewCost ? 'balances' : $type;
        $quantity = fn (mixed $value): string => self::quantity((string) ($value ?? '0'));
        $itemRow = fn (ProductVariant $variant): array => [
            'item' => $variant->displayName(),
            'sku' => $variant->sku,
            'category' => $variant->product->category?->name,
            'unit' => $variant->product->unit->code,
            'balance' => $quantity($variant->getAttribute('balance')),
            'reserved' => $quantity($variant->getAttribute('reserved')),
            'minimum' => $quantity($variant->minimum_stock),
            'maximum' => $variant->maximum_stock === null ? null : $quantity($variant->maximum_stock),
        ];
        $itemColumns = [['key' => 'item', 'label' => 'Mercadoria'], ['key' => 'sku', 'label' => 'SKU'], ['key' => 'category', 'label' => 'Categoria'], ['key' => 'unit', 'label' => 'Un.'], ['key' => 'balance', 'label' => 'Saldo', 'align' => 'right'], ['key' => 'reserved', 'label' => 'Reservado', 'align' => 'right'], ['key' => 'minimum', 'label' => 'Mínimo', 'align' => 'right'], ['key' => 'maximum', 'label' => 'Máximo', 'align' => 'right']];
        $items = fn (?string $situation): array => $this->items->query($company, ['situation' => $situation, 'warehouse' => $filters['warehouse']])->orderBy('product_variants.id')->limit(2000)->get()
            ->map(fn (ProductVariant $variant): array => $filters['warehouse'] === null ? $itemRow($variant) : [...$itemRow($variant), 'balance' => $quantity($variant->getAttribute('warehouse_quantity'))])->values()->all();
        $table = match ($type) {
            'low_stock' => ['columns' => $itemColumns, 'rows' => $items('low')],
            'above_max' => ['columns' => $itemColumns, 'rows' => $items('above_max')],
            'no_movement' => ['columns' => [['key' => 'item', 'label' => 'Mercadoria'], ['key' => 'sku', 'label' => 'SKU'], ['key' => 'balance', 'label' => 'Saldo', 'align' => 'right'], ['key' => 'last', 'label' => 'Última movimentação']], 'rows' => $this->noMovementRows($company, $filters['days'])],
            'movements' => ['columns' => [['key' => 'item', 'label' => 'Mercadoria'], ['key' => 'sku', 'label' => 'SKU'], ['key' => 'entries', 'label' => 'Entradas', 'align' => 'right'], ['key' => 'exits', 'label' => 'Saídas', 'align' => 'right'], ['key' => 'net', 'label' => 'Variação', 'align' => 'right']], 'rows' => $this->movementRows($company, $filters)],
            'by_warehouse' => ['columns' => [['key' => 'warehouse', 'label' => 'Depósito'], ['key' => 'items', 'label' => 'Mercadorias com saldo', 'align' => 'right'], ['key' => 'quantity', 'label' => 'Quantidade total', 'align' => 'right']], 'rows' => array_map(fn (array $row): array => ['warehouse' => $row['name'], 'items' => (string) $row['items'], 'quantity' => $quantity($row['quantity'])], $this->warehouseDistribution($company))],
            'valuation' => ['columns' => [['key' => 'item', 'label' => 'Mercadoria'], ['key' => 'balance', 'label' => 'Saldo', 'align' => 'right'], ['key' => 'cost', 'label' => 'Custo médio', 'align' => 'right'], ['key' => 'value', 'label' => 'Valor estimado', 'align' => 'right']], 'rows' => $this->valuationRows($company)],
            default => ['columns' => $itemColumns, 'rows' => $items(null)],
        };

        return ['type' => $type, 'title' => self::TYPES[$type], 'columns' => $table['columns'], 'rows' => self::cells($table['rows'])];
    }

    /**
     * Linhas da tabela como texto (ou vazio), no formato da tela e do CSV.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, string|null>>
     */
    private static function cells(array $rows): array
    {
        return array_values(array_map(fn (array $row): array => array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $row), $rows));
    }

    /** @return array{value: string, items_with_cost: int, items_without_cost: int} */
    public function valuation(Company $company): array
    {
        $rows = DB::table('inventory_balances')->where('company_id', $company->id)->where('quantity', '>', 0)->get(['quantity', 'average_cost']);
        $value = $rows->whereNotNull('average_cost')->reduce(fn (BigDecimal $carry, object $row): BigDecimal => $carry->plus(BigDecimal::of((string) $row->quantity)->multipliedBy((string) $row->average_cost)), BigDecimal::zero());

        return ['value' => (string) $value->toScale(2, RoundingMode::HalfUp), 'items_with_cost' => $rows->whereNotNull('average_cost')->count(), 'items_without_cost' => $rows->whereNull('average_cost')->count()];
    }

    /** @return list<array{id: int, name: string, items: int, quantity: string}> */
    public function warehouseDistribution(Company $company): array
    {
        $totals = DB::table('inventory_warehouse_balances')->where('company_id', $company->id)->where('quantity', '!=', 0)->groupBy('inventory_warehouse_id')
            ->selectRaw('inventory_warehouse_id, count(*) as items, sum(quantity) as quantity')->get()->keyBy('inventory_warehouse_id');

        return array_values(InventoryWarehouse::query()->where('company_id', $company->id)->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (InventoryWarehouse $warehouse): array => ['id' => $warehouse->id, 'name' => $warehouse->name, 'items' => (int) ($totals[$warehouse->id]->items ?? 0), 'quantity' => (string) ($totals[$warehouse->id]->quantity ?? '0')])->all());
    }

    private function noMovementCount(Company $company, int $days): int
    {
        return count($this->noMovementRows($company, $days));
    }

    /** @return list<array<string, string|null>> Mercadorias ativas com saldo e sem movimentação no período. */
    private function noMovementRows(Company $company, int $days): array
    {
        $since = now()->subDays($days);
        $variants = $this->items->query($company, ['situation' => 'with_balance'])->where('product_variants.is_active', true)->orderBy('product_variants.id')->limit(2000)->get();
        $last = $this->items->lastMovementDates($company->id, array_values(array_map('intval', $variants->modelKeys())));

        return array_values($variants->filter(fn ($variant): bool => ! isset($last[$variant->id]) || now()->parse($last[$variant->id], 'UTC')->isBefore($since))
            ->map(fn ($variant): array => ['item' => $variant->displayName(), 'sku' => $variant->sku, 'balance' => self::quantity((string) $variant->getAttribute('balance')), 'last' => isset($last[$variant->id]) ? now()->parse($last[$variant->id], 'UTC')->setTimezone($company->timezone)->format('d/m/Y') : null])
            ->values()->all());
    }

    /**
     * @param  Filters  $filters
     * @return list<array<string, string|null>>
     */
    private function movementRows(Company $company, array $filters): array
    {
        [$from, $until] = $this->range($company, $filters);
        $rows = DB::table('inventory_movements')->join('product_variants', 'product_variants.id', '=', 'inventory_movements.product_variant_id')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('inventory_movements.company_id', $company->id)->whereBetween('inventory_movements.occurred_at', [$from, $until])
            ->when($filters['warehouse'], fn ($query, int $warehouse) => $query->where('inventory_movements.inventory_warehouse_id', $warehouse), fn ($query) => $query->whereNotIn('inventory_movements.type', [MovementType::TransferIn->value, MovementType::TransferOut->value]))
            ->groupBy('inventory_movements.product_variant_id', 'products.name', 'product_variants.label', 'product_variants.sku')
            ->selectRaw('products.name, product_variants.label, product_variants.sku, sum(case when inventory_movements.quantity > 0 then inventory_movements.quantity else 0 end) as entries, sum(case when inventory_movements.quantity < 0 then -inventory_movements.quantity else 0 end) as exits, sum(inventory_movements.quantity) as net')
            ->orderBy('products.name')->limit(2000)->get();

        return array_values($rows->map(fn (object $row): array => ['item' => $row->label === null ? (string) $row->name : "{$row->name} — {$row->label}", 'sku' => $row->sku, 'entries' => self::quantity((string) $row->entries), 'exits' => self::quantity((string) $row->exits), 'net' => self::quantity((string) $row->net)])->all());
    }

    /** @return list<array<string, string|null>> */
    private function valuationRows(Company $company): array
    {
        return array_values($this->items->query($company, ['situation' => 'with_balance'])->whereNotNull('inventory_balances.average_cost')->addSelect('inventory_balances.average_cost')->orderBy('product_variants.id')->limit(2000)->get()
            ->map(fn ($variant): array => [
                'item' => $variant->displayName(),
                'balance' => self::quantity((string) $variant->getAttribute('balance')),
                'cost' => (string) BigDecimal::of((string) $variant->getAttribute('average_cost'))->toScale(4, RoundingMode::HalfUp),
                'value' => (string) BigDecimal::of((string) $variant->getAttribute('balance'))->multipliedBy((string) $variant->getAttribute('average_cost'))->toScale(2, RoundingMode::HalfUp),
            ])->all());
    }

    /**
     * @param  Filters  $filters
     * @return array{string, string}
     */
    private function range(Company $company, array $filters): array
    {
        return [
            now($company->timezone)->parse($filters['from'], $company->timezone)->startOfDay()->utc()->format('Y-m-d H:i:s'),
            now($company->timezone)->parse($filters['until'], $company->timezone)->endOfDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** Quantidade como texto decimal sem zeros à direita; a formatação brasileira fica na tela e no CSV. */
    private static function quantity(string $value): string
    {
        return (string) BigDecimal::of($value)->strippedOfTrailingZeros();
    }
}
