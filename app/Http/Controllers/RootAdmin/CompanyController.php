<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RootAdmin\StoreCompanyRequest;
use App\Modules\Core\Services\AuditService;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Company::class);

        return Inertia::render('root-admin/companies', [
            'companies' => Company::query()
                ->with(['moduleAssignments' => fn (HasMany $query) => $query->whereNull('deactivated_at')])
                ->orderBy('name')->get()
                ->map(fn (Company $company): array => [
                    ...$company->only(['id', 'name', 'slug', 'status', 'timezone']),
                    'active_modules' => $company->moduleAssignments->pluck('platform_module_id'),
                ]),
            'modules' => PlatformModule::query()->orderBy('sort_order')->get(['id', 'identifier', 'name', 'development_status', 'dependencies']),
        ]);
    }

    public function store(StoreCompanyRequest $request, AuditService $audit): RedirectResponse
    {
        $company = Company::create($request->safe()->only(['name', 'slug', 'timezone']));
        $audit->record('company.created', $company, $request->user(), $company);

        return to_route('root-admin.companies.index');
    }
}
