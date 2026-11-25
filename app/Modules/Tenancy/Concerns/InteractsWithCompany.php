<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;

trait InteractsWithCompany
{
    protected function company(Request $request): Company
    {
        $company = $request->attributes->get('company');

        abort_unless($company instanceof Company, 403);

        return $company;
    }

    protected function allows(Request $request, string $permission): bool
    {
        return app(TenantContext::class)->allows($request, $permission);
    }
}
