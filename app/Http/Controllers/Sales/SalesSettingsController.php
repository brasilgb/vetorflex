<?php

namespace App\Http\Controllers\Sales;

use App\Modules\Core\Services\AuditService;
use App\Modules\Sales\Services\SaleDocumentBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações de Vendas da empresa. Venda sem identificação do cliente fica em
 * `companies.settings['sales']['allow_anonymous_customer']`; sem valor gravado, vale o padrão de `config/sales.php`.
 */
class SalesSettingsController extends SalesController
{
    public function edit(Request $request, SaleDocumentBuilder $builder): Response
    {
        return Inertia::render('sales/settings', [
            'settings' => ['allow_anonymous_customer' => $builder->allowsAnonymous($this->company($request))],
        ]);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate(['allow_anonymous_customer' => ['required', 'boolean']]);
        $company = $this->company($request);
        $company->update(['settings' => [...($company->settings ?? []), 'sales' => [...($company->settings['sales'] ?? []), 'allow_anonymous_customer' => (bool) $validated['allow_anonymous_customer']]]]);
        $audit->record('sales.settings_updated', $company, $request->user(), $company, $validated);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Configurações de Vendas salvas.']);

        return back();
    }
}
