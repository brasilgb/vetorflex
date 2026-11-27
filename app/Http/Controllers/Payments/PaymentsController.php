<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\PaymentPermission;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Base dos controladores de Pagamentos: empresa ativa (nunca a enviada pelo navegador), permissões e avisos.
 */
abstract class PaymentsController extends Controller
{
    use InteractsWithCompany;

    protected function can(Request $request, PaymentPermission $permission): bool
    {
        return $this->allows($request, $permission->value);
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
