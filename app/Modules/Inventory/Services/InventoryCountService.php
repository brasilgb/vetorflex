<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\CountStatus;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryCountItem;
use App\Modules\Inventory\Models\InventoryWarehouseBalance;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inventário (contagem física) de um depósito: criação com as mercadorias escolhidas (ou todas), registro das
 * contagens, comparação com o saldo, aprovação e encerramento. A aprovação compara cada contagem com o saldo do
 * depósito naquele momento (sob a trava de `InventoryService`) e gera um ajuste por divergência, com chave
 * determinística; aprovar de novo é recusado e nunca duplica ajustes. Itens sem contagem não são ajustados.
 */
class InventoryCountService
{
    public function __construct(
        private InventoryService $inventory,
        private WarehouseService $warehouses,
        private StockItems $items,
        private SequenceService $sequences,
        private AuditService $audit,
    ) {}

    /** @param list<int>|null $variantIds Sem seleção, inclui todas as mercadorias ativas com controle de estoque. */
    public function create(Company $company, User $user, int $warehouseId, ?array $variantIds, ?string $description): InventoryCount
    {
        $warehouse = $this->warehouses->resolve($company->id, $warehouseId);
        $query = $this->items->query($company)->where('product_variants.is_active', true)->whereHas('product', fn ($product) => $product->where('is_active', true));
        $ids = ($variantIds === null || $variantIds === [] ? $query : $query->whereIn('product_variants.id', $variantIds))->orderBy('product_variants.id')->pluck('product_variants.id')->all();

        if ($ids === []) {
            throw ValidationException::withMessages(['items' => 'Nenhuma mercadoria com controle de estoque para contar.']);
        }

        if ($variantIds !== null && $variantIds !== [] && count($ids) !== count(array_unique($variantIds))) {
            throw ValidationException::withMessages(['items' => 'Selecione mercadorias ativas desta empresa com controle de estoque.']);
        }

        return DB::transaction(function () use ($company, $user, $warehouse, $ids, $description): InventoryCount {
            $count = InventoryCount::create([
                'company_id' => $company->id,
                'number' => $this->sequences->next($company, 'inventory_count'),
                'inventory_warehouse_id' => $warehouse->id,
                'status' => CountStatus::Open,
                'description' => $description,
                'created_by' => $user->id,
                'created_by_name' => $user->name,
            ]);

            foreach ($ids as $id) {
                $count->items()->create(['company_id' => $company->id, 'product_variant_id' => $id]);
            }

            $this->audit->record('inventory.count_created', $company, $user, $count, ['number' => $count->number, 'warehouse' => $warehouse->name, 'items' => count($ids)]);

            return $count;
        });
    }

    /**
     * Registra (ou limpa, com vazio) as quantidades contadas.
     *
     * @param  array<int, string|null>  $quantities  Item do inventário => quantidade contada.
     */
    public function record(InventoryCount $count, User $user, array $quantities): InventoryCount
    {
        return DB::transaction(function () use ($count, $user, $quantities): InventoryCount {
            $count = $this->lockOpen($count);
            $items = $count->items()->with('variant.product.unit')->get()->keyBy('id');

            foreach ($quantities as $itemId => $value) {
                $item = $items->get((int) $itemId) ?? throw ValidationException::withMessages(['counts' => 'Item não pertence a este inventário.']);
                $item->update(['counted_quantity' => $value === null || trim($value) === '' ? null : $this->quantity($item, $value)]);
            }

            $this->audit->record('inventory.count_recorded', Company::query()->findOrFail($count->company_id), $user, $count, ['items' => count($quantities)]);

            return $count;
        });
    }

    public function approve(InventoryCount $count, User $user): InventoryCount
    {
        $company = Company::query()->findOrFail($count->company_id);

        return DB::transaction(function () use ($company, $count, $user): InventoryCount {
            $count = $this->lockOpen($count);
            $items = $count->items()->whereNotNull('counted_quantity')->orderBy('product_variant_id')->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['counts' => 'Registre ao menos uma contagem antes de aprovar.']);
            }

            foreach ($items as $item) {
                $this->adjust($count, $item, $user);
            }

            $count->update(['status' => CountStatus::Approved, 'approved_by' => $user->id, 'approved_by_name' => $user->name, 'approved_at' => now()]);
            $this->audit->record('inventory.count_approved', $company, $user, $count, ['number' => $count->number, 'adjusted' => $items->whereNotNull('inventory_movement_id')->count()]);

            return $count;
        });
    }

    public function cancel(InventoryCount $count, User $user, string $reason): InventoryCount
    {
        return DB::transaction(function () use ($count, $user, $reason): InventoryCount {
            $count = $this->lockOpen($count);
            $count->update(['status' => CountStatus::Cancelled, 'cancelled_by' => $user->id, 'cancelled_by_name' => $user->name, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit->record('inventory.count_cancelled', Company::query()->findOrFail($count->company_id), $user, $count, ['number' => $count->number, 'reason' => $reason]);

            return $count;
        });
    }

    /**
     * Saldo atual do depósito para cada item, para comparar com a contagem antes da aprovação.
     *
     * @return array<int, string> Unidade comercializável => quantidade.
     */
    public function systemQuantities(InventoryCount $count): array
    {
        return InventoryWarehouseBalance::query()->where('inventory_warehouse_id', $count->inventory_warehouse_id)
            ->whereIn('product_variant_id', $count->items()->select('product_variant_id'))->pluck('quantity', 'product_variant_id')->all();
    }

    private function adjust(InventoryCount $count, InventoryCountItem $item, User $user): void
    {
        $counted = (string) $item->counted_quantity;
        $current = (string) (InventoryWarehouseBalance::query()->where('inventory_warehouse_id', $count->inventory_warehouse_id)->where('product_variant_id', $item->product_variant_id)->value('quantity') ?? '0');

        if (BigDecimal::of($counted)->isEqualTo($current)) {
            $item->update(['system_quantity' => $current, 'difference' => '0']);

            return;
        }

        $movement = $this->inventory->adjustTo(new StockOperation(
            companyId: $count->company_id,
            variantId: $item->product_variant_id,
            quantity: $counted,
            origin: MovementOrigin::Count,
            user: $user,
            reason: "Inventário #{$count->number}".($count->description ? ": {$count->description}" : ''),
            referenceType: 'inventory_count',
            referenceId: (string) $count->id,
            idempotencyKey: "inventory-count-item:{$item->id}",
            warehouseId: $count->inventory_warehouse_id,
        ));
        $difference = BigDecimal::of($movement->quantity);
        $item->update(['system_quantity' => (string) BigDecimal::of($counted)->minus($difference), 'difference' => (string) $difference, 'inventory_movement_id' => $movement->id]);
    }

    private function lockOpen(InventoryCount $count): InventoryCount
    {
        $count = InventoryCount::query()->lockForUpdate()->findOrFail($count->id);

        if ($count->status !== CountStatus::Open) {
            throw ValidationException::withMessages(['count' => "Este inventário está {$count->status->label()} e não aceita alterações."]);
        }

        return $count;
    }

    private function quantity(InventoryCountItem $item, string $value): string
    {
        try {
            $quantity = BigDecimal::of(str_replace(',', '.', trim($value)))->toScale(4);
        } catch (MathException) {
            throw ValidationException::withMessages(["counts.{$item->id}" => 'Informe uma quantidade válida.']);
        }

        if ($quantity->isNegative()) {
            throw ValidationException::withMessages(["counts.{$item->id}" => 'A quantidade contada não pode ser negativa.']);
        }

        if (! $item->variant->product->unit->allows_fraction && $quantity->strippedOfTrailingZeros()->getScale() > 0) {
            throw ValidationException::withMessages(["counts.{$item->id}" => "A unidade {$item->variant->product->unit->code} não aceita fração."]);
        }

        return (string) $quantity;
    }
}
