<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RootAdmin\StoreCompanyRequest;
use App\Modules\Core\Services\AuditService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Company::class);

        return Inertia::render('root-admin/companies', [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'slug', 'status', 'timezone']),
        ]);
    }

    public function store(StoreCompanyRequest $request, AuditService $audit): RedirectResponse
    {
        $company = Company::create($request->safe()->only(['name', 'slug', 'timezone']));
        $audit->record('company.created', $company, $request->user(), $company);

        return to_route('root-admin.companies.index');
    }
}
