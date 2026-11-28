<?php

namespace App\Modules\Finance\Support;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceAttachment;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceRecurrence;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Models\FinanceTitleEvent;
use App\Modules\Finance\Models\FinanceTransfer;
use Brick\Math\BigDecimal;

/**
 * Formato dos registros financeiros enviados às telas. Valores monetários seguem como texto decimal.
 */
class FinancePresenter
{
    /** @return array<string, mixed> */
    public function titleSummary(FinanceTitle $title, string $today): array
    {
        return [
            'id' => $title->id,
            'number' => $title->number,
            'kind' => $title->kind->value,
            'status' => $title->status->value,
            'status_label' => $title->status->label(),
            'is_overdue' => $title->isOverdueOn($today),
            'origin' => $title->origin->value,
            'origin_label' => $title->origin->label(),
            'description' => $title->description,
            'party_name' => $title->party_name,
            'category' => $title->category?->fullName(),
            'competence_date' => $title->competence_date->format('Y-m-d'),
            'due_date' => $title->due_date->format('Y-m-d'),
            'amount' => CommercialMath::money($title->effectiveAmount()),
            'settled' => $title->settled_principal,
            'open' => CommercialMath::money($title->status->isOpen() ? $title->openBalance() : BigDecimal::zero()),
            'installment' => $title->installment_count > 1 ? "{$title->installment_number}/{$title->installment_count}" : null,
            'source_label' => $title->source_label,
        ];
    }

    /** @return array<string, mixed> */
    public function title(FinanceTitle $title, string $today): array
    {
        return [
            ...$this->titleSummary($title, $today),
            'original_amount' => $title->amount,
            'adjusted_amount' => $title->adjusted_amount,
            'cancelled_amount' => $title->cancelled_amount,
            'interest_total' => $title->interest_total,
            'fine_total' => $title->fine_total,
            'discount_total' => $title->discount_total,
            'party_id' => $title->party_id,
            'finance_category_id' => $title->finance_category_id,
            'notes' => $title->notes,
            'cancellation_reason' => $title->cancellation_reason,
            'is_editable' => $title->status->isOpen(),
            'has_settlements' => BigDecimal::of($title->settled_principal)->isPositive(),
            'is_integration' => ! in_array($title->origin->value, ['manual', 'recurrence'], true),
            'user_name' => $title->user_name,
            'settlements' => $title->settlements->map(fn (FinanceSettlement $settlement): array => $this->settlement($settlement))->values()->all(),
            'events' => $title->events->map(fn (FinanceTitleEvent $event): array => [
                'id' => $event->id,
                'event' => $event->event,
                'description' => $event->description,
                'amount' => $event->amount,
                'user_name' => $event->user_name,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ])->values()->all(),
            'attachments' => $title->attachments->map(fn (FinanceAttachment $attachment): array => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'size' => $attachment->size,
                'user_name' => $attachment->user_name,
                'created_at' => $attachment->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function settlement(FinanceSettlement $settlement): array
    {
        return [
            'id' => $settlement->id,
            'status' => $settlement->status->value,
            'status_label' => $settlement->status->label(),
            'is_external' => $settlement->is_external,
            'account' => $settlement->account?->name,
            'settled_at' => $settlement->settled_at->format('Y-m-d'),
            'method' => $settlement->method?->label(),
            'principal' => $settlement->principal,
            'interest' => $settlement->interest,
            'fine' => $settlement->fine,
            'discount' => $settlement->discount,
            'amount' => $settlement->amount,
            'notes' => $settlement->notes,
            'user_name' => $settlement->user_name,
            'reversed_by_name' => $settlement->reversed_by_name,
            'reversal_reason' => $settlement->reversal_reason,
        ];
    }

    /** @return array<string, mixed> */
    public function account(FinanceAccount $account, string $balance): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'type_label' => $account->type->label(),
            'institution' => $account->institution,
            'opening_balance' => $account->opening_balance,
            'opening_date' => $account->opening_date->format('Y-m-d'),
            'is_active' => $account->is_active,
            'notes' => $account->notes,
            'balance' => $balance,
        ];
    }

    /** @return array<string, mixed> */
    public function movement(FinanceMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'account' => $movement->account->name,
            'type' => $movement->type->value,
            'type_label' => $movement->type->label(),
            'amount' => $movement->amount,
            'is_operational' => $movement->is_operational,
            'occurred_at' => $movement->occurred_at->format('Y-m-d'),
            'description' => $movement->description,
            'category' => $movement->category?->fullName(),
            'method' => $movement->method === null ? null : (PaymentMethod::tryFrom($movement->method)?->label() ?? $movement->method),
            'user_name' => $movement->user_name,
            'title_id' => $movement->settlement?->finance_title_id,
            'is_reversal' => $movement->reversal_of_id !== null,
            'reconciled_at' => $movement->reconciled_at?->toIso8601String(),
            'reconciled_by_name' => $movement->reconciled_by_name,
        ];
    }

    /** @return array<string, mixed> */
    public function transfer(FinanceTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'from' => $transfer->from->name,
            'to' => $transfer->to->name,
            'amount' => $transfer->amount,
            'transferred_at' => $transfer->transferred_at->format('Y-m-d'),
            'description' => $transfer->description,
            'status' => $transfer->status,
            'user_name' => $transfer->user_name,
            'reversed_by_name' => $transfer->reversed_by_name,
            'reversal_reason' => $transfer->reversal_reason,
        ];
    }

    /** @return array<string, mixed> */
    public function recurrence(FinanceRecurrence $recurrence): array
    {
        return [
            'id' => $recurrence->id,
            'kind' => $recurrence->kind->value,
            'description' => $recurrence->description,
            'category' => $recurrence->category?->fullName(),
            'amount' => $recurrence->amount,
            'frequency_label' => $recurrence->frequency->label(),
            'start_date' => $recurrence->start_date->format('Y-m-d'),
            'end_date' => $recurrence->end_date?->format('Y-m-d'),
            'generated_until' => $recurrence->generated_until?->format('Y-m-d'),
            'ended_at' => $recurrence->ended_at?->toIso8601String(),
            'titles_count' => (int) ($recurrence->titles_count ?? 0),
        ];
    }

    /** @return array{id: int, kind: string, name: string, full_name: string, parent_id: int|null, is_active: bool} */
    public function category(FinanceCategory $category): array
    {
        return [
            'id' => $category->id,
            'kind' => $category->kind->value,
            'name' => $category->name,
            'full_name' => $category->fullName(),
            'parent_id' => $category->parent_id,
            'is_active' => $category->is_active,
        ];
    }
}
