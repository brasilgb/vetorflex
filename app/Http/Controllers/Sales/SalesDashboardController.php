<?php

namespace App\Http\Controllers\Sales;

use App\Modules\Sales\Enums\SalePermission;
use App\Modules\Sales\Services\SaleIndicators;
use App\Modules\Sales\Services\SalesStock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visão geral de Vendas. Sem período informado, considera o mês corrente. Indicadores exigem permissão própria.
 */
class SalesDashboardController extends SalesController
{
    public function __invoke(Request $request, SaleIndicators $indicators, SalesStock $stock): Response
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $validated['from'] ?? $now->startOfMonth()->format('Y-m-d');
        $until = $validated['until'] ?? $now->format('Y-m-d');
        $canView = $this->allows($request, SalePermission::ViewIndicators->value);

        return Inertia::render('sales/dashboard', [
            'indicators' => $canView ? $indicators->for($company, $from, $until, $this->canViewMargin($request)) : null,
            'period' => ['from' => $from, 'until' => $until],
            'stockEnabled' => $stock->isEnabled($company),
        ]);
    }
}
