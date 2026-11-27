<?php

namespace App\Http\Controllers\Pos;

use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Finance\Services\FinanceAccountDirectory;
use App\Modules\Pos\Data\PosSettings;
use App\Modules\Pos\Services\PosCatalog;
use App\Modules\Pos\Services\PosModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações do PDV da empresa e produtos favoritos. As contas financeiras só aparecem com o Financeiro habilitado.
 */
class PosSettingsController extends PosController
{
    public function __construct(private PosModule $module, private PosCatalog $catalog, private FinanceAccountDirectory $accounts) {}

    public function edit(Request $request): Response
    {
        $company = $this->company($request);
        $financeEnabled = $this->module->isEnabled($company, 'finance');

        return Inertia::render('pos/settings', [
            'settings' => $this->module->settings($company)->toArray(),
            'methods' => PaymentMethod::options(),
            'financeEnabled' => $financeEnabled,
            'accounts' => $financeEnabled ? $this->accounts->activeAccounts($company) : [],
            'favorites' => $this->catalog->favorites($company),
            'catalog' => $this->catalog->sellableOptions($company),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $validated = $request->validate([
            'allow_anonymous' => ['required', 'boolean'],
            'max_discount_percent' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'discount_requires_authorization' => ['required', 'boolean'],
            'allow_price_change' => ['required', 'boolean'],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => [Rule::enum(PaymentMethod::class)],
            'blind_close' => ['required', 'boolean'],
            'print_receipt' => ['required', 'boolean'],
            'receipt_header' => ['nullable', 'string', 'max:200'],
            'receipt_footer' => ['nullable', 'string', 'max:300'],
            'finance_accounts' => ['nullable', 'array'],
            'finance_accounts.*' => ['nullable', 'integer'],
            'cash_account_id' => ['nullable', 'integer'],
            'treasury_account_id' => ['nullable', 'integer', 'different:cash_account_id'],
        ], ['payment_methods.required' => 'Habilite ao menos uma forma de pagamento.', 'treasury_account_id.different' => 'A conta de destino da sangria deve ser diferente da conta do caixa.']);
        $accounts = array_values(array_filter([...array_values($validated['finance_accounts'] ?? []), $validated['cash_account_id'] ?? null, $validated['treasury_account_id'] ?? null]));

        if ($accounts !== [] && ! $this->accounts->belongToCompany($company, array_map('intval', $accounts))) {
            throw ValidationException::withMessages(['finance_accounts' => 'Selecione contas financeiras desta empresa.']);
        }

        $this->module->saveSettings($company, $request->user(), PosSettings::from([
            ...$validated,
            'finance_accounts' => array_intersect_key(array_filter($validated['finance_accounts'] ?? []), array_flip(array_column(PaymentMethod::cases(), 'value'))),
        ]));
        $this->toast('Configurações do PDV salvas.');

        return back();
    }

    public function favorites(Request $request): RedirectResponse
    {
        $validated = $request->validate(['favorites' => ['present', 'array', 'max:24'], 'favorites.*' => ['integer']]);
        $this->catalog->saveFavorites($this->company($request), $request->user(), $validated['favorites']);
        $this->toast('Favoritos atualizados.');

        return back();
    }
}
