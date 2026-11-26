<?php

namespace App\Modules\Purchases\Services;

use App\Modules\Purchases\Enums\ReceiptStatus;
use App\Modules\Purchases\Models\PurchaseReceiptItem;
use App\Modules\Purchases\Models\SupplierProduct;
use App\Modules\Purchases\Models\SupplierProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vínculo entre fornecedor e unidade comercializável. O último custo vem sempre do último recebimento confirmado.
 */
class SupplierProductService
{
    /** @param array<string, mixed> $data */
    public function save(SupplierProfile $supplier, SupplierProduct $link, array $data): SupplierProduct
    {
        $link->fill([...$data, 'company_id' => $supplier->company_id, 'supplier_profile_id' => $supplier->id])->save();

        if ($link->is_preferred) {
            SupplierProduct::query()->where('company_id', $supplier->company_id)->where('product_variant_id', $link->product_variant_id)
                ->whereKeyNot($link->id)->update(['is_preferred' => false]);
        }

        return $link;
    }

    /** Recalcula o último custo de compra após um recebimento ou estorno. */
    public function refreshLastCost(SupplierProfile $supplier, int $variantId): void
    {
        $latest = PurchaseReceiptItem::query()
            ->where('purchase_receipt_items.company_id', $supplier->company_id)
            ->where('purchase_receipt_items.product_variant_id', $variantId)
            ->whereHas('receipt', fn (Builder $receipt) => $receipt->where('status', ReceiptStatus::Confirmed)
                ->whereHas('order', fn (Builder $order) => $order->where('supplier_profile_id', $supplier->id)))
            ->with('receipt')
            ->orderByDesc('id')
            ->first();
        $link = SupplierProduct::query()->firstOrNew(['supplier_profile_id' => $supplier->id, 'product_variant_id' => $variantId], ['company_id' => $supplier->company_id]);

        if ($latest === null && ! $link->exists) {
            return;
        }

        $link->fill([
            'last_cost' => $latest?->purchase_unit_cost,
            'last_purchased_at' => $latest?->receipt->received_at,
        ])->save();
    }
}
