<?php

namespace App\Http\Controllers\Sales;

use App\Http\Requests\Sales\StoreSaleCustomerRequest;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Services\SaleCustomers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Busca e cadastro simplificado de clientes durante a venda, sem exigir o módulo Clientes.
 */
class SaleCustomerController extends SalesController
{
    public function __construct(private SaleCustomers $customers) {}

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['term' => ['required', 'string', 'max:100']]);

        return response()->json($this->customers->search($this->company($request), $validated['term'])
            ->map(fn (Party $party): array => $this->customers->summary($party))->values());
    }

    public function store(StoreSaleCustomerRequest $request): JsonResponse
    {
        $party = $this->customers->createSimplified($this->company($request), $request->user(), $request->validated());

        return response()->json($this->customers->summary($party->load('addresses')), 201);
    }
}
