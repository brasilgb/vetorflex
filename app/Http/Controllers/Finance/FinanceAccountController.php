<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Finance\Enums\AccountType;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Services\FinanceAccountService;
use App\Modules\Finance\Services\FinanceLedger;
use App\Modules\Finance\Support\FinancePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contas financeiras. O saldo é sempre calculado pelo livro; alterações de saldo só por ajuste justificado.
 */
class FinanceAccountController extends FinanceController
{
    public function __construct(private FinanceAccountService $accounts, private FinanceLedger $ledger, private FinancePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $balances = $this->ledger->balances($company->id);

        return Inertia::render('finance/accounts', [
            'accounts' => FinanceAccount::query()->where('company_id', $company->id)->orderByDesc('is_active')->orderBy('name')->get()
                ->map(fn (FinanceAccount $account): array => $this->presenter->account($account, $balances[$account->id] ?? $account->opening_balance))->values()->all(),
            'types' => AccountType::options(),
            'today' => $this->today($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $account = $this->accounts->create($company, $request->user(), $this->validated($request, null));
        $this->toast("Conta {$account->name} cadastrada.");

        return back();
    }

    public function update(Request $request, FinanceAccount $account): RedirectResponse
    {
        $this->accounts->update($account, $request->user(), $this->validated($request, $account));
        $this->toast("Conta {$account->name} atualizada.");

        return back();
    }

    public function toggle(Request $request, FinanceAccount $account): RedirectResponse
    {
        $this->accounts->setActive($account, $request->user(), ! $account->is_active);
        $this->toast($account->is_active ? 'Conta reativada.' : 'Conta inativada. O histórico foi preservado.');

        return back();
    }

    public function adjust(Request $request, FinanceAccount $account): RedirectResponse
    {
        $validated = $request->validate([
            'balance' => ['required', 'regex:/^-?\d{1,13}(\.\d{1,2})?$/'],
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:400'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ], ['balance.required' => 'Informe o saldo correto.', 'balance.regex' => 'Informe um valor válido.', 'reason.required' => 'Informe a justificativa do ajuste.']);
        $this->accounts->adjust($account, $request->user(), $validated['balance'], $validated['date'], $validated['reason'], $validated['idempotency_key']);
        $this->toast('Ajuste de saldo registrado.');

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?FinanceAccount $account): array
    {
        $companyId = $this->company($request)->id;

        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('finance_accounts')->where('company_id', $companyId)->ignore($account?->id)],
            'type' => ['required', Rule::enum(AccountType::class)],
            'institution' => ['nullable', 'string', 'max:120'],
            'opening_balance' => ['required', 'regex:/^-?\d{1,13}(\.\d{1,2})?$/'],
            'opening_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => 'Informe o nome da conta.',
            'name.unique' => 'Já existe uma conta com este nome.',
            'opening_balance.regex' => 'Informe um saldo inicial válido.',
            'opening_date.required' => 'Informe a data do saldo inicial.',
        ]);
    }
}
