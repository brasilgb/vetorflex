<?php

namespace App\Http\Controllers;

use App\Modules\Commercial\Models\Signup;
use App\Modules\Commercial\Services\SignupService;
use App\Modules\Commercial\Services\SubscriberOverview;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel inicial. Conclui um cadastro pendente (recuperação segura se o provisionamento falhou), seleciona a empresa
 * automaticamente quando o usuário só tem uma e mostra apenas as aplicações habilitadas.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenants, SignupService $signups, SubscriberOverview $overview): Response|RedirectResponse
    {
        $user = $request->user();
        $provisioned = $signups->provisionPendingFor($user);
        $companies = $tenants->companiesFor($user);
        $active = $tenants->activeCompany($request);

        if ($provisioned !== null && $companies->contains('id', $provisioned->id)) {
            $request->session()->put(TenantContext::SESSION_KEY, $provisioned->id);

            return to_route('dashboard');
        }

        if ($active === null && $companies->count() === 1) {
            $request->session()->put(TenantContext::SESSION_KEY, $companies->first()->id);

            return to_route('dashboard');
        }

        return Inertia::render('dashboard', [
            'overview' => $active === null ? null : $overview->for($active),
            'companies' => $active === null ? array_values($companies->map->only(['id', 'name'])->all()) : [],
            'pendingSignup' => Signup::query()->where('user_id', $user->id)->where('status', Signup::FAILED)->exists(),
            'isRootAdmin' => (bool) $user->is_root_admin,
        ]);
    }
}
