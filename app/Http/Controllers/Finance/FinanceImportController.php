<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Finance\Services\FinanceImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importação assistida de vendas e compras para empresas que contratam o Financeiro depois.
 * Prévia por período, seleção e confirmação; nada é presumido recebido ou pago.
 */
class FinanceImportController extends FinanceController
{
    public function __construct(private FinanceImportService $imports) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $types = $this->imports->availableTypes($company);
        $validated = $request->validate([
            'type' => ['nullable', 'in:sales,purchases'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $type = $validated['type'] ?? null;
        $from = $validated['from'] ?? null;
        $until = $validated['until'] ?? null;
        $hasPreview = $type !== null && $from !== null && $until !== null && in_array($type, $types, true);

        return Inertia::render('finance/import', [
            'types' => $types,
            'filters' => ['type' => $type ?? ($types[0] ?? null), 'from' => $from, 'until' => $until],
            'preview' => $hasPreview ? $this->imports->preview($company, $type, $from, $until) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:sales,purchases'],
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'selected' => ['required', 'array', 'min:1', 'max:500'],
            'selected.*' => ['integer'],
            'settled_externally' => ['array'],
            'settled_externally.*' => ['integer'],
        ], ['selected.required' => 'Selecione ao menos um documento.']);
        $count = $this->imports->import($this->company($request), $request->user(), $validated['type'], $validated['from'], $validated['until'],
            array_values(array_map('intval', $validated['selected'])), array_values(array_map('intval', $validated['settled_externally'] ?? [])));
        $this->toast("{$count} documento(s) importado(s).");

        return back();
    }
}
