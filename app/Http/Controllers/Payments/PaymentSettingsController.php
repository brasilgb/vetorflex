<?php

namespace App\Http\Controllers\Payments;

use App\Modules\Finance\Services\FinanceAccountDirectory;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Models\PaymentProviderAvailability;
use App\Modules\Payments\Providers\ProviderRegistry;
use App\Modules\Payments\Services\PaymentsModule;
use App\Modules\Payments\Services\ProviderAccountService;
use App\Modules\Payments\Support\PaymentPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuração das contas da empresa nos provedores: credenciais (nunca devolvidas à tela), certificado, ambiente,
 * chave Pix, formas habilitadas, vencimento, teste de conexão, registro do webhook e últimas falhas.
 */
class PaymentSettingsController extends PaymentsController
{
    public function __construct(private ProviderAccountService $accounts, private PaymentPresenter $presenter) {}

    public function edit(Request $request, ProviderRegistry $providers, PaymentsModule $module, FinanceAccountDirectory $financeAccounts): Response
    {
        $company = $this->company($request);
        $providers->ensureRegistered();
        $financeEnabled = $module->isEnabled($company, 'finance');

        return Inertia::render('payments/settings', [
            'accounts' => PaymentProviderAccount::query()->where('company_id', $company->id)->orderBy('provider')->orderByDesc('environment')->get()
                ->map(fn (PaymentProviderAccount $account): array => $this->presenter->account($account, $this->accounts->webhookUrl($account)))->values()->all(),
            'providers' => array_values(array_map(fn ($provider): array => [
                'identifier' => $provider->identifier(),
                'name' => $provider->name(),
                'capabilities' => $provider->capabilities()->toArray(),
                'is_available' => $providers->isAvailable($provider->identifier()),
            ], $providers->all())),
            'financeEnabled' => $financeEnabled,
            'financeAccounts' => $financeEnabled ? $financeAccounts->activeAccounts($company) : [],
            'unavailable' => PaymentProviderAvailability::query()->where('is_available', false)->pluck('name')->all(),
        ]);
    }

    public function update(Request $request, FinanceAccountDirectory $financeAccounts): RedirectResponse
    {
        $company = $this->company($request);
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:30'],
            'environment' => ['required', Rule::in(['homologation', 'production'])],
            'name' => ['nullable', 'string', 'max:80'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'certificate' => ['nullable', 'file', 'max:64', 'extensions:p12,pem'],
            'certificate_password' => ['nullable', 'string', 'max:255'],
            'pix_key' => ['nullable', 'string', 'max:120'],
            'methods' => ['nullable', 'array'],
            'methods.*' => ['string', 'in:pix,boleto,credit_card'],
            'expiration_seconds' => ['required', 'integer', 'min:300', 'max:2592000'],
            'finance_account_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['certificate.extensions' => 'Envie o certificado no formato .p12 ou .pem.', 'expiration_seconds.min' => 'A validade mínima do Pix é de 5 minutos.']);

        if (isset($validated['finance_account_id']) && ! $financeAccounts->belongToCompany($company, [(int) $validated['finance_account_id']])) {
            throw ValidationException::withMessages(['finance_account_id' => 'Selecione uma conta financeira desta empresa.']);
        }

        $account = $this->accounts->save($company, $request->user(), $validated, $request->file('certificate'));
        $this->toast("Configuração de {$account->name} salva. {$account->status->label()}.");

        return back();
    }

    public function test(Request $request, PaymentProviderAccount $account): RedirectResponse
    {
        abort_unless($account->company_id === $this->company($request)->id, 404);
        $account = $this->accounts->test($account, $request->user());
        $account->last_error === null ? $this->toast('Conexão com o provedor confirmada.') : $this->toast("Falha na conexão: {$account->last_error}", 'error');

        return back();
    }

    public function webhook(Request $request, PaymentProviderAccount $account): RedirectResponse
    {
        abort_unless($account->company_id === $this->company($request)->id, 404);
        $this->accounts->registerWebhook($account, $request->user());
        $this->toast('Webhook registrado no provedor.');

        return back();
    }
}
