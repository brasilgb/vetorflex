<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Requests\Purchases\StoreQuickPurchaseRequest;
use App\Modules\Purchases\Enums\PaymentCondition;
use App\Modules\Purchases\Enums\PaymentMethod;
use App\Modules\Purchases\Services\QuickPurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registro de compra já realizada: pedido, confirmação e recebimento integral em uma única operação.
 */
class QuickPurchaseController extends PurchaseController
{
    public function create(Request $request): Response
    {
        return Inertia::render('purchases/quick', [
            'conditions' => PaymentCondition::options(),
            'methods' => PaymentMethod::options(),
            'today' => $this->today($request),
            'canManageSuppliers' => $this->allows($request, 'purchases.manage_suppliers'),
        ]);
    }

    public function store(StoreQuickPurchaseRequest $request, QuickPurchaseService $purchases): RedirectResponse
    {
        $data = $request->validated();

        if (isset($data['new_supplier']) && ! $this->allows($request, 'purchases.manage_suppliers')) {
            abort(403);
        }

        $order = $purchases->register($this->company($request), $request->user(), $data);
        $this->toast("Compra #{$order->number} registrada e recebida.");

        return to_route('purchases.orders.show', $order);
    }
}
