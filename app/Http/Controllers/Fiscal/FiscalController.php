<?php

namespace App\Http\Controllers\Fiscal;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Enums\FiscalPermission;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Base dos controladores de Documentos Fiscais: empresa ativa (nunca a enviada pelo navegador), permissões e avisos.
 */
abstract class FiscalController extends Controller
{
    use InteractsWithCompany;

    protected function can(Request $request, FiscalPermission $permission): bool
    {
        return $this->allows($request, $permission->value);
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
