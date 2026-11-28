<?php

namespace App\Http\Controllers\Pos;

use App\Http\Requests\Sales\StoreSaleCustomerRequest;
use App\Http\Requests\Sales\StoreSaleItemRequest;
use App\Modules\Parties\Models\Party;
use App\Modules\Pos\Services\PosCatalog;
use App\Modules\Sales\Services\SaleCustomers;
use App\Modules\Sales\Services\SaleItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Busca de itens e clientes no caixa, pelos domínios compartilhados, sem exigir Produtos, Clientes ou Vendas.
 */
class PosCatalogController extends PosController
{
    public function __construct(private PosCatalog $catalog, private SaleItems $items, private SaleCustomers $customers) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(['term' => ['required', 'string', 'max:100']]);

        return response()->json($this->catalog->search($this->company($request), $validated['term']));
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:60']]);
        $item = $this->catalog->scan($this->company($request), $validated['code']);

        return $item === null ? response()->json(['message' => 'Código não encontrado.'], 404) : response()->json($item);
    }

    public function storeItem(StoreSaleItemRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $variant = $this->items->createSimplified($company, $request->user(), [
            'name' => $request->string('name')->trim()->value(),
            'type' => $request->string('type')->value(),
            'sale_price' => $request->string('sale_price')->value(),
            'sku' => $request->filled('sku') ? $request->string('sku')->trim()->upper()->value() : null,
            'unit_code' => $request->filled('unit_code') ? $request->string('unit_code')->value() : null,
        ]);

        return response()->json($this->items->summary($company, $variant, null, false), 201);
    }

    public function customers(Request $request): JsonResponse
    {
        $validated = $request->validate(['term' => ['required', 'string', 'max:100']]);

        return response()->json($this->customers->search($this->company($request), $validated['term'])
            ->map(fn (Party $party): array => $this->customers->summary($party))->values());
    }

    public function storeCustomer(StoreSaleCustomerRequest $request): JsonResponse
    {
        $party = $this->customers->createSimplified($this->company($request), $request->user(), $request->validated());

        return response()->json($this->customers->summary($party->load('addresses')), 201);
    }
}
