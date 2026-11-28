<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Services\ApplicationCatalog;
use App\Modules\Commercial\Services\CompanyProvisioning;
use App\Modules\Commercial\Services\TrialService;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aplicações da empresa: as contratadas, as disponíveis no cardápio (com valores configurados) e a inclusão ou
 * remoção pelo proprietário. Dependências são resolvidas no servidor; o período de teste nunca é reiniciado.
 */
class CompanyApplicationController extends Controller
{
    use InteractsWithCompany;

    public function index(Request $request, ApplicationCatalog $catalog, CompanyProvisioning $provisioning, TrialService $trials, TenantContext $tenants): Response
    {
        $company = $this->company($request);
        $active = $provisioning->activeIdentifiers($company);
        $menu = $catalog->menu();
        // Aplicações já habilitadas aparecem mesmo que estejam fora da venda no momento.
        $extra = PlatformModule::query()->whereIn('identifier', array_diff($active, array_column($menu, 'identifier')))->orderBy('sort_order')->get()
            ->map(fn (PlatformModule $module): array => $catalog->present($module))->all();

        return Inertia::render('company/applications', [
            'applications' => [...$menu, ...$extra],
            'active' => $active,
            'subscription' => $trials->summary($company),
            'canManage' => $tenants->isOwner($request),
        ]);
    }

    public function store(Request $request, CompanyProvisioning $provisioning, TenantContext $tenants): RedirectResponse
    {
        abort_unless($tenants->isOwner($request), 403);
        $validated = $request->validate(['modules' => ['required', 'array', 'min:1', 'max:30'], 'modules.*' => ['string', 'max:40']]);
        $activated = $provisioning->addApplications($this->company($request), $request->user(), $validated['modules']);
        Inertia::flash('toast', ['type' => 'success', 'message' => $activated === [] ? 'As aplicações já estavam habilitadas.' : 'Aplicações habilitadas: '.count($activated).'.']);

        return back();
    }

    public function destroy(Request $request, string $module, CompanyProvisioning $provisioning, TenantContext $tenants): RedirectResponse
    {
        abort_unless($tenants->isOwner($request), 403);
        $provisioning->removeApplication($this->company($request), $request->user(), $module);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Aplicação removida. Os dados foram preservados.']);

        return back();
    }
}
