<?php

namespace App\Modules\Sales\Support;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SaleQuoteStatus;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleFulfillmentItem;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleOrderItem;
use App\Modules\Sales\Models\SalePaymentSchedule;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Sales\Models\SaleQuoteItem;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnItem;
use App\Modules\Sales\Models\SaleStatusChange;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Formata documentos de venda para as respostas. Preços e totais de venda são visíveis a quem consulta vendas;
 * custos exigem `sales.view_cost` e margens, também `sales.view_margin`.
 */
class SalePresenter
{
    /** @return array<string, mixed> */
    public function quoteSummary(SaleQuote $quote, string $today): array
    {
        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'status' => ['value' => $quote->status->value, 'label' => $quote->status->label()],
            'customer' => $quote->customer_snapshot['name'] ?? null,
            'seller_name' => $quote->seller_name,
            'issue_date' => $quote->issue_date->format('Y-m-d'),
            'valid_until' => $quote->valid_until->format('Y-m-d'),
            'is_overdue' => $quote->isExpiredOn($today),
            'total' => $quote->total,
        ];
    }

    /** @return array<string, mixed> */
    public function quote(SaleQuote $quote, string $today): array
    {
        return [
            ...$this->quoteSummary($quote, $today),
            ...$this->header($quote),
            'items' => $quote->items->map(fn (SaleQuoteItem $item): array => $this->item($item))->values()->all(),
            'issued_at' => $quote->issued_at?->toIso8601String(),
            'decided_at' => $quote->decided_at?->toIso8601String(),
            'order' => $quote->order === null ? null : ['id' => $quote->order->id, 'number' => $quote->order->number],
            'history' => $this->history($quote->statusHistory, fn (string $status): string => SaleQuoteStatus::from($status)->label()),
        ];
    }

    /** @return array<string, mixed> */
    public function orderSummary(SaleOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => ['value' => $order->status->value, 'label' => $order->status->label()],
            'origin' => ['value' => $order->origin->value, 'label' => $order->origin->label()],
            'customer' => $order->customer_snapshot['name'] ?? null,
            'seller_name' => $order->seller_name,
            'order_date' => $order->order_date->format('Y-m-d'),
            'payment_method' => $order->payment_method?->label(),
            'is_quick' => $order->is_quick,
            'total' => $order->total,
        ];
    }

    /** @return array<string, mixed> */
    public function order(SaleOrder $order, bool $canViewCost, bool $canViewMargin): array
    {
        $items = $order->items->map(fn (SaleOrderItem $item): array => [
            ...$this->item($item),
            'net_total' => $item->net_total,
            'fulfilled_quantity' => $item->fulfilled_quantity,
            'returned_quantity' => $item->returned_quantity,
            'pending_quantity' => (string) BigDecimal::max(BigDecimal::zero(), BigDecimal::of($item->quantity)->minus($item->fulfilled_quantity))->toScale(4),
        ]);

        return [
            ...$this->orderSummary($order),
            ...$this->header($order),
            'sale_quote' => $order->quote === null ? null : ['id' => $order->quote->id, 'number' => $order->quote->number],
            'external_reference' => $order->external_reference,
            'expected_date' => $order->expected_date?->format('Y-m-d'),
            'delivery_method' => ['value' => $order->delivery_method->value, 'label' => $order->delivery_method->label()],
            'delivery_address' => $order->delivery_address,
            'internal_notes' => $order->internal_notes,
            'reserves_stock' => $order->reserves_stock,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'balance_closed_at' => $order->balance_closed_at?->toIso8601String(),
            'items' => $items->values()->all(),
            'history' => $this->history($order->statusHistory, fn (string $status): string => SaleOrderStatus::from($status)->label()),
            'margin' => $canViewCost && $canViewMargin ? $this->margin($order) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function fulfillment(SaleFulfillment $fulfillment, bool $canViewCost): array
    {
        return [
            'id' => $fulfillment->id,
            'number' => $fulfillment->number,
            'status' => ['value' => $fulfillment->status->value, 'label' => $fulfillment->status->label()],
            'method' => ['value' => $fulfillment->method->value, 'label' => $fulfillment->method->label()],
            'fulfilled_at' => $fulfillment->fulfilled_at->format('Y-m-d'),
            'notes' => $fulfillment->notes,
            'revenue' => $fulfillment->revenue,
            'cost_total' => $canViewCost ? $fulfillment->cost_total : null,
            'user_name' => $fulfillment->user_name,
            'reversed_at' => $fulfillment->reversed_at?->toIso8601String(),
            'reversed_by_name' => $fulfillment->reversed_by_name,
            'reversal_reason' => $fulfillment->reversal_reason,
            'has_returns' => $fulfillment->relationLoaded('returns') ? $fulfillment->returns->isNotEmpty() : $fulfillment->returns()->exists(),
            'order' => $fulfillment->relationLoaded('order') ? ['id' => $fulfillment->order->id, 'number' => $fulfillment->order->number, 'customer' => $fulfillment->order->customer_snapshot['name'] ?? null] : null,
            'items' => $fulfillment->items->map(fn (SaleFulfillmentItem $item): array => [
                'id' => $item->id,
                'sale_order_item_id' => $item->sale_order_item_id,
                'description' => $item->orderItem->description,
                'unit_code' => $item->orderItem->unit_code,
                'allows_fraction' => $item->orderItem->allows_fraction,
                'quantity' => $item->quantity,
                'returned_quantity' => $item->returned_quantity,
                'revenue' => $item->revenue,
                'moved_stock' => $item->inventory_movement_id !== null,
                ...($canViewCost ? ['unit_cost' => $item->unit_cost, 'cost_total' => $item->cost_total] : []),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function saleReturn(SaleReturn $return, bool $canViewCost): array
    {
        return [
            'id' => $return->id,
            'number' => $return->number,
            'returned_at' => $return->returned_at->format('Y-m-d'),
            'reason' => $return->reason,
            'restock' => $return->restock,
            'total_value' => $return->total_value,
            'cost_total' => $canViewCost ? $return->cost_total : null,
            'user_name' => $return->user_name,
            'fulfillment_number' => $return->fulfillment->number,
            'order' => $return->relationLoaded('order') ? ['id' => $return->order->id, 'number' => $return->order->number, 'customer' => $return->order->customer_snapshot['name'] ?? null] : null,
            'items' => $return->items->map(fn (SaleReturnItem $item): array => [
                'description' => $item->fulfillmentItem->orderItem->description,
                'unit_code' => $item->fulfillmentItem->orderItem->unit_code,
                'quantity' => $item->quantity,
                'historical_value' => $item->historical_value,
                'restocked' => $item->inventory_movement_id !== null,
            ])->values()->all(),
        ];
    }

    /**
     * Margem comercial a partir dos atendimentos e devoluções registrados, com o custo histórico de cada um.
     * Sem custo confiável para algum item atendido, a margem é indisponível, nunca calculada com custo zero.
     *
     * @return array{revenue: string, cost: string|null, margin: string|null, percent: string|null, is_complete: bool}
     */
    public function margin(SaleOrder $order): array
    {
        $fulfilled = $order->fulfillments()->where('status', 'confirmed')->with('items')->get();
        $returns = $order->returns()->with('items')->get();
        $revenue = BigDecimal::zero();
        $cost = BigDecimal::zero();
        $isComplete = true;

        foreach ($fulfilled->flatMap->items as $item) {
            $revenue = $revenue->plus($item->revenue);
            $isComplete = $isComplete && $item->cost_total !== null;
            $cost = $cost->plus($item->cost_total ?? '0');
        }

        foreach ($returns->flatMap->items as $item) {
            $revenue = $revenue->minus($item->historical_value);
            $cost = $cost->minus($item->cost_value ?? '0');
        }

        $hasData = $fulfilled->isNotEmpty() && $isComplete;
        $margin = $revenue->minus($cost);

        return [
            'revenue' => CommercialMath::money($revenue),
            'cost' => $hasData ? CommercialMath::money($cost) : null,
            'margin' => $hasData ? CommercialMath::money($margin) : null,
            'percent' => $hasData && $revenue->isPositive() ? (string) $margin->multipliedBy(100)->dividedBy($revenue, 2, RoundingMode::HalfUp) : null,
            'is_complete' => $isComplete,
        ];
    }

    /** @return array<string, mixed> */
    private function header(SaleQuote|SaleOrder $document): array
    {
        return [
            'customer_party_id' => $document->customer_party_id,
            'customer_snapshot' => $document->customer_snapshot,
            'seller_id' => $document->seller_id,
            'user_name' => $document->user_name,
            'commercial_terms' => $document->commercial_terms,
            'notes' => $document->notes,
            'items_total' => $document->items_total,
            'discount' => $document->discount,
            'freight' => $document->freight,
            'other_costs' => $document->other_costs,
            'payment_condition' => ['value' => $document->payment_condition->value, 'label' => $document->payment_condition->label()],
            'payment_method' => $document->payment_method === null ? null : ['value' => $document->payment_method->value, 'label' => $document->payment_method->label()],
            'payment_notes' => $document->payment_notes,
            'payment_schedules' => $document->paymentSchedules->map(fn (SalePaymentSchedule $schedule): array => [
                'sequence' => $schedule->sequence,
                'is_entry' => $schedule->is_entry,
                'due_date' => $schedule->due_date->format('Y-m-d'),
                'amount' => $schedule->amount,
                'method' => $schedule->method?->label(),
                'method_value' => $schedule->method?->value,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function item(SaleQuoteItem|SaleOrderItem $item): array
    {
        return $item->only(['id', 'product_variant_id', 'position', 'description', 'sku', 'unit_code', 'allows_fraction', 'is_service', 'is_custom', 'controls_stock', 'quantity', 'catalog_price', 'unit_price', 'discount_percent', 'discount', 'total', 'notes']);
    }

    /**
     * @param  iterable<SaleStatusChange>  $changes
     * @param  callable(string): string  $label
     * @return list<array<string, mixed>>
     */
    private function history(iterable $changes, callable $label): array
    {
        $rows = [];

        foreach ($changes as $change) {
            $rows[] = [
                'id' => $change->id,
                'from' => $change->from_status === null ? null : $label($change->from_status),
                'to' => $label($change->to_status),
                'reason' => $change->reason,
                'user_name' => $change->user_name,
                'occurred_at' => $change->occurred_at->toIso8601String(),
            ];
        }

        return $rows;
    }
}
