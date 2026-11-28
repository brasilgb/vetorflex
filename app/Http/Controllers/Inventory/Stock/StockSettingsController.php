<?php

namespace App\Http\Controllers\Inventory\Stock;

use App\Modules\Inventory\Services\StockModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações do Estoque: estoque negativo (padrão: recusado) e data de início do controle.
 */
class StockSettingsController extends StockController
{
    public function __construct(private StockModule $module) {}

    public function edit(Request $request): Response
    {
        $company = $this->company($request);

        return Inertia::render('inventory/settings', [
            'settings' => $this->module->settings($company),
            'integrations' => collect(['products' => 'Produtos', 'purchases' => 'Compras', 'sales' => 'Vendas', 'pos' => 'PDV', 'finance' => 'Financeiro'])
                ->map(fn (string $name, string $identifier): array => ['identifier' => $identifier, 'name' => $name, 'enabled' => $this->module->hasModule($company, $identifier)])->values()->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'allow_negative' => ['required', 'boolean'],
            'started_at' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $this->module->saveSettings($this->company($request), $request->user(), ['allow_negative' => (bool) $validated['allow_negative'], 'started_at' => $validated['started_at'] ?? null]);
        $this->toast('Configurações do estoque salvas.');

        return back();
    }
}
