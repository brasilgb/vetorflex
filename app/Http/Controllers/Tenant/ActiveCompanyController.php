<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActiveCompanyController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $validated = $request->validate(['company_id' => ['required', 'integer']]);

        abort_unless($tenantContext->authorizedCompany($request->user(), $validated['company_id']) !== null, 404);

        $request->session()->put(TenantContext::SESSION_KEY, $validated['company_id']);

        return back();
    }
}
