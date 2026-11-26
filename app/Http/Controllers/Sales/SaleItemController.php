<?php

namespace App\Http\Controllers\Sales;

use App\Http\Requests\Sales\StoreSaleItemRequest;
use App\Modules\Sales\Services\SaleItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Busca e cadastro simplificado de itens durante a venda, sem exigir acesso ao módulo Produtos.
 * O saldo disponível é exibido somente com o estoque habilitado; o custo, somente com `sales.view_cost`.
 */
class SaleItemController extends SalesController
{
    public function __construct(private SaleItems $items) {}

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['term' => ['required', 'string', 'max:100']]);

        return response()->json($this->items->search($this->company($request), $validated['term'], $this->canViewCost($request)));
    }

    public function store(StoreSaleItemRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $variant = $this->items->createSimplified($company, $request->user(), [
            'name' => $request->string('name')->trim()->value(),
            'type' => $request->string('type')->value(),
            'sale_price' => $request->string('sale_price')->value(),
            'sku' => $request->filled('sku') ? $request->string('sku')->trim()->upper()->value() : null,
            'unit_code' => $request->filled('unit_code') ? $request->string('unit_code')->value() : null,
        ]);

        return response()->json($this->items->summary($company, $variant, null, $this->canViewCost($request)), 201);
    }
}
