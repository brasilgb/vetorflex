<?php

namespace App\Modules\Purchases\Services;

use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Enums\ReceiptStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseReceiptItem;
use App\Modules\Purchases\Support\PurchaseMath;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores calculados a partir dos documentos de compra. Valores pedidos, recebidos e devolvidos são
 * distintos; pagamentos efetivos dependem do Financeiro e não são apresentados.
 */
class PurchaseIndicators
{
    private const RANKING_SIZE = 5;

    private const MONTHS = 12;

    /** @return array<string, mixed> */
    public function for(Company $company, string $from, string $until, bool $canViewCost): array
    {
        $today = now($company->timezone)->format('Y-m-d');
        $effective = fn (): Builder => PurchaseOrder::query()->where('company_id', $company->id)
            ->whereIn('status', PurchaseOrderStatus::effective())->whereBetween('order_date', [$from, $until]);
        $receiptItems = fn (): Builder => PurchaseReceiptItem::query()->where('purchase_receipt_items.company_id', $company->id)
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_items.purchase_receipt_id')
            ->where('purchase_receipts.status', ReceiptStatus::Confirmed->value)
            ->whereBetween('purchase_receipts.received_at', [$from, $until]);

        $indicators = [
            'period' => ['from' => $from, 'until' => $until],
            'orders_count' => $effective()->count(),
            'pending_count' => PurchaseOrder::query()->where('company_id', $company->id)->pending()->count(),
            'late_count' => PurchaseOrder::query()->where('company_id', $company->id)->late($today)->count(),
            'draft_count' => PurchaseOrder::query()->where('company_id', $company->id)->where('status', PurchaseOrderStatus::Draft)->count(),
            'top_products' => $receiptItems()
                ->join('purchase_order_items', 'purchase_order_items.id', '=', 'purchase_receipt_items.purchase_order_item_id')
                ->groupBy('purchase_receipt_items.product_variant_id', 'purchase_order_items.unit_code')
                ->selectRaw('purchase_receipt_items.product_variant_id, purchase_order_items.unit_code, sum(purchase_receipt_items.quantity) as quantity, sum(purchase_receipt_items.acquisition_total) as value, max(purchase_order_items.description) as description')
                ->orderByDesc('value')->orderBy('purchase_receipt_items.product_variant_id')->limit(self::RANKING_SIZE)->toBase()->get()
                ->map(fn (object $row): array => [
                    'product_variant_id' => (int) $row->product_variant_id,
                    'description' => $row->description,
                    'unit_code' => $row->unit_code,
                    'quantity' => (string) BigDecimal::of($row->quantity)->toScale(4, RoundingMode::HalfUp),
                    ...($canViewCost ? ['value' => PurchaseMath::money((string) $row->value)] : []),
                ])->values()->all(),
            'top_suppliers' => $effective()
                ->groupBy('supplier_profile_id')
                ->selectRaw('supplier_profile_id, count(*) as orders, sum(total) as value')
                ->orderByDesc($canViewCost ? 'value' : 'orders')->orderBy('supplier_profile_id')->limit(self::RANKING_SIZE)->toBase()->get()
                ->map(fn (object $row): array => [
                    'supplier_profile_id' => (int) $row->supplier_profile_id,
                    'name' => PurchaseOrder::query()->where('company_id', $company->id)->where('supplier_profile_id', $row->supplier_profile_id)->latest('id')->first()?->supplier_snapshot['name'] ?? '',
                    'orders' => (int) $row->orders,
                    ...($canViewCost ? ['value' => PurchaseMath::money((string) $row->value)] : []),
                ])->values()->all(),
        ];

        if (! $canViewCost) {
            return $indicators;
        }

        $returned = (string) DB::table('purchase_returns')->where('company_id', $company->id)->whereBetween('returned_at', [$from, $until])->sum('total_value');

        return [
            ...$indicators,
            'ordered_value' => PurchaseMath::money((string) $effective()->sum('total')),
            'confirmed_open_value' => PurchaseMath::money((string) PurchaseOrder::query()->where('company_id', $company->id)->pending()->sum('total')),
            'received_value' => PurchaseMath::money((string) $receiptItems()->sum('purchase_receipt_items.acquisition_total')),
            'returned_value' => PurchaseMath::money($returned),
            'monthly' => $this->monthly($company, $until),
            'cost_changes' => $this->costChanges($company, $from, $until),
        ];
    }

    /** @return list<array{month: string, ordered: string, received: string}> */
    private function monthly(Company $company, string $until): array
    {
        $end = CarbonImmutable::parse($until)->startOfMonth();
        $start = $end->subMonths(self::MONTHS - 1);
        $lastDay = $end->endOfMonth()->format('Y-m-d');
        $ordered = PurchaseOrder::query()->where('company_id', $company->id)->whereIn('status', PurchaseOrderStatus::effective())
            ->whereBetween('order_date', [$start->format('Y-m-d'), $lastDay])
            ->selectRaw('substr(order_date, 1, 7) as month, sum(total) as value')->groupByRaw('substr(order_date, 1, 7)')
            ->pluck('value', 'month');
        $received = DB::table('purchase_receipts')->where('company_id', $company->id)->where('status', ReceiptStatus::Confirmed->value)
            ->whereBetween('received_at', [$start->format('Y-m-d'), $lastDay])
            ->selectRaw('substr(received_at, 1, 7) as month, sum(total_cost) as value')->groupByRaw('substr(received_at, 1, 7)')
            ->pluck('value', 'month');
        $months = [];

        for ($month = $start; $month <= $end; $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $months[] = [
                'month' => $key,
                'ordered' => PurchaseMath::money((string) ($ordered[$key] ?? '0')),
                'received' => PurchaseMath::money((string) ($received[$key] ?? '0')),
            ];
        }

        return $months;
    }

    /**
     * Variação do custo efetivo unitário entre o último recebimento do período e o recebimento anterior do mesmo item.
     *
     * @return list<array{product_variant_id: int, description: string, unit_code: string, previous: string, current: string, variation: string|null}>
     */
    private function costChanges(Company $company, string $from, string $until): array
    {
        $latestIds = PurchaseReceiptItem::query()->where('purchase_receipt_items.company_id', $company->id)
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_items.purchase_receipt_id')
            ->where('purchase_receipts.status', ReceiptStatus::Confirmed->value)
            ->whereBetween('purchase_receipts.received_at', [$from, $until])
            ->groupBy('purchase_receipt_items.product_variant_id')
            ->selectRaw('max(purchase_receipt_items.id) as id')
            ->orderByDesc('id')->limit(10)->pluck('id');
        $changes = [];

        foreach (PurchaseReceiptItem::query()->whereKey($latestIds)->with('orderItem')->orderByDesc('id')->get() as $latest) {
            $previous = PurchaseReceiptItem::query()->where('company_id', $company->id)->where('product_variant_id', $latest->product_variant_id)
                ->where('id', '<', $latest->id)->whereHas('receipt', fn (Builder $receipt) => $receipt->where('status', ReceiptStatus::Confirmed))
                ->orderByDesc('id')->first();

            if ($previous === null) {
                continue;
            }

            $previousCost = BigDecimal::of($previous->unit_cost);
            $changes[] = [
                'product_variant_id' => $latest->product_variant_id,
                'description' => $latest->orderItem->description,
                'unit_code' => $latest->orderItem->unit_code,
                'previous' => (string) $previousCost->toScale(4, RoundingMode::HalfUp),
                'current' => (string) BigDecimal::of($latest->unit_cost)->toScale(4, RoundingMode::HalfUp),
                'variation' => $previousCost->isZero() ? null : (string) BigDecimal::of($latest->unit_cost)->minus($previousCost)
                    ->multipliedBy(100)->dividedBy($previousCost, 2, RoundingMode::HalfUp),
            ];
        }

        return $changes;
    }
}
