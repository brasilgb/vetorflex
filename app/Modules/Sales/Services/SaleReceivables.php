<?php

namespace App\Modules\Sales\Services;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Sales\Contracts\SaleReceivableSource;
use App\Modules\Sales\Data\SaleReceivable;
use App\Modules\Sales\Enums\FulfillmentStatus;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SalePaymentSchedule;
use App\Modules\Sales\Models\SaleReturn;

class SaleReceivables implements SaleReceivableSource
{
    public function receivableFor(SaleOrder $order): ?SaleReceivable
    {
        if ($order->status === SaleOrderStatus::Draft) {
            return null;
        }

        $returns = $order->returns()->get();

        return new SaleReceivable(
            companyId: $order->company_id,
            customerPartyId: $order->customer_party_id,
            customerName: $order->customer_snapshot['name'] ?? null,
            saleOrderId: $order->id,
            saleOrderNumber: $order->number,
            status: $order->status->value,
            isCancelled: $order->status === SaleOrderStatus::Cancelled,
            amount: $order->total,
            fulfilledValue: CommercialMath::money((string) $order->fulfillments()->where('status', FulfillmentStatus::Confirmed)->sum('revenue')),
            returnedValue: CommercialMath::money((string) $returns->sum(fn (SaleReturn $return): string => $return->total_value)),
            paymentCondition: $order->payment_condition->value,
            installments: array_values($order->paymentSchedules()->get()->map(fn (SalePaymentSchedule $schedule): array => [
                'sequence' => $schedule->sequence,
                'due_date' => $schedule->due_date->format('Y-m-d'),
                'amount' => $schedule->amount,
                'method' => $schedule->method?->value,
                'is_entry' => $schedule->is_entry,
                'idempotency_key' => "sale-order:{$order->id}:installment:{$schedule->sequence}",
            ])->all()),
            returns: array_values($returns->map(fn (SaleReturn $return): array => [
                'number' => $return->number,
                'returned_at' => $return->returned_at->format('Y-m-d'),
                'amount' => $return->total_value,
                'idempotency_key' => "sale-return:{$return->id}",
            ])->all()),
            origin: 'sale_order',
            idempotencyKey: "sale-order:{$order->id}",
        );
    }
}
