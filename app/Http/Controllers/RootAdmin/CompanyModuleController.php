<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\ModuleManager\Services\ModuleActivationService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Autorização de módulos por empresa. Desativar preserva todos os dados do módulo.
 */
class CompanyModuleController extends Controller
{
    public function __construct(private ModuleActivationService $activation) {}

    public function store(Request $request, Company $company, PlatformModule $module): RedirectResponse
    {
        Gate::authorize('update', $company);
        $this->activation->activate($company, $module, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Módulo ativado para a empresa.']);

        return to_route('root-admin.companies.index');
    }

    public function destroy(Request $request, Company $company, PlatformModule $module): RedirectResponse
    {
        Gate::authorize('update', $company);
        $this->activation->deactivate($company, $module, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Módulo desativado. Os dados foram preservados.']);

        return to_route('root-admin.companies.index');
    }
}
