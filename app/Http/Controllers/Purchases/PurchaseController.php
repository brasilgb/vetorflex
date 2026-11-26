<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Modules\Purchases\Enums\PurchasePermission;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Base dos controladores de Compras: concentra as regras de exibição e edição de custos.
 */
abstract class PurchaseController extends Controller
{
    use InteractsWithCompany;

    protected function canViewCost(Request $request): bool
    {
        return $this->allows($request, PurchasePermission::ViewCost->value);
    }

    /** Informar custos exige também consultá-los. */
    protected function canManageCost(Request $request): bool
    {
        return $this->canViewCost($request) && $this->allows($request, PurchasePermission::ManageCost->value);
    }

    protected function today(Request $request): string
    {
        return now($this->company($request)->timezone)->format('Y-m-d');
    }

    protected function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
