<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\AuditService;
use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Enums\FiscalStatus;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalProviderAvailability;
use App\Modules\Fiscal\Models\FiscalSetting;
use App\Modules\Fiscal\Models\FiscalWebhookEvent;
use App\Modules\Fiscal\Providers\FiscalProviderException;
use App\Modules\Fiscal\Providers\FiscalProviderRegistry;
use App\Modules\Fiscal\Services\FiscalWebhookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Documentos fiscais na plataforma: provedor, tipos liberados, ambiente e chave da empresa titular (somente escrita,
 * criptografada), registro do webhook, liberação de produção por empresa e indicadores agregados (contagens, sem
 * valores nem dados fiscais das empresas).
 */
class FiscalAdminController extends Controller
{
    public function index(FiscalProviderRegistry $providers): Response
    {
        $providers->ensureRegistered();

        return Inertia::render('root-admin/fiscal', [
            'providers' => FiscalProviderAvailability::query()->orderBy('name')->get()->map(fn (FiscalProviderAvailability $provider): array => [
                'id' => $provider->id,
                'identifier' => $provider->identifier,
                'name' => $provider->name,
                'is_available' => $provider->is_available,
                'document_types' => $provider->document_types ?? [],
                'environment' => $provider->environment,
                'has_owner_key' => $provider->ownerKey() !== null,
                'has_webhook_secret' => $provider->webhookSecret() !== null,
                'webhook_registered_at' => $provider->webhook_registered_at?->toIso8601String(),
                'webhook_url' => route('fiscal.webhook', ['provider' => $provider->identifier]),
                'last_error' => $provider->last_error,
                'capabilities' => isset($providers->all()[$provider->identifier]) ? $providers->get($provider->identifier)->capabilities()->toArray() : [],
            ])->values()->all(),
            'types' => array_map(fn (DocumentType $type): array => ['value' => $type->value, 'label' => $type->label()], DocumentType::cases()),
            'companies' => FiscalSetting::query()->with('company:id,name')->get()->map(fn (FiscalSetting $setting): array => [
                'id' => $setting->id,
                'company' => $setting->company?->name,
                'environment' => $setting->environment,
                'is_registered' => $setting->isRegistered(),
                'registration_error' => $setting->registration_error,
                'certificate_expires_at' => $setting->certificate_expires_at?->toIso8601String(),
                'tax_settings_confirmed' => $setting->tax_settings_confirmed_at !== null,
                'production_released_at' => $setting->production_released_at?->toIso8601String(),
                'documents' => FiscalDocument::query()->where('company_id', $setting->company_id)->count(),
            ])->values()->all(),
            'indicators' => [
                'authorized_last_30_days' => FiscalDocument::query()->where('status', FiscalStatus::Authorized)->where('issued_at', '>=', now()->subDays(30))->count(),
                'rejected' => FiscalDocument::query()->whereIn('status', [FiscalStatus::Rejected, FiscalStatus::Denied])->count(),
                'pending' => FiscalDocument::query()->whereIn('status', [FiscalStatus::Queued, FiscalStatus::Processing, FiscalStatus::CancelRequested, FiscalStatus::CommunicationFailure])->count(),
                'failed_events' => FiscalWebhookEvent::query()->where('status', FiscalWebhookService::FAILED)->count(),
            ],
        ]);
    }

    public function update(Request $request, FiscalProviderAvailability $provider, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
            'document_types' => ['array'],
            'document_types.*' => [Rule::enum(DocumentType::class)],
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'owner_api_key' => ['nullable', 'string', 'max:200'],
        ]);
        $credentials = $provider->platform_credentials ?? [];

        // A chave é somente escrita: em branco mantém a atual. Trocar de ambiente exige nova chave (contas separadas).
        if (filled($validated['owner_api_key'] ?? null)) {
            $credentials = ['owner_api_key' => trim((string) $validated['owner_api_key'])];
        } elseif ($validated['environment'] !== $provider->environment) {
            throw ValidationException::withMessages(['owner_api_key' => 'Sandbox e produção são contas separadas: informe a chave da empresa titular do novo ambiente.']);
        }

        $provider->update([
            'is_available' => (bool) $validated['is_available'],
            'document_types' => array_values($validated['document_types'] ?? []),
            'environment' => $validated['environment'],
            'platform_credentials' => $credentials,
            'webhook_registered_at' => isset($credentials['webhook_secret']) ? $provider->webhook_registered_at : null,
        ]);
        $audit->record('fiscal.provider_updated', null, $request->user(), $provider, ['provider' => $provider->identifier, 'is_available' => $provider->is_available, 'types' => $provider->document_types, 'environment' => $provider->environment, 'key_changed' => filled($validated['owner_api_key'] ?? null)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$provider->name}: configuração salva."]);

        return back();
    }

    public function test(FiscalProviderAvailability $provider, FiscalProviderRegistry $providers): RedirectResponse
    {
        try {
            $providers->get($provider->identifier)->testPlatform();
            $provider->update(['last_error' => null]);
        } catch (FiscalProviderException $exception) {
            $provider->update(['last_error' => Str::limit($exception->getMessage(), 490)]);

            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Conexão com {$provider->name} confirmada."]);

        return back();
    }

    public function webhook(Request $request, FiscalProviderAvailability $provider, FiscalProviderRegistry $providers, AuditService $audit): RedirectResponse
    {
        try {
            $providers->get($provider->identifier)->registerWebhook(route('fiscal.webhook', ['provider' => $provider->identifier]));
        } catch (FiscalProviderException $exception) {
            $provider->update(['last_error' => Str::limit($exception->getMessage(), 490)]);

            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }

        $audit->record('fiscal.webhook_registered', null, $request->user(), $provider, ['provider' => $provider->identifier]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Webhook registrado e segredo de assinatura guardado.']);

        return back();
    }

    /** Libera (ou revoga) a emissão em produção para uma empresa, após a homologação acompanhada. */
    public function release(Request $request, FiscalSetting $setting, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate(['released' => ['required', 'boolean']]);
        $setting->forceFill($validated['released'] ? ['production_released_at' => now(), 'production_released_by' => $request->user()->name] : ['production_released_at' => null, 'production_released_by' => null])->save();
        $audit->record('fiscal.production_release_changed', $setting->company, $request->user(), $setting, ['released' => (bool) $validated['released']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['released'] ? 'Produção liberada para a empresa.' : 'Liberação de produção revogada.']);

        return back();
    }
}
