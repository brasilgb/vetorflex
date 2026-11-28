<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RootAdmin\StoreCompanyRequest;
use App\Models\User;
use App\Modules\Commercial\Data\ProvisioningRequest;
use App\Modules\Commercial\Services\CompanyProvisioning;
use App\Modules\Commercial\Services\TrialService;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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

    public function index(Request $request, TrialService $trials): Response
    {
        Gate::authorize('viewAny', Company::class);
        $filter = $request->validate(['commercial' => ['nullable', Rule::in(['trial', 'active', 'expired', 'suspended'])]])['commercial'] ?? null;
        $modules = PlatformModule::query()->orderBy('sort_order')->get(['id', 'identifier', 'name', 'development_status', 'dependencies', 'optional_integrations', 'monthly_price']);
        $companies = Company::query()
            ->with(['moduleAssignments' => fn (HasMany $query) => $query->whereNull('deactivated_at')])
            ->withCount('users')
            ->orderBy('name')->get()
            ->map(function (Company $company) use ($trials, $modules): array {
                $active = $company->moduleAssignments->pluck('platform_module_id');
                $prices = $modules->whereIn('id', $active)->pluck('monthly_price')->filter(fn (mixed $price): bool => $price !== null);

                return [
                    ...$company->only(['id', 'name', 'slug', 'status', 'timezone', 'origin']),
                    'users_count' => $company->users_count,
                    'active_modules' => $active,
                    'subscription' => $trials->summary($company),
                    'contracted_at' => $company->contracted_at?->toIso8601String(),
                    'monthly_total' => $prices->isEmpty() ? null : CommercialMath::money((string) $prices->reduce(fn (BigDecimal $sum, mixed $price): BigDecimal => $sum->plus((string) $price), BigDecimal::zero())),
                ];
            });

        return Inertia::render('root-admin/companies', [
            'companies' => $companies->filter(fn (array $company): bool => match ($filter) {
                null => true,
                'suspended' => $company['status'] === 'suspended',
                default => $company['subscription']['status'] === $filter,
            })->values(),
            'counts' => [
                'trial' => $companies->where('subscription.status', 'trial')->count(),
                'active' => $companies->where('subscription.status', 'active')->count(),
                'expired' => $companies->where('subscription.status', 'expired')->count(),
                'suspended' => $companies->where('status', 'suspended')->count(),
            ],
            'filter' => $filter,
            'modules' => $modules,
            'trialDays' => (int) config('commercial.trial_days'),
        ]);
    }

    /** Cadastro administrativo pelo mesmo serviço do cadastro público: empresa, proprietário, aplicações e teste. */
    public function store(StoreCompanyRequest $request, CompanyProvisioning $provisioning): RedirectResponse
    {
        $owner = null;

        if ($request->filled('owner_email')) {
            $email = Str::lower(trim((string) $request->validated('owner_email')));
            $owner = User::query()->where('email', $email)->first();

            if ($owner === null) {
                // O e-mail é informado pela plataforma; o responsável define a senha pelo link enviado a ele.
                $owner = User::create(['name' => (string) $request->validated('owner_name'), 'email' => $email, 'password' => Str::password(40)]);
                $owner->forceFill(['email_verified_at' => now()])->save();
                Password::sendResetLink(['email' => $email]);
            }
        }

        $trialDays = $request->validated('trial_days');
        $company = $provisioning->provision(new ProvisioningRequest(
            key: 'admin:'.$request->validated('idempotency_key', (string) Str::uuid()),
            owner: $owner,
            actor: $request->user(),
            companyName: (string) $request->validated('name'),
            modules: array_values($request->validated('modules') ?? []),
            document: $request->validated('document'),
            timezone: $request->validated('timezone') ?? 'America/Sao_Paulo',
            trialDays: $trialDays === null ? null : (int) $trialDays,
            origin: 'admin',
            onlyAvailable: false,
            slug: (string) $request->validated('slug'),
        ));
        $count = $company->moduleAssignments()->whereNull('deactivated_at')->count();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Empresa {$company->name} cadastrada".($count === 0 ? '. Nenhum módulo foi ativado.' : " com {$count} aplicação(ões).")]);

        return to_route('root-admin.companies.index');
    }

    public function trial(Request $request, Company $company, TrialService $trials): RedirectResponse
    {
        Gate::authorize('update', $company);
        $validated = $request->validate([
            'trial_ends_at' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:400'],
        ], ['reason.required' => 'Informe a justificativa da prorrogação.']);
        $trials->extend($company, $request->user(), CarbonImmutable::parse($validated['trial_ends_at'], $company->timezone), $validated['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Período de teste prorrogado.']);

        return to_route('root-admin.companies.index');
    }

    public function contract(Request $request, Company $company, TrialService $trials): RedirectResponse
    {
        Gate::authorize('update', $company);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:400']], ['reason.required' => 'Informe a referência da contratação.']);
        $trials->contract($company, $request->user(), $validated['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contratação registrada. Nenhuma cobrança é feita automaticamente.']);

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
