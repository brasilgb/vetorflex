<?php

namespace App\Http\Controllers\Payments;

use App\Modules\Payments\Enums\ChargeOrigin;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Providers\ProviderRegistry;
use App\Modules\Payments\Services\ChargeQueries;
use App\Modules\Payments\Support\PaymentPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visão geral de Pagamentos com indicadores reais, filtráveis por período, situação, provedor e origem.
 */
class PaymentsDashboardController extends PaymentsController
{
    public function __invoke(Request $request, ChargeQueries $queries, PaymentPresenter $presenter, ProviderRegistry $providers): Response
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'provider' => ['nullable', 'string', 'max:30'],
            'origin' => ['nullable', Rule::enum(ChargeOrigin::class)],
        ]);
        $filters = ['from' => $validated['from'] ?? $now->copy()->startOfMonth()->format('Y-m-d'), 'until' => $validated['until'] ?? $now->format('Y-m-d'), 'provider' => $validated['provider'] ?? null, 'origin' => $validated['origin'] ?? null];

        return Inertia::render('payments/dashboard', [
            'indicators' => $queries->dashboard($company, $filters),
            'filters' => $filters,
            'recent' => PaymentCharge::query()->where('company_id', $company->id)->latest('id')->limit(8)->get()->map(fn (PaymentCharge $charge): array => $presenter->charge($charge))->values()->all(),
            'origins' => ChargeOrigin::options(),
            'providers' => array_values(array_map(fn ($provider): array => ['value' => $provider->identifier(), 'label' => $provider->name()], $providers->all())),
            'accounts' => PaymentProviderAccount::query()->where('company_id', $company->id)->get()
                ->map(fn (PaymentProviderAccount $account): array => ['name' => $account->name, 'environment' => $account->environment, 'status' => $account->status->value, 'status_label' => $account->status->label()])->values()->all(),
        ]);
    }
}
