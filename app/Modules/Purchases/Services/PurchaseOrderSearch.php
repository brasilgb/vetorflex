<?php

namespace App\Modules\Purchases\Services;

use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filtros comuns à listagem e à exportação de pedidos.
 */
class PurchaseOrderSearch
{
    public const FILTERS = ['search', 'supplier', 'status', 'situation', 'product', 'from', 'until'];

    /** @return Builder<PurchaseOrder> */
    public function query(Company $company, Request $request): Builder
    {
        $today = now($company->timezone)->format('Y-m-d');
        $search = $request->string('search')->trim()->value();
        $status = PurchaseOrderStatus::tryFrom((string) $request->input('status'));

        return PurchaseOrder::query()->where('purchase_orders.company_id', $company->id)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $document = BrazilianDocument::normalize($search);
                $query->where(fn (Builder $query) => $query
                    ->when(ctype_digit(ltrim($search, '#')), fn (Builder $query) => $query->orWhere('number', (int) ltrim($search, '#')))
                    ->orWhere('external_reference', 'like', "%{$search}%")
                    ->orWhereHas('supplier.party', fn (Builder $party) => $party->where('name', 'like', "%{$search}%")
                        ->orWhere('trade_name', 'like', "%{$search}%")
                        ->when($document !== null, fn (Builder $party) => $party->orWhere('document', $document))));
            })
            ->when($request->integer('supplier') > 0, fn (Builder $query) => $query->where('supplier_profile_id', $request->integer('supplier')))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($request->input('situation') === 'late', fn (Builder $query) => $query->late($today))
            ->when($request->input('situation') === 'pending', fn (Builder $query) => $query->pending())
            ->when($request->integer('product') > 0, fn (Builder $query) => $query->whereHas('items', fn (Builder $items) => $items->where('product_variant_id', $request->integer('product'))))
            ->when($this->date($request->input('from')), fn (Builder $query, string $from) => $query->where('order_date', '>=', $from))
            ->when($this->date($request->input('until')), fn (Builder $query, string $until) => $query->where('order_date', '<=', $until));
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
