<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\AuditService;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\Payments\Enums\AccountStatus;
use App\Modules\Payments\Enums\WebhookStatus;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Models\PaymentProviderAvailability;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Providers\ProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pagamentos na plataforma: disponibilidade dos provedores, indicadores operacionais agregados (somente contagens, sem
 * valores financeiros das empresas), integrações com falha e eventos técnicos sem conteúdo nem credenciais.
 */
class PaymentsAdminController extends Controller
{
    public function index(ProviderRegistry $providers): Response
    {
        $providers->ensureRegistered();

        return Inertia::render('root-admin/payments', [
            'providers' => PaymentProviderAvailability::query()->orderBy('name')->get()->map(fn (PaymentProviderAvailability $provider): array => [
                'id' => $provider->id,
                'identifier' => $provider->identifier,
                'name' => $provider->name,
                'is_available' => $provider->is_available,
                'capabilities' => isset($providers->all()[$provider->identifier]) ? $providers->get($provider->identifier)->capabilities()->toArray() : [],
                'accounts' => PaymentProviderAccount::query()->where('provider', $provider->identifier)->count(),
            ])->values()->all(),
            'indicators' => [
                'companies' => CompanyModule::query()->whereNull('deactivated_at')->whereHas('module', fn ($query) => $query->where('identifier', 'payments'))->count(),
                'active_accounts' => PaymentProviderAccount::query()->where('status', AccountStatus::Active)->count(),
                'failing_accounts' => PaymentProviderAccount::query()->where('status', AccountStatus::Failing)->count(),
                'charges_last_30_days' => PaymentCharge::query()->where('created_at', '>=', now()->subDays(30))->count(),
                'failed_events' => PaymentWebhookEvent::query()->where('status', WebhookStatus::Failed)->count(),
            ],
            'failing' => PaymentProviderAccount::query()->with('company:id,name')->where(fn ($query) => $query->where('status', AccountStatus::Failing)->orWhereNotNull('last_error'))
                ->latest('updated_at')->limit(50)->get()->map(fn (PaymentProviderAccount $account): array => [
                    'id' => $account->id,
                    'company' => $account->company?->name,
                    'provider' => $account->provider,
                    'environment' => $account->environment,
                    'status_label' => $account->status->label(),
                    'last_error' => $account->last_error,
                    'updated_at' => $account->updated_at?->toIso8601String(),
                ])->values()->all(),
            'events' => PaymentWebhookEvent::query()->with('company:id,name')->latest('id')->limit(50)->get()->map(fn (PaymentWebhookEvent $event): array => [
                'id' => $event->id,
                'company' => $event->company?->name,
                'provider' => $event->provider,
                'status_label' => $event->status->label(),
                'status' => $event->status->value,
                'attempts' => $event->attempts,
                'error' => $event->error,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function update(Request $request, PaymentProviderAvailability $provider, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate(['is_available' => ['required', 'boolean']]);
        $provider->update(['is_available' => (bool) $validated['is_available']]);
        $audit->record('payments.provider_availability_changed', null, $request->user(), $provider, ['provider' => $provider->identifier, 'is_available' => $provider->is_available]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$provider->name} ".($provider->is_available ? 'disponível' : 'indisponível').' para as empresas.']);

        return back();
    }
}
