<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Queries\FinanceDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visão geral financeira. Sem período informado, considera o mês corrente.
 */
class FinanceDashboardController extends FinanceController
{
    public function __invoke(Request $request, FinanceDashboard $dashboard): Response
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account' => ['nullable', 'integer'],
        ]);
        $accountId = isset($validated['account']) && FinanceAccount::query()->where('company_id', $company->id)->whereKey($validated['account'])->exists() ? (int) $validated['account'] : null;

        return Inertia::render('finance/dashboard', [
            'dashboard' => $dashboard->for($company, $validated['from'] ?? $now->startOfMonth()->format('Y-m-d'), $validated['until'] ?? $now->format('Y-m-d'), $accountId),
            'today' => $this->today($request),
        ]);
    }
}
