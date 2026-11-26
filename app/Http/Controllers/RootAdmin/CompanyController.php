<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RootAdmin\StoreCompanyRequest;
use App\Modules\Core\Services\AuditService;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Empresas da plataforma: cadastro, edição, suspensão e reativação. Suspender bloqueia o acesso dos usuários da
 * empresa (as rotas empresariais respondem 404) e preserva todos os dados.
 */
class CompanyController extends Controller
{
    public const STATUSES = ['active', 'suspended'];

    public function index(): Response
    {
        Gate::authorize('viewAny', Company::class);

        return Inertia::render('root-admin/companies', [
            'companies' => Company::query()
                ->with(['moduleAssignments' => fn (HasMany $query) => $query->whereNull('deactivated_at')])
                ->withCount('users')
                ->orderBy('name')->get()
                ->map(fn (Company $company): array => [
                    ...$company->only(['id', 'name', 'slug', 'status', 'timezone']),
                    'users_count' => $company->users_count,
                    'active_modules' => $company->moduleAssignments->pluck('platform_module_id'),
                ]),
            'modules' => PlatformModule::query()->orderBy('sort_order')->get(['id', 'identifier', 'name', 'development_status', 'dependencies', 'optional_integrations']),
        ]);
    }

    public function store(StoreCompanyRequest $request, AuditService $audit): RedirectResponse
    {
        $company = Company::create([...$request->safe()->only(['name', 'slug']), 'timezone' => $request->validated('timezone') ?? 'America/Sao_Paulo', 'status' => 'active']);
        $audit->record('company.created', $company, $request->user(), $company);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Empresa {$company->name} cadastrada. Nenhum módulo foi ativado."]);

        return to_route('root-admin.companies.index');
    }

    public function update(Request $request, Company $company, AuditService $audit): RedirectResponse
    {
        Gate::authorize('update', $company);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('companies', 'slug')->ignore($company->id)],
            'timezone' => ['required', 'timezone'],
        ], ['name.required' => 'Informe o nome da empresa.', 'slug.unique' => 'Este identificador já está em uso.']);
        $before = $company->only(array_keys($validated));
        $company->update($validated);
        $audit->record('company.updated', $company, $request->user(), $company, ['before' => $before, 'after' => $validated]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Empresa atualizada.']);

        return to_route('root-admin.companies.index');
    }

    public function status(Request $request, Company $company, AuditService $audit): RedirectResponse
    {
        Gate::authorize('update', $company);
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'reason' => ['required', 'string', 'max:400'],
        ], ['reason.required' => 'Informe o motivo.']);

        if ($validated['status'] === $company->status) {
            return to_route('root-admin.companies.index');
        }

        $company->update(['status' => $validated['status']]);
        $audit->record($validated['status'] === 'active' ? 'company.reactivated' : 'company.suspended', $company, $request->user(), $company, ['reason' => $validated['reason']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['status'] === 'active' ? 'Empresa reativada.' : 'Empresa suspensa. Os dados foram preservados.']);

        return to_route('root-admin.companies.index');
    }
}
