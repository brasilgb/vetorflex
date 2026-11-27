<?php

namespace App\Http\Controllers\Inventory\Stock;

use App\Modules\Inventory\Enums\InventoryPermission;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\StockModule;
use App\Modules\Inventory\Services\StockReports;
use App\Modules\Inventory\Support\StockPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visão geral do estoque com indicadores reais do período.
 */
class StockDashboardController extends StockController
{
    public function __invoke(Request $request, StockReports $reports, StockPresenter $presenter, StockModule $module): Response
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $filters = ['from' => $filters['from'] ?? $now->copy()->startOfMonth()->format('Y-m-d'), 'until' => $filters['until'] ?? $now->format('Y-m-d'), 'warehouse' => null, 'days' => (int) config('inventory.idle_days')];
        $canViewCost = $this->can($request, InventoryPermission::ViewCost);

        return Inertia::render('inventory/dashboard', [
            'indicators' => $reports->dashboard($company, $filters, $canViewCost),
            'filters' => ['from' => $filters['from'], 'until' => $filters['until']],
            'idleDays' => $filters['days'],
            'recent' => InventoryMovement::query()->where('company_id', $company->id)->with(['variant.product.unit', 'warehouse', 'reversal'])->latest('occurred_at')->latest('id')->limit(10)->get()
                ->map(fn (InventoryMovement $movement): array => $presenter->movement($movement, $canViewCost))->values()->all(),
            'startedAt' => $module->settings($company)['started_at'],
        ]);
    }
}
