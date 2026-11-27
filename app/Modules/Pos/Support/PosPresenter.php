<?php

namespace App\Modules\Pos\Support;

use App\Models\User;
use App\Modules\Pos\Models\PosCashMovement;
use App\Modules\Pos\Models\PosPayment;
use App\Modules\Pos\Models\PosRefund;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Models\PosTerminal;

/**
 * Formato dos registros do PDV para as telas. Valores monetários seguem como texto decimal; nunca há custo ou margem.
 */
class PosPresenter
{
    /** @return array<string, mixed> */
    public function terminal(PosTerminal $terminal): array
    {
        return [
            'id' => $terminal->id,
            'name' => $terminal->name,
            'code' => $terminal->code,
            'location' => $terminal->location,
            'is_active' => $terminal->is_active,
            'receipt_width' => $terminal->receiptWidth(),
            'operators' => $terminal->relationLoaded('operators') ? $terminal->operators->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all() : [],
            'open_session' => $terminal->relationLoaded('openSession') && $terminal->openSession !== null ? ['id' => $terminal->openSession->id, 'number' => $terminal->openSession->number, 'user_name' => $terminal->openSession->user_name, 'user_id' => $terminal->openSession->user_id] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function session(PosSession $session): array
    {
        return [
            'id' => $session->id,
            'number' => $session->number,
            'status' => $session->status->value,
            'status_label' => $session->status->label(),
            'terminal' => ['id' => $session->terminal->id, 'name' => $session->terminal->name, 'code' => $session->terminal->code, 'receipt_width' => $session->terminal->receiptWidth()],
            'user_id' => $session->user_id,
            'user_name' => $session->user_name,
            'opening_amount' => $session->opening_amount,
            'opening_notes' => $session->opening_notes,
            'opened_at' => $session->opened_at->toIso8601String(),
            'is_blind' => $session->is_blind,
            'closed_at' => $session->closed_at?->toIso8601String(),
            'closed_by_name' => $session->closed_by_name,
            'expected_cash' => $session->expected_cash,
            'counted_cash' => $session->counted_cash,
            'divergence' => $session->divergence,
            'closing_notes' => $session->closing_notes,
        ];
    }

    /** @return array<string, mixed> */
    public function sale(PosSale $sale): array
    {
        return [
            'id' => $sale->id,
            'status' => $sale->status->value,
            'status_label' => $sale->status->label(),
            'order_id' => $sale->sale_order_id,
            'order_number' => $sale->order?->number,
            'customer_name' => $sale->order?->customer_snapshot['name'] ?? ($sale->cart['customer_name'] ?? null),
            'terminal' => $sale->terminal->name,
            'session_number' => $sale->session->number,
            'user_name' => $sale->user_name,
            'label' => $sale->label,
            'total' => $sale->status->value === 'completed' ? $sale->total : ($sale->cart['estimated_total'] ?? null),
            'received_total' => $sale->received_total,
            'term_total' => $sale->term_total,
            'change_total' => $sale->change_total,
            'refunded_total' => $sale->refunded_total,
            'authorized_by_name' => $sale->authorized_by_name,
            'items_count' => $sale->order !== null ? $sale->order->items()->count() : count($sale->cart['items'] ?? []),
            'suspended_at' => $sale->suspended_at?->toIso8601String(),
            'completed_at' => $sale->completed_at?->toIso8601String(),
            'cancelled_at' => $sale->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $sale->cancellation_reason,
            'cancelled_by_name' => $sale->cancelled_by_name,
            'reprints' => $sale->reprints,
        ];
    }

    /** @return array<string, mixed> */
    public function payment(PosPayment $payment): array
    {
        return [
            'sequence' => $payment->sequence,
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'amount' => $payment->amount,
            'tendered' => $payment->tendered,
            'change' => $payment->change,
            'is_received' => $payment->is_received,
            'due_date' => $payment->due_date->format('Y-m-d'),
        ];
    }

    /** @return array<string, mixed> */
    public function movement(PosCashMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'type' => $movement->type->value,
            'type_label' => $movement->type->label(),
            'amount' => $movement->amount,
            'reason' => $movement->reason,
            'user_name' => $movement->user_name,
            'authorized_by_name' => $movement->authorized_by_name,
            'is_post_close' => $movement->is_post_close,
            'occurred_at' => $movement->occurred_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function refund(PosRefund $refund): array
    {
        return [
            'id' => $refund->id,
            'return_number' => $refund->saleReturn->number,
            'method_label' => $refund->method->label(),
            'amount' => $refund->amount,
            'return_value' => $refund->saleReturn->total_value,
            'user_name' => $refund->user_name,
            'authorized_by_name' => $refund->authorized_by_name,
            'created_at' => $refund->created_at?->toIso8601String(),
        ];
    }
}
