<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchases\Enums\ReceiptStatus;
use App\Modules\Purchases\Models\PurchaseReceipt;
use App\Modules\Purchases\Models\PurchaseReceiptItem;
use App\Modules\Purchases\Models\PurchaseReturn;
use App\Modules\Purchases\Support\PurchaseMath;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Devolução comercial de mercadorias recebidas. A saída é valorizada pelo custo médio vigente, que não muda;
 * o valor histórico da aquisição é registrado à parte para a futura conciliação financeira.
 */
class PurchaseReturnService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private PurchaseOrderService $orders,
        private InventoryService $inventory,
        private SequenceService $sequences,
        private AuditService $audit,
    ) {}

    /**
     * Dados validados: `returned_at`, `reason`, `idempotency_key` e `items` (`purchase_receipt_item_id`, `quantity`).
     *
     * @param  array<string, mixed>  $data
     */
    public function register(PurchaseReceipt $receipt, User $user, array $data): PurchaseReturn
    {
        $items = array_map(fn (array $row): array => [(int) $row['purchase_receipt_item_id'], (string) BigDecimal::of($row['quantity'])->toScale(4)], $data['items']);
        sort($items);
        $hash = hash('sha256', (string) json_encode([$receipt->id, $data['returned_at'], $data['reason'], $items]));

        try {
            return DB::transaction(fn (): PurchaseReturn => $this->registerLocked($receipt, $user, $data, $hash), self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($receipt, $data['idempotency_key'], $hash, lock: true) ?? throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    private function registerLocked(PurchaseReceipt $receipt, User $user, array $data, string $hash): PurchaseReturn
    {
        $order = $this->orders->lock($receipt->order);
        $receipt = PurchaseReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
        $existing = $this->existing($receipt, $data['idempotency_key'], $hash, lock: false);

        if ($existing !== null) {
            return $existing;
        }

        if ($receipt->status !== ReceiptStatus::Confirmed) {
            throw ValidationException::withMessages(['items' => 'Recebimento estornado não aceita devolução.']);
        }

        $receiptItems = $receipt->items()->with('orderItem')->lockForUpdate()->get()->keyBy('id');
        $lines = [];

        foreach ($data['items'] as $index => $row) {
            $item = $receiptItems->get((int) $row['purchase_receipt_item_id']);
            $quantity = BigDecimal::of($row['quantity'])->toScale(4);

            if ($item === null) {
                throw ValidationException::withMessages(["items.{$index}.purchase_receipt_item_id" => 'O item não pertence a este recebimento.']);
            }

            if ($quantity->isZero()) {
                continue;
            }

            $available = BigDecimal::of($item->quantity)->minus($item->returned_quantity);

            if ($quantity->isGreaterThan($available)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "Quantidade de \"{$item->orderItem->description}\" maior que a disponível para devolução (".str_replace('.', ',', (string) $available->strippedOfTrailingZeros()).').']);
            }

            if (! $item->orderItem->allows_fraction && PurchaseMath::hasFraction((string) $quantity)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "A unidade {$item->orderItem->unit_code} não aceita quantidade fracionária."]);
            }

            $lines[] = [$item, (string) $quantity];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Informe a quantidade devolvida de ao menos um item.']);
        }

        usort($lines, fn (array $first, array $second): int => [$first[0]->product_variant_id, $first[0]->id] <=> [$second[0]->product_variant_id, $second[0]->id]);
        $return = PurchaseReturn::create([
            'company_id' => $order->company_id,
            'number' => $this->sequences->next($order->company, 'purchase_return'),
            'purchase_order_id' => $order->id,
            'purchase_receipt_id' => $receipt->id,
            'returned_at' => $data['returned_at'],
            'reason' => $data['reason'],
            'user_id' => $user->id,
            'user_name' => $user->name,
            'idempotency_key' => $data['idempotency_key'],
            'request_hash' => $hash,
        ]);
        $total = BigDecimal::zero();

        foreach ($lines as [$item, $quantity]) {
            $total = $total->plus($this->returnItem($return, $item, $quantity, $user));
        }

        $return->update(['total_value' => PurchaseMath::money($total)]);
        $this->audit->record('purchase.return_registered', $order->company, $user, $return, ['number' => $return->number]);

        return $return;
    }

    private function returnItem(PurchaseReturn $return, PurchaseReceiptItem $item, string $quantity, User $user): string
    {
        $returnedAfter = (string) BigDecimal::of($item->returned_quantity)->plus($quantity);
        $before = BigDecimal::of($item->returned_quantity)->isZero() ? '0' : PurchaseMath::cumulativeShare($item->acquisition_total, $item->quantity, '0', $item->returned_quantity, '0');
        $historical = PurchaseMath::cumulativeShare($item->acquisition_total, $item->quantity, $item->returned_quantity, $returnedAfter, $before);
        $returnItem = $return->items()->create([
            'company_id' => $return->company_id,
            'purchase_receipt_item_id' => $item->id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $quantity,
            'historical_value' => $historical,
        ]);

        if ($item->inventory_movement_id !== null) {
            $average = $this->inventory->averageCostOf($return->company_id, $item->product_variant_id);
            $movement = $this->inventory->recordExit(new StockOperation(
                companyId: $return->company_id,
                variantId: $item->product_variant_id,
                quantity: $quantity,
                origin: MovementOrigin::Purchase,
                user: $user,
                reason: "Devolução #{$return->number} ao fornecedor: {$return->reason}",
                referenceType: 'purchase_return',
                referenceId: "{$return->id}:{$returnItem->id}",
                idempotencyKey: "purchase-return-item:{$returnItem->id}",
                unitCost: $average === null ? null : (string) BigDecimal::of($average)->toScale(4, RoundingMode::HalfUp),
            ));
            $returnItem->update([
                'inventory_movement_id' => $movement->id,
                'stock_value' => $average === null ? null : PurchaseMath::money(BigDecimal::of($average)->multipliedBy($quantity)),
            ]);
        }

        $item->update(['returned_quantity' => $returnedAfter]);

        return $historical;
    }

    private function existing(PurchaseReceipt $receipt, string $key, string $hash, bool $lock): ?PurchaseReturn
    {
        $existing = PurchaseReturn::query()->where('company_id', $receipt->company_id)->where('idempotency_key', $key)
            ->when($lock, fn ($query) => $query->sharedLock())->first();

        if ($existing !== null && ! hash_equals($existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta requisição já foi usada em outra devolução. Recarregue a página.']);
        }

        return $existing;
    }
}
