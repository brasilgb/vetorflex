<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Requests\Purchases\SaveSupplierProductRequest;
use App\Modules\Purchases\Models\SupplierProduct;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Purchases\Services\SupplierProductService;
use Illuminate\Http\RedirectResponse;

/**
 * Vínculos entre fornecedor e unidade comercializável. Excluir um vínculo não apaga o histórico de compras.
 */
class SupplierProductController extends PurchaseController
{
    public function __construct(private SupplierProductService $links) {}

    public function store(SaveSupplierProductRequest $request, SupplierProfile $supplier): RedirectResponse
    {
        $this->links->save($supplier, new SupplierProduct, $request->validated());
        $this->toast('Item vinculado ao fornecedor.');

        return back();
    }

    public function update(SaveSupplierProductRequest $request, SupplierProfile $supplier, SupplierProduct $link): RedirectResponse
    {
        abort_unless($link->supplier_profile_id === $supplier->id, 404);
        $this->links->save($supplier, $link, $request->validated());
        $this->toast('Vínculo atualizado.');

        return back();
    }

    public function destroy(SupplierProfile $supplier, SupplierProduct $link): RedirectResponse
    {
        abort_unless($link->supplier_profile_id === $supplier->id, 404);
        $link->delete();
        $this->toast('Vínculo removido.');

        return back();
    }
}
