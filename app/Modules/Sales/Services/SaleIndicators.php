<?php

namespace App\Modules\Sales\Services;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Sales\Enums\FulfillmentStatus;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SaleQuoteStatus;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores calculados a partir dos documentos de venda. Valor vendido não é valor recebido: recebimentos
 * pertencem ao Financeiro e não são apresentados.
 */
class SaleIndicators
{
    private const RANKING_SIZE = 5;

    private const MONTHS = 12;

    /** @return array<string, mixed> */
    public function for(Company $company, string $from, string $until, bool $canViewMargin): array
    {
        $effective = fn (): Builder => SaleOrder::query()->where('sale_orders.company_id', $company->id)
            ->whereIn('sale_orders.status', SaleOrderStatus::effective())->whereBetween('sale_orders.order_date', [$from, $until]);
        $quotes = fn (): Builder => SaleQuote::query()->where('company_id', $company->id)->whereNotNull('issued_at')->whereBetween('issue_date', [$from, $until]);
        $count = $effective()->count();
        $sold = BigDecimal::of((string) ($effective()->sum('total') ?: '0'));
        $issued = $quotes()->count();
        $approved = $quotes()->whereIn('status', [SaleQuoteStatus::Approved, SaleQuoteStatus::Converted])->count();
        $converted = $quotes()->where('status', SaleQuoteStatus::Converted)->count();
        $returned = (string) DB::table('sale_returns')->where('company_id', $company->id)->whereBetween('returned_at', [$from, $until])->sum('total_value');

        return [
            'period' => ['from' => $from, 'until' => $until],
            'sold_value' => CommercialMath::money($sold),
            'orders_count' => $count,
            'average_ticket' => $count === 0 ? null : (string) $sold->dividedBy($count, 2, RoundingMode::HalfUp),
            'returned_value' => CommercialMath::money($returned ?: '0'),
            'quotes_issued' => $issued,
            'quotes_approved' => $approved,
            'conversion_rate' => $issued === 0 ? null : (string) BigDecimal::of($converted)->multipliedBy(100)->dividedBy($issued, 2, RoundingMode::HalfUp),
            'pending_count' => SaleOrder::query()->where('company_id', $company->id)->pending()->count(),
            'completed_count' => SaleOrder::query()->where('company_id', $company->id)->where('status', SaleOrderStatus::Completed)->whereBetween('order_date', [$from, $until])->count(),
            'cancelled_count' => SaleOrder::query()->where('company_id', $company->id)->where('status', SaleOrderStatus::Cancelled)->whereBetween('order_date', [$from, $until])->count(),
            'top_products' => $effective()->join('sale_order_items', 'sale_order_items.sale_order_id', '=', 'sale_orders.id')
                ->groupBy('sale_order_items.product_variant_id', 'sale_order_items.description', 'sale_order_items.unit_code')
                ->selectRaw('sale_order_items.product_variant_id, sale_order_items.description, sale_order_items.unit_code, sum(sale_order_items.quantity) as quantity, sum(sale_order_items.total) as value')
                ->orderByDesc('value')->orderBy('sale_order_items.description')->limit(self::RANKING_SIZE)->toBase()->get()
                ->map(fn (object $row): array => [
                    'product_variant_id' => $row->product_variant_id === null ? null : (int) $row->product_variant_id,
                    'description' => $row->description,
                    'unit_code' => $row->unit_code,
                    'quantity' => (string) BigDecimal::of($row->quantity)->toScale(4, RoundingMode::HalfUp),
                    'value' => CommercialMath::money((string) $row->value),
                ])->values()->all(),
            'top_customers' => $effective()->whereNotNull('customer_party_id')->groupBy('customer_party_id')
                ->selectRaw('customer_party_id, count(*) as orders, sum(total) as value')
                ->orderByDesc('value')->orderBy('customer_party_id')->limit(self::RANKING_SIZE)->toBase()->get()
                ->map(fn (object $row): array => [
                    'customer_party_id' => (int) $row->customer_party_id,
                    'name' => SaleOrder::query()->where('company_id', $company->id)->where('customer_party_id', $row->customer_party_id)->latest('id')->first()?->customer_snapshot['name'] ?? '',
                    'orders' => (int) $row->orders,
                    'value' => CommercialMath::money((string) $row->value),
                ])->values()->all(),
            'by_seller' => $effective()->groupBy('seller_id', 'seller_name')
                ->selectRaw('seller_id, seller_name, count(*) as orders, sum(total) as value')
                ->orderByDesc('value')->orderBy('seller_name')->toBase()->get()
                ->map(fn (object $row): array => [
                    'seller_id' => $row->seller_id === null ? null : (int) $row->seller_id,
                    'name' => $row->seller_name ?? 'Sem responsável',
                    'orders' => (int) $row->orders,
                    'value' => CommercialMath::money((string) $row->value),
                ])->values()->all(),
            'monthly' => $this->monthly($company, $until),
            'margin' => $canViewMargin ? $this->margin($company, $from, $until) : null,
        ];
    }

    /** @return list<array{month: string, sold: string, orders: int}> */
    private function monthly(Company $company, string $until): array
    {
        $end = CarbonImmutable::parse($until)->startOfMonth();
        $start = $end->subMonths(self::MONTHS - 1);
        $rows = SaleOrder::query()->where('company_id', $company->id)->effective()
            ->whereBetween('order_date', [$start->format('Y-m-d'), $end->endOfMonth()->format('Y-m-d')])
            ->selectRaw('substr(order_date, 1, 7) as month, sum(total) as value, count(*) as orders')->groupByRaw('substr(order_date, 1, 7)')
            ->toBase()->get()->keyBy('month');
        $months = [];

        for ($month = $start; $month <= $end; $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $months[] = ['month' => $key, 'sold' => CommercialMath::money((string) ($rows[$key]->value ?? '0')), 'orders' => (int) ($rows[$key]->orders ?? 0)];
        }

        return $months;
    }

    /**
     * Margem bruta comercial dos atendimentos do período, menos as devoluções, com o custo histórico.
     * Itens sem custo confiável ficam fora do cálculo e são informados à parte.
     *
     * @return array{revenue: string, cost: string, margin: string, percent: string|null, items_without_cost: int}
     */
    private function margin(Company $company, string $from, string $until): array
    {
        $items = DB::table('sale_fulfillment_items')->join('sale_fulfillments', 'sale_fulfillments.id', '=', 'sale_fulfillment_items.sale_fulfillment_id')
            ->where('sale_fulfillments.company_id', $company->id)->where('sale_fulfillments.status', FulfillmentStatus::Confirmed->value)
            ->whereBetween('sale_fulfillments.fulfilled_at', [$from, $until]);
        $withCost = (clone $items)->whereNotNull('sale_fulfillment_items.cost_total');
        $returns = DB::table('sale_return_items')->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_returns.company_id', $company->id)->whereBetween('sale_returns.returned_at', [$from, $until])
            ->whereNotNull('sale_return_items.cost_value');
        $revenue = BigDecimal::of((string) ($withCost->sum('sale_fulfillment_items.revenue') ?: '0'))->minus((string) ((clone $returns)->sum('sale_return_items.historical_value') ?: '0'));
        $cost = BigDecimal::of((string) ((clone $withCost)->sum('sale_fulfillment_items.cost_total') ?: '0'))->minus((string) ($returns->sum('sale_return_items.cost_value') ?: '0'));
        $margin = $revenue->minus($cost);

        return [
            'revenue' => CommercialMath::money($revenue),
            'cost' => CommercialMath::money($cost),
            'margin' => CommercialMath::money($margin),
            'percent' => $revenue->isPositive() ? (string) $margin->multipliedBy(100)->dividedBy($revenue, 2, RoundingMode::HalfUp) : null,
            'items_without_cost' => (clone $items)->whereNull('sale_fulfillment_items.cost_total')->count(),
        ];
    }
}
