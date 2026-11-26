<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Queries\CashFlow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fluxo de caixa realizado e previsto. Sem período informado: do início do mês até 60 dias à frente.
 */
class FinanceCashFlowController extends FinanceController
{
    public function __invoke(Request $request, CashFlow $cashFlow): Response
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'granularity' => ['nullable', Rule::in(CashFlow::GRANULARITIES)],
            'account' => ['nullable', 'integer'],
        ]);
        $from = $validated['from'] ?? $now->startOfMonth()->format('Y-m-d');
        $until = $validated['until'] ?? $now->addDays(60)->format('Y-m-d');
        abort_if($now->parse($from)->diffInDays($now->parse($until)) > 731, 422, 'Escolha um período de até dois anos.');
        $accounts = FinanceAccount::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name']);
        $accountId = $accounts->firstWhere('id', (int) ($validated['account'] ?? 0))?->id;

        return Inertia::render('finance/cash-flow', [
            'flow' => $cashFlow->for($company, $from, $until, $validated['granularity'] ?? 'week', $accountId),
            'filters' => ['from' => $from, 'until' => $until, 'granularity' => $validated['granularity'] ?? 'week', 'account' => $accountId],
            'accounts' => $accounts->map(fn (FinanceAccount $account): array => ['id' => $account->id, 'name' => $account->name])->values()->all(),
            'today' => $this->today($request),
        ]);
    }
}
