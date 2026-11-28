<?php

namespace App\Modules\Purchases\Services;

use App\Modules\Purchases\Contracts\PurchaseObligationSource;
use App\Modules\Purchases\Data\PurchaseObligation;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Enums\ReceiptStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchasePaymentSchedule;
use App\Modules\Purchases\Models\PurchaseReceipt;
use App\Modules\Purchases\Support\PurchaseMath;
use App\Modules\Tenancy\Models\Company;

class PurchaseObligations implements PurchaseObligationSource
{
    public function obligationFor(PurchaseOrder $order): ?PurchaseObligation
    {
        if ($order->status === PurchaseOrderStatus::Draft) {
            return null;
        }

        $receipts = $order->receipts()->where('status', ReceiptStatus::Confirmed)->get();

        return new PurchaseObligation(
            companyId: $order->company_id,
            supplierProfileId: $order->supplier_profile_id,
            partyId: $order->supplier->party_id,
            purchaseOrderId: $order->id,
            purchaseOrderNumber: $order->number,
            status: $order->status->value,
            amount: $order->total,
            receivedValue: PurchaseMath::money((string) $receipts->sum(fn (PurchaseReceipt $receipt): string => $receipt->total_cost)),
            returnedValue: PurchaseMath::money((string) $order->returns()->sum('total_value')),
            installments: array_values($order->paymentSchedules->map(fn (PurchasePaymentSchedule $schedule): array => [
                'sequence' => $schedule->sequence,
                'due_date' => $schedule->due_date->format('Y-m-d'),
                'amount' => $schedule->amount,
                'method' => $schedule->method?->value,
                'is_entry' => $schedule->is_entry,
            ])->all()),
            origin: 'purchase_order',
            idempotencyKey: "purchase-order:{$order->id}",
            isBalanceClosed: $order->balance_closed_at !== null,
            orderDate: $order->order_date->format('Y-m-d'),
            supplierName: $order->supplier_snapshot['name'] ?? null,
        );
    }

    public function obligationsBetween(Company $company, string $from, string $until): array
    {
        return array_values(PurchaseOrder::query()->where('company_id', $company->id)->whereNotNull('confirmed_at')
            ->whereBetween('order_date', [$from, $until])->with('supplier')->orderBy('order_date')->orderBy('id')->get()
            ->map(fn (PurchaseOrder $order): ?PurchaseObligation => $this->obligationFor($order))->filter()->all());
    }
}
