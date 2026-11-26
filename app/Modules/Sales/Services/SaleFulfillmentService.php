<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Services\InventoryReservationService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Enums\DeliveryMethod;
use App\Modules\Sales\Enums\FulfillmentStatus;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Events\SaleFulfilled;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleFulfillmentItem;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleOrderItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atendimento integral ou parcial. Pedido, atendimento, reservas, saídas de estoque e custo histórico são gravados
 * numa única transação. O atendimento independe da situação financeira.
 *
 * O custo do item é o custo médio vigente no atendimento. Sem custo médio, fica indisponível (nulo), nunca zero.
 */
class SaleFulfillmentService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private SaleOrderService $orders,
        private SalesStock $stock,
        private InventoryService $inventory,
        private InventoryReservationService $reservations,
        private SequenceService $sequences,
        private AuditService $audit,
    ) {}

    /**
     * Dados validados: `fulfilled_at`, `method`, `notes`, `idempotency_key` e `items` (`sale_order_item_id`, `quantity`).
     *
     * @param  array<string, mixed>  $data
     */
    public function fulfill(SaleOrder $order, User $user, array $data): SaleFulfillment
    {
        $items = array_map(fn (array $row): array => [(int) $row['sale_order_item_id'], (string) BigDecimal::of($row['quantity'])->toScale(4)], $data['items']);
        sort($items);
        $hash = hash('sha256', (string) json_encode([$order->id, $data['fulfilled_at'], $data['method'] ?? null, $data['notes'] ?? null, $items]));

        try {
            return DB::transaction(fn (): SaleFulfillment => $this->fulfillLocked($order, $user, $data, $hash), self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($order, $data['idempotency_key'], $hash) ?? throw $exception;
        }
    }

    /** Estorno técnico: desfaz as saídas e as quantidades atendidas, sem apagar o registro. */
    public function reverse(SaleFulfillment $fulfillment, User $user, string $reason): SaleFulfillment
    {
        return DB::transaction(function () use ($fulfillment, $user, $reason): SaleFulfillment {
            $order = $this->orders->lock($fulfillment->order);
            $fulfillment = SaleFulfillment::query()->lockForUpdate()->findOrFail($fulfillment->id);

            if ($fulfillment->status === FulfillmentStatus::Reversed) {
                throw ValidationException::withMessages(['fulfillment' => 'Este atendimento já foi estornado.']);
            }

            if ($order->status === SaleOrderStatus::Completed) {
                throw ValidationException::withMessages(['fulfillment' => 'A venda está concluída. Registre uma devolução.']);
            }

            if ($fulfillment->returns()->exists()) {
                throw ValidationException::withMessages(['fulfillment' => 'O atendimento possui devoluções e não pode ser estornado.']);
            }

            $items = $fulfillment->items()->with('movement')->get()->sortBy(['product_variant_id', 'id']);
            $orderItems = $order->items()->lockForUpdate()->get()->keyBy('id');

            foreach ($items as $item) {
                if ($item->movement !== null) {
                    $this->inventory->reverse($item->movement, $user, "Estorno do atendimento #{$fulfillment->number}: {$reason}", "sale-fulfillment-reversal:{$item->id}");
                }

                $orderItem = $orderItems->get($item->sale_order_item_id);
                $orderItem->update(['fulfilled_quantity' => (string) BigDecimal::of($orderItem->fulfilled_quantity)->minus($item->quantity)]);
            }

            $fulfillment->update(['status' => FulfillmentStatus::Reversed, 'reversed_at' => now(), 'reversed_by_name' => $user->name, 'reversal_reason' => $reason]);
            $wasClosed = $order->balance_closed_at !== null;
            $order->update(['balance_closed_at' => null]);
            $this->updateStatus($order, $orderItems, $user, "Estorno do atendimento #{$fulfillment->number}: {$reason}".($wasClosed ? ' Saldo pendente reaberto.' : ''));
            $this->orders->syncReservations($order, $orderItems->values());
            $this->audit->record('sales.fulfillment_reversed', $order->company, $user, $fulfillment, ['number' => $fulfillment->number, 'reason' => $reason]);

            return $fulfillment;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** @param array<string, mixed> $data */
    private function fulfillLocked(SaleOrder $order, User $user, array $data, string $hash): SaleFulfillment
    {
        $order = $this->orders->lock($order);
        $existing = $this->existing($order, $data['idempotency_key'], $hash);

        if ($existing !== null) {
            return $existing;
        }

        if (! $order->status->isPending()) {
            throw ValidationException::withMessages(['items' => "Venda \"{$order->status->label()}\" não aceita atendimento."]);
        }

        $orderItems = $order->items()->lockForUpdate()->get()->keyBy('id');
        $lines = $this->validatedLines($orderItems, $data['items']);
        $fulfillment = SaleFulfillment::create([
            'company_id' => $order->company_id,
            'number' => $this->sequences->next($order->company, 'sale_fulfillment'),
            'sale_order_id' => $order->id,
            'status' => FulfillmentStatus::Confirmed,
            'method' => $data['method'] ?? $order->delivery_method->value ?? DeliveryMethod::Pickup->value,
            'fulfilled_at' => $data['fulfilled_at'],
            'notes' => $data['notes'] ?? null,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'idempotency_key' => $data['idempotency_key'],
            'request_hash' => $hash,
        ]);
        $moveStock = $this->stock->isEnabled($order->company);
        $revenue = BigDecimal::zero();
        $cost = BigDecimal::zero();
        $hasUnknownCost = false;

        foreach ($lines as ['item' => $orderItem, 'quantity' => $quantity]) {
            $fulfillmentItem = $this->fulfillItem($order, $fulfillment, $orderItem, $quantity, $user, $moveStock);
            $revenue = $revenue->plus($fulfillmentItem->revenue);
            $hasUnknownCost = $hasUnknownCost || $fulfillmentItem->cost_total === null;
            $cost = $cost->plus($fulfillmentItem->cost_total ?? '0');
        }

        $fulfillment->update(['revenue' => CommercialMath::money($revenue), 'cost_total' => $hasUnknownCost ? null : CommercialMath::money($cost)]);
        $this->updateStatus($order, $orderItems, $user, "Atendimento #{$fulfillment->number}.");
        $this->audit->record('sales.fulfillment_registered', $order->company, $user, $fulfillment, ['number' => $fulfillment->number]);
        SaleFulfilled::dispatch($order, $fulfillment);

        return $fulfillment;
    }

    private function fulfillItem(SaleOrder $order, SaleFulfillment $fulfillment, SaleOrderItem $orderItem, string $quantity, User $user, bool $moveStock): SaleFulfillmentItem
    {
        $fulfilledAfter = (string) BigDecimal::of($orderItem->fulfilled_quantity)->plus($quantity);
        $allocatedBefore = (string) SaleFulfillmentItem::query()->where('sale_order_item_id', $orderItem->id)
            ->whereHas('fulfillment', fn ($query) => $query->where('status', FulfillmentStatus::Confirmed))->sum('revenue');
        $revenue = CommercialMath::cumulativeShare((string) ($orderItem->net_total ?? $orderItem->total), $orderItem->quantity, $orderItem->fulfilled_quantity, $fulfilledAfter, CommercialMath::money($allocatedBefore));
        $averageCost = $orderItem->product_variant_id === null ? null : $this->inventory->averageCostOf($order->company_id, $orderItem->product_variant_id);
        $item = $fulfillment->items()->create([
            'company_id' => $order->company_id,
            'sale_order_item_id' => $orderItem->id,
            'product_variant_id' => $orderItem->product_variant_id,
            'quantity' => $quantity,
            'revenue' => $revenue,
            'unit_cost' => $averageCost,
            'cost_total' => $averageCost === null ? null : CommercialMath::money(BigDecimal::of($averageCost)->multipliedBy($quantity)),
        ]);

        if ($moveStock && $orderItem->controls_stock && $orderItem->product_variant_id !== null) {
            if ($order->reserves_stock) {
                $reserved = BigDecimal::of($this->reservations->reservedFor($order->company_id, SaleOrderService::RESERVATION_REFERENCE, (string) $orderItem->id));
                $this->reservations->reserve($order->company_id, $orderItem->product_variant_id, SaleOrderService::RESERVATION_REFERENCE, (string) $orderItem->id, (string) BigDecimal::max(BigDecimal::zero(), $reserved->minus($quantity)));
            }

            $movement = $this->inventory->recordExit(new StockOperation(
                companyId: $order->company_id,
                variantId: $orderItem->product_variant_id,
                quantity: $quantity,
                origin: MovementOrigin::Sale,
                user: $user,
                reason: "Atendimento #{$fulfillment->number} da venda #{$order->number}.",
                referenceType: 'sale_fulfillment',
                referenceId: "{$fulfillment->id}:{$item->id}",
                idempotencyKey: "sale-fulfillment-item:{$item->id}",
                unitCost: $averageCost === null ? null : (string) BigDecimal::of($averageCost)->toScale(4, RoundingMode::HalfUp),
            ));
            $item->update(['inventory_movement_id' => $movement->id]);
        }

        $orderItem->update(['fulfilled_quantity' => $fulfilledAfter]);

        return $item;
    }

    /**
     * Linhas sem quantidade são ignoradas; as demais são ordenadas por unidade comercializável, evitando deadlocks.
     *
     * @param  Collection<int, SaleOrderItem>  $orderItems
     * @param  list<array{sale_order_item_id: int, quantity: string}>  $rows
     * @return list<array{item: SaleOrderItem, quantity: string}>
     */
    private function validatedLines(Collection $orderItems, array $rows): array
    {
        $lines = [];

        foreach ($rows as $index => $row) {
            $item = $orderItems->get((int) $row['sale_order_item_id']);
            $quantity = BigDecimal::of($row['quantity'])->toScale(4);

            if ($item === null) {
                throw ValidationException::withMessages(["items.{$index}.sale_order_item_id" => 'O item não pertence a esta venda.']);
            }

            if ($quantity->isZero()) {
                continue;
            }

            $pending = BigDecimal::of($item->quantity)->minus($item->fulfilled_quantity);

            if ($quantity->isGreaterThan($pending)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "Quantidade de \"{$item->description}\" maior que a pendente (".str_replace('.', ',', (string) $pending->strippedOfTrailingZeros()).').']);
            }

            if (! $item->allows_fraction && CommercialMath::hasFraction((string) $quantity)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "A unidade {$item->unit_code} não aceita quantidade fracionária."]);
            }

            $lines[] = ['item' => $item, 'quantity' => (string) $quantity];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Informe a quantidade atendida de ao menos um item.']);
        }

        usort($lines, fn (array $first, array $second): int => [$first['item']->product_variant_id ?? 0, $first['item']->id] <=> [$second['item']->product_variant_id ?? 0, $second['item']->id]);

        return $lines;
    }

    /** @param Collection<int, SaleOrderItem> $orderItems */
    private function updateStatus(SaleOrder $order, Collection $orderItems, User $user, string $reason): void
    {
        $isComplete = $orderItems->every(fn (SaleOrderItem $item): bool => BigDecimal::of($item->fulfilled_quantity)->isGreaterThanOrEqualTo($item->quantity));
        $isEmpty = $orderItems->every(fn (SaleOrderItem $item): bool => BigDecimal::of($item->fulfilled_quantity)->isZero());
        $next = match (true) {
            $isComplete || ($order->balance_closed_at !== null && ! $isEmpty) => SaleOrderStatus::Fulfilled,
            $isEmpty => SaleOrderStatus::Confirmed,
            default => SaleOrderStatus::PartiallyFulfilled,
        };

        if ($next !== $order->status) {
            $this->orders->transition($order, $next, $user, $reason);
        }
    }

    private function existing(SaleOrder $order, string $key, string $hash): ?SaleFulfillment
    {
        $existing = SaleFulfillment::query()->where('company_id', $order->company_id)->where('idempotency_key', $key)->first();

        if ($existing !== null && ! hash_equals($existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta requisição já foi usada em outro atendimento. Recarregue a página.']);
        }

        return $existing;
    }
}
