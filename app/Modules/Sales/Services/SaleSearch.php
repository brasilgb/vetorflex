<?php

namespace App\Modules\Sales\Services;

use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SaleOrigin;
use App\Modules\Sales\Enums\SaleQuoteStatus;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filtros comuns às listagens e à exportação de vendas e orçamentos.
 */
class SaleSearch
{
    public const ORDER_FILTERS = ['search', 'customer', 'seller', 'status', 'product', 'origin', 'payment_method', 'from', 'until'];

    public const QUOTE_FILTERS = ['search', 'customer', 'seller', 'status', 'product', 'from', 'until'];

    /** @return Builder<SaleOrder> */
    public function orders(Company $company, Request $request): Builder
    {
        $status = SaleOrderStatus::tryFrom((string) $request->input('status'));
        $origin = SaleOrigin::tryFrom((string) $request->input('origin'));
        $method = PaymentMethod::tryFrom((string) $request->input('payment_method'));
        $query = SaleOrder::query()->where('sale_orders.company_id', $company->id)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($origin !== null, fn (Builder $query) => $query->where('origin', $origin))
            ->when($method !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('payment_method', $method)
                ->orWhereHas('paymentSchedules', fn (Builder $schedules) => $schedules->where('method', $method))))
            ->when($this->date($request->input('from')), fn (Builder $query, string $from) => $query->where('order_date', '>=', $from))
            ->when($this->date($request->input('until')), fn (Builder $query, string $until) => $query->where('order_date', '<=', $until));

        return $this->common($query, $request);
    }

    /** @return Builder<SaleQuote> */
    public function quotes(Company $company, Request $request): Builder
    {
        $status = SaleQuoteStatus::tryFrom((string) $request->input('status'));
        $query = SaleQuote::query()->where('sale_quotes.company_id', $company->id)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($this->date($request->input('from')), fn (Builder $query, string $from) => $query->where('issue_date', '>=', $from))
            ->when($this->date($request->input('until')), fn (Builder $query, string $until) => $query->where('issue_date', '<=', $until));

        return $this->common($query, $request);
    }

    /**
     * @template TModel of SaleOrder|SaleQuote
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function common(Builder $query, Request $request): Builder
    {
        $search = $request->string('search')->trim()->value();

        return $query
            ->when($search !== '', function (Builder $query) use ($search): void {
                $document = BrazilianDocument::normalize($search);
                $query->where(fn (Builder $query) => $query
                    ->when(ctype_digit(ltrim($search, '#')), fn (Builder $query) => $query->orWhere('number', (int) ltrim($search, '#')))
                    ->orWhereHas('customer', fn (Builder $party) => $party->where('name', 'like', "%{$search}%")
                        ->orWhere('trade_name', 'like', "%{$search}%")
                        ->when($document !== null, fn (Builder $party) => $party->orWhere('document', $document))));
            })
            ->when($request->integer('customer') > 0, fn (Builder $query) => $query->where('customer_party_id', $request->integer('customer')))
            ->when($request->input('customer') === 'anonymous', fn (Builder $query) => $query->whereNull('customer_party_id'))
            ->when($request->integer('seller') > 0, fn (Builder $query) => $query->where('seller_id', $request->integer('seller')))
            ->when($request->integer('product') > 0, fn (Builder $query) => $query->whereHas('items', fn (Builder $items) => $items->where('product_variant_id', $request->integer('product'))));
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
