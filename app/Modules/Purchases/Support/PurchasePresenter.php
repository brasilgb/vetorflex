<?php

namespace App\Modules\Purchases\Support;

use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Enums\ReceiptStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use App\Modules\Purchases\Models\PurchasePaymentSchedule;
use App\Modules\Purchases\Models\PurchaseReceipt;
use App\Modules\Purchases\Models\PurchaseReceiptItem;
use App\Modules\Purchases\Models\PurchaseReturn;
use App\Modules\Purchases\Models\PurchaseReturnItem;
use App\Modules\Purchases\Models\PurchaseStatusChange;
use App\Modules\Purchases\Models\SupplierProfile;
use Brick\Math\BigDecimal;

/**
 * Formata documentos de compra para as respostas. Sem `purchases.view_cost`, nenhum valor monetário é enviado.
 */
class PurchasePresenter
{
    /** @return array<string, mixed> */
    public function orderSummary(PurchaseOrder $order, bool $canViewCost, string $today): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $this->status($order->status),
            'supplier' => $order->supplier_snapshot['name'] ?? '',
            'order_date' => $order->order_date->format('Y-m-d'),
            'expected_date' => $order->expected_date?->format('Y-m-d'),
            'is_late' => $this->isLate($order, $today),
            'is_quick' => $order->is_quick,
            'user_name' => $order->user_name,
            ...($canViewCost ? ['total' => $order->total] : []),
        ];
    }

    /** @return array<string, mixed> */
    public function order(PurchaseOrder $order, bool $canViewCost, string $today): array
    {
        return [
            ...$this->orderSummary($order, $canViewCost, $today),
            'supplier_profile_id' => $order->supplier_profile_id,
            'supplier_snapshot' => $order->supplier_snapshot,
            'external_reference' => $order->external_reference,
            'notes' => $order->notes,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'balance_closed_at' => $order->balance_closed_at?->toIso8601String(),
            'payment_condition' => ['value' => $order->payment_condition->value, 'label' => $order->payment_condition->label()],
            'payment_method' => $order->payment_method === null ? null : ['value' => $order->payment_method->value, 'label' => $order->payment_method->label()],
            'payment_notes' => $order->payment_notes,
            ...($canViewCost ? $order->only(['items_total', 'discount', 'freight', 'insurance', 'other_costs']) : []),
            'items' => $order->items->map(fn (PurchaseOrderItem $item): array => $this->orderItem($item, $canViewCost))->values()->all(),
            'payment_schedules' => $canViewCost ? $order->paymentSchedules->map(fn (PurchasePaymentSchedule $schedule): array => [
                'sequence' => $schedule->sequence,
                'is_entry' => $schedule->is_entry,
                'due_date' => $schedule->due_date->format('Y-m-d'),
                'amount' => $schedule->amount,
                'method' => $schedule->method?->label(),
                'method_value' => $schedule->method?->value,
            ])->values()->all() : [],
            'history' => $order->statusHistory->map(fn (PurchaseStatusChange $change): array => [
                'id' => $change->id,
                'from' => $change->from_status?->label(),
                'to' => $change->to_status->label(),
                'reason' => $change->reason,
                'user_name' => $change->user_name,
                'occurred_at' => $change->occurred_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function orderItem(PurchaseOrderItem $item, bool $canViewCost): array
    {
        return [
            ...$item->only(['id', 'product_variant_id', 'position', 'description', 'sku', 'supplier_code', 'unit_code', 'allows_fraction', 'is_service', 'is_free', 'quantity', 'received_quantity']),
            'pending_quantity' => (string) BigDecimal::of($item->quantity)->minus($item->received_quantity)->toScale(4),
            ...($canViewCost ? $item->only(['unit_cost', 'discount', 'total', 'acquisition_total']) : []),
        ];
    }

    /** @return array<string, mixed> */
    public function receipt(PurchaseReceipt $receipt, bool $canViewCost): array
    {
        return [
            ...$receipt->only(['id', 'number', 'purchase_order_id', 'document_reference', 'notes', 'user_name', 'reversed_by_name', 'reversal_reason']),
            'status' => ['value' => $receipt->status->value, 'label' => $receipt->status->label()],
            'is_reversed' => $receipt->status === ReceiptStatus::Reversed,
            'received_at' => $receipt->received_at->format('Y-m-d'),
            'reversed_at' => $receipt->reversed_at?->toIso8601String(),
            'created_at' => $receipt->created_at?->toIso8601String(),
            ...($canViewCost ? ['total_cost' => $receipt->total_cost] : []),
            'items' => $receipt->relationLoaded('items') ? $receipt->items->map(fn (PurchaseReceiptItem $item): array => [
                'id' => $item->id,
                'purchase_order_item_id' => $item->purchase_order_item_id,
                'description' => $item->orderItem->description,
                'unit_code' => $item->orderItem->unit_code,
                'allows_fraction' => $item->orderItem->allows_fraction,
                'is_service' => $item->orderItem->is_service,
                'quantity' => $item->quantity,
                'returned_quantity' => $item->returned_quantity,
                'returnable_quantity' => (string) BigDecimal::of($item->quantity)->minus($item->returned_quantity)->toScale(4),
                'divergence' => $item->divergence,
                ...($canViewCost ? $item->only(['acquisition_total', 'unit_cost', 'purchase_unit_cost']) : []),
            ])->values()->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    public function purchaseReturn(PurchaseReturn $return, bool $canViewCost): array
    {
        return [
            ...$return->only(['id', 'number', 'purchase_order_id', 'purchase_receipt_id', 'reason', 'user_name']),
            'returned_at' => $return->returned_at->format('Y-m-d'),
            'receipt_number' => $return->receipt->number,
            ...($canViewCost ? ['total_value' => $return->total_value] : []),
            'items' => $return->relationLoaded('items') ? $return->items->map(fn (PurchaseReturnItem $item): array => [
                'id' => $item->id,
                'description' => $item->receiptItem->orderItem->description,
                'unit_code' => $item->receiptItem->orderItem->unit_code,
                'quantity' => $item->quantity,
                ...($canViewCost ? $item->only(['historical_value', 'stock_value']) : []),
            ])->values()->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    public function supplierSummary(SupplierProfile $supplier): array
    {
        $party = $supplier->party;

        return [
            'id' => $supplier->id,
            'party_id' => $party->id,
            'name' => $party->name,
            'trade_name' => $party->trade_name,
            'type' => $party->type,
            'document' => BrazilianDocument::format($party->document),
            'email' => $party->email,
            'phone' => $party->phone ?? $party->mobile ?? $party->whatsapp,
            'internal_code' => $supplier->internal_code,
            'is_active' => $supplier->is_active,
            'is_customer' => $party->relationLoaded('customerProfile') ? $party->customerProfile !== null : null,
        ];
    }

    /** @return array{value: string, label: string} */
    public function status(PurchaseOrderStatus $status): array
    {
        return ['value' => $status->value, 'label' => $status->label()];
    }

    public function isLate(PurchaseOrder $order, string $today): bool
    {
        return $order->status->isPending() && $order->expected_date !== null && $order->expected_date->format('Y-m-d') < $today;
    }
}
