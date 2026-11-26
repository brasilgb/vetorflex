<?php

namespace App\Http\Controllers\Finance;

use App\Http\Requests\Finance\FinanceReasonRequest;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Services\FinanceLedger;
use App\Modules\Finance\Services\FinanceReconciliationService;
use App\Modules\Finance\Support\FinancePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extrato por conta para conferência com o extrato da instituição, com marcação manual de conciliação.
 */
class FinanceReconciliationController extends FinanceController
{
    public function __construct(private FinanceReconciliationService $reconciliation, private FinanceLedger $ledger, private FinancePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $validated = $request->validate([
            'account' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'pending' => ['nullable', 'boolean'],
        ]);
        $accounts = FinanceAccount::query()->where('company_id', $company->id)->orderByDesc('is_active')->orderBy('name')->get();
        $account = $accounts->firstWhere('id', (int) ($validated['account'] ?? 0)) ?? $accounts->first();
        $from = $validated['from'] ?? $now->startOfMonth()->format('Y-m-d');
        $until = $validated['until'] ?? $now->format('Y-m-d');
        $onlyPending = (bool) ($validated['pending'] ?? false);

        return Inertia::render('finance/reconciliation', [
            'accounts' => $accounts->map(fn (FinanceAccount $item): array => ['id' => $item->id, 'name' => $item->name])->values()->all(),
            'account' => $account === null ? null : [
                'id' => $account->id,
                'name' => $account->name,
                'opening' => $this->ledger->balanceOf($account, CarbonImmutable::parse($from)->subDay()->format('Y-m-d')),
                'closing' => $this->ledger->balanceOf($account, $until),
                'unreconciled' => FinanceMovement::query()->where('finance_account_id', $account->id)->whereNull('reconciled_at')->count(),
            ],
            'movements' => $account === null ? [] : FinanceMovement::query()->where('finance_account_id', $account->id)->whereBetween('occurred_at', [$from, $until])
                ->when($onlyPending, fn ($query) => $query->whereNull('reconciled_at'))
                ->with(['account', 'category.parent', 'settlement'])->orderBy('occurred_at')->orderBy('id')->limit(500)->get()
                ->map(fn (FinanceMovement $movement): array => $this->presenter->movement($movement))->values()->all(),
            'filters' => ['from' => $from, 'until' => $until, 'pending' => $onlyPending],
        ]);
    }

    public function store(Request $request, FinanceAccount $account): RedirectResponse
    {
        $validated = $request->validate(['movements' => ['required', 'array', 'min:1', 'max:500'], 'movements.*' => ['integer']], ['movements.required' => 'Selecione as movimentações conferidas.']);
        $count = $this->reconciliation->reconcile($account, $request->user(), array_values(array_map('intval', $validated['movements'])));
        $this->toast("{$count} movimentação(ões) conciliada(s).");

        return back();
    }

    public function destroy(FinanceReasonRequest $request, FinanceMovement $movement): RedirectResponse
    {
        $this->reconciliation->undo($movement, $request->user(), $request->validated('reason'));
        $this->toast('Conciliação desfeita.');

        return back();
    }
}
