<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Enums\ReceiptStatus;
use App\Modules\Purchases\Events\PurchaseReceiptReversed;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use App\Modules\Purchases\Models\PurchaseReceipt;
use App\Modules\Purchases\Models\PurchaseReceiptItem;
use App\Modules\Purchases\Support\PurchaseMath;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recebimento de mercadorias. Pedido, recebimento, estoque e custo médio são gravados numa única transação;
 * qualquer falha desfaz a operação inteira.
 *
 * Ordem de bloqueio: pedido → numeração → unidades comercializáveis em ordem crescente (InventoryService).
 */
class PurchaseReceiptService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private PurchaseOrderService $orders,
        private InventoryService $inventory,
        private SequenceService $sequences,
        private SupplierProductService $supplierProducts,
        private AuditService $audit,
    ) {}

    /**
     * Dados validados: `received_at`, `idempotency_key`, `items` (`purchase_order_item_id`, `quantity`, `divergence`), `document_reference` e `notes`.
     *
     * @param  array<string, mixed>  $data
     */
    public function receive(PurchaseOrder $order, User $user, array $data): PurchaseReceipt
    {
        $hash = $this->requestHash($order, $data);

        try {
            return DB::transaction(fn (): PurchaseReceipt => $this->receiveLocked($order, $user, $data, $hash), self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($order, $data['idempotency_key'], $hash, lock: true) ?? throw $exception;
        }
    }

    public function reverse(PurchaseReceipt $receipt, User $user, string $reason): PurchaseReceipt
    {
        return DB::transaction(function () use ($receipt, $user, $reason): PurchaseReceipt {
            $order = $this->orders->lock($receipt->order);
            $receipt = PurchaseReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            if ($receipt->status === ReceiptStatus::Reversed) {
                throw ValidationException::withMessages(['receipt' => 'Este recebimento já foi estornado.']);
            }

            if ($receipt->returns()->exists()) {
                throw ValidationException::withMessages(['receipt' => 'O recebimento possui devoluções e não pode ser estornado.']);
            }

            $items = $receipt->items()->with('movement')->get()->sortBy(['product_variant_id', 'id']);
            $orderItems = $order->items()->lockForUpdate()->get()->keyBy('id');

            foreach ($items as $item) {
                if ($item->movement !== null) {
                    $this->inventory->reverse($item->movement, $user, "Estorno do recebimento #{$receipt->number}: {$reason}", "purchase-receipt-reversal:{$item->id}");
                }

                $orderItem = $orderItems->get($item->purchase_order_item_id);
                $orderItem->update(['received_quantity' => (string) BigDecimal::of($orderItem->received_quantity)->minus($item->quantity)]);
            }

            $receipt->update(['status' => ReceiptStatus::Reversed, 'reversed_at' => now(), 'reversed_by_name' => $user->name, 'reversal_reason' => $reason]);
            $isClosed = $order->balance_closed_at !== null;
            $order->update(['balance_closed_at' => null]);
            $this->updateStatus($order, $orderItems, $user, "Estorno do recebimento #{$receipt->number}: {$reason}".($isClosed ? ' Saldo pendente reaberto.' : ''));

            foreach ($items->pluck('product_variant_id')->unique() as $variantId) {
                $this->supplierProducts->refreshLastCost($order->supplier, $variantId);
            }

            $this->audit->record('purchase.receipt_reversed', $order->company, $user, $receipt, ['number' => $receipt->number, 'reason' => $reason]);
            PurchaseReceiptReversed::dispatch($order);

            return $receipt;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** @param array<string, mixed> $data */
    private function receiveLocked(PurchaseOrder $order, User $user, array $data, string $hash): PurchaseReceipt
    {
        $order = $this->orders->lock($order);
        $existing = $this->existing($order, $data['idempotency_key'], $hash, lock: false);

        if ($existing !== null) {
            return $existing;
        }

        if (! $order->status->isPending()) {
            throw ValidationException::withMessages(['items' => "Pedido \"{$order->status->label()}\" não aceita recebimento."]);
        }

        $orderItems = $order->items()->with('variant.product')->lockForUpdate()->get()->keyBy('id');
        $lines = $this->validatedLines($orderItems, $data['items']);
        $receipt = PurchaseReceipt::create([
            'company_id' => $order->company_id,
            'number' => $this->sequences->next($order->company, 'purchase_receipt'),
            'purchase_order_id' => $order->id,
            'status' => ReceiptStatus::Confirmed,
            'received_at' => $data['received_at'],
            'document_reference' => $data['document_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'idempotency_key' => $data['idempotency_key'],
            'request_hash' => $hash,
        ]);
        $total = BigDecimal::zero();

        foreach ($lines as ['item' => $orderItem, 'quantity' => $quantity, 'divergence' => $divergence]) {
            $receiptItem = $this->receiveItem($receipt, $orderItem, $quantity, $divergence, $user);
            $total = $total->plus($receiptItem->acquisition_total);
        }

        $receipt->update(['total_cost' => PurchaseMath::money($total)]);
        $this->updateStatus($order, $orderItems, $user, "Recebimento #{$receipt->number}.");

        foreach (array_unique(array_map(fn (array $line): int => $line['item']->product_variant_id, $lines)) as $variantId) {
            $this->supplierProducts->refreshLastCost($order->supplier, $variantId);
        }

        $this->audit->record('purchase.receipt_registered', $order->company, $user, $receipt, ['number' => $receipt->number]);

        return $receipt;
    }

    private function receiveItem(PurchaseReceipt $receipt, PurchaseOrderItem $orderItem, string $quantity, ?string $divergence, User $user): PurchaseReceiptItem
    {
        $receivedAfter = (string) BigDecimal::of($orderItem->received_quantity)->plus($quantity);
        $allocatedBefore = (string) PurchaseReceiptItem::query()->where('purchase_order_item_id', $orderItem->id)
            ->whereHas('receipt', fn ($query) => $query->where('status', ReceiptStatus::Confirmed))->sum('acquisition_total');
        $acquisition = PurchaseMath::cumulativeShare((string) $orderItem->acquisition_total, $orderItem->quantity, $orderItem->received_quantity, $receivedAfter, PurchaseMath::money($allocatedBefore));
        $receiptItem = $receipt->items()->create([
            'company_id' => $receipt->company_id,
            'purchase_order_item_id' => $orderItem->id,
            'product_variant_id' => $orderItem->product_variant_id,
            'quantity' => $quantity,
            'acquisition_total' => $acquisition,
            'unit_cost' => PurchaseMath::unitCost($acquisition, $quantity, 6),
            'purchase_unit_cost' => PurchaseMath::unitCost($orderItem->total, $orderItem->quantity, 4),
            'divergence' => $divergence,
        ]);

        if ($orderItem->variant->product->controlsStock()) {
            $movement = $this->inventory->recordEntry(new StockOperation(
                companyId: $receipt->company_id,
                variantId: $orderItem->product_variant_id,
                quantity: $quantity,
                origin: MovementOrigin::Purchase,
                user: $user,
                reason: "Recebimento #{$receipt->number} do pedido de compra.",
                referenceType: 'purchase_receipt',
                referenceId: "{$receipt->id}:{$receiptItem->id}",
                idempotencyKey: "purchase-receipt-item:{$receiptItem->id}",
                unitCost: PurchaseMath::unitCost($acquisition, $quantity, 4),
                entryValue: $acquisition,
            ));
            $receiptItem->update(['inventory_movement_id' => $movement->id]);
        }

        $orderItem->update(['received_quantity' => $receivedAfter]);

        return $receiptItem;
    }

    /**
     * Linhas sem quantidade são ignoradas. As restantes são ordenadas por unidade comercializável, evitando deadlocks.
     *
     * @param  Collection<int, PurchaseOrderItem>  $orderItems
     * @param  list<array{purchase_order_item_id: int, quantity: string, divergence?: string|null}>  $rows
     * @return list<array{item: PurchaseOrderItem, quantity: string, divergence: string|null}>
     */
    private function validatedLines(Collection $orderItems, array $rows): array
    {
        $lines = [];

        foreach ($rows as $index => $row) {
            $item = $orderItems->get((int) $row['purchase_order_item_id']);
            $quantity = BigDecimal::of($row['quantity'])->toScale(4);

            if ($item === null) {
                throw ValidationException::withMessages(["items.{$index}.purchase_order_item_id" => 'O item não pertence a este pedido.']);
            }

            if ($quantity->isZero()) {
                continue;
            }

            $pending = BigDecimal::of($item->quantity)->minus($item->received_quantity);

            if ($quantity->isGreaterThan($pending)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "Quantidade de \"{$item->description}\" maior que a pendente (".str_replace('.', ',', (string) $pending->strippedOfTrailingZeros()).').']);
            }

            if (! $item->allows_fraction && PurchaseMath::hasFraction((string) $quantity)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "A unidade {$item->unit_code} não aceita quantidade fracionária."]);
            }

            $lines[] = ['item' => $item, 'quantity' => (string) $quantity, 'divergence' => $row['divergence'] ?? null];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Informe a quantidade recebida de ao menos um item.']);
        }

        usort($lines, fn (array $first, array $second): int => [$first['item']->product_variant_id, $first['item']->id] <=> [$second['item']->product_variant_id, $second['item']->id]);

        return $lines;
    }

    /** @param Collection<int, PurchaseOrderItem> $orderItems */
    private function updateStatus(PurchaseOrder $order, Collection $orderItems, User $user, string $reason): void
    {
        $isComplete = $orderItems->every(fn (PurchaseOrderItem $item): bool => BigDecimal::of($item->received_quantity)->isGreaterThanOrEqualTo($item->quantity));
        $isEmpty = $orderItems->every(fn (PurchaseOrderItem $item): bool => BigDecimal::of($item->received_quantity)->isZero());
        $next = match (true) {
            $isComplete || $order->balance_closed_at !== null => PurchaseOrderStatus::Received,
            $isEmpty => PurchaseOrderStatus::Confirmed,
            default => PurchaseOrderStatus::PartiallyReceived,
        };

        if ($next !== $order->status) {
            $this->orders->transition($order, $next, $user, $reason);
        }
    }

    private function existing(PurchaseOrder $order, string $key, string $hash, bool $lock): ?PurchaseReceipt
    {
        $existing = PurchaseReceipt::query()->where('company_id', $order->company_id)->where('idempotency_key', $key)
            ->when($lock, fn ($query) => $query->sharedLock())->first();

        if ($existing !== null && ! hash_equals($existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta requisição já foi usada em outro recebimento. Recarregue a página.']);
        }

        return $existing;
    }

    /** @param array<string, mixed> $data */
    private function requestHash(PurchaseOrder $order, array $data): string
    {
        $items = array_map(fn (array $row): array => [(int) $row['purchase_order_item_id'], (string) BigDecimal::of($row['quantity'])->toScale(4), $row['divergence'] ?? null], $data['items']);
        sort($items);

        return hash('sha256', (string) json_encode([$order->id, $data['received_at'], $data['document_reference'] ?? null, $data['notes'] ?? null, $items]));
    }
}
