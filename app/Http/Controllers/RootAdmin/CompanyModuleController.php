<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Services\CompanyProvisioning;
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
        // As dependências obrigatórias são ativadas junto; o período de teste não muda.
        $activated = app(CompanyProvisioning::class)->addApplications($company, $request->user(), [$module->identifier], onlyAvailable: false);
        Inertia::flash('toast', ['type' => 'success', 'message' => count($activated) > 1 ? 'Módulo ativado com as dependências: '.implode(', ', $activated).'.' : 'Módulo ativado para a empresa.']);

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
