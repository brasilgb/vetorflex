<?php

namespace App\Http\Controllers\Purchases;

use App\Modules\Purchases\Services\PurchaseIndicators;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visão geral de Compras. Sem período informado, considera o mês corrente.
 */
class PurchaseDashboardController extends PurchaseController
{
    public function __invoke(Request $request, PurchaseIndicators $indicators): Response
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $validated['from'] ?? $now->startOfMonth()->format('Y-m-d');
        $until = $validated['until'] ?? $now->format('Y-m-d');

        return Inertia::render('purchases/dashboard', [
            'indicators' => $indicators->for($company, $from, $until, $this->canViewCost($request)),
            'hasFinance' => false,
        ]);
    }
}
