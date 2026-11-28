<?php

namespace App\Http\Controllers\Finance;

use App\Http\Requests\Finance\StoreFinancePartyRequest;
use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Finance\Services\FinanceModule;
use App\Modules\Finance\Services\FinanceParties;
use App\Modules\Parties\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Busca de pessoas do domínio compartilhado e cadastro simplificado, sem exigir Clientes ou Compras.
 */
class FinancePartyController extends FinanceController
{
    public function __construct(private FinanceParties $parties) {}

    public function lookup(Request $request): JsonResponse
    {
        $this->authorizeLaunch($request);
        $validated = $request->validate(['term' => ['required', 'string', 'max:100']]);

        return response()->json($this->parties->search($this->company($request), $validated['term'])
            ->map(fn (Party $party): array => $this->parties->summary($party))->values());
    }

    public function store(StoreFinancePartyRequest $request): JsonResponse
    {
        $this->authorizeLaunch($request);
        $party = $this->parties->createSimplified($this->company($request), $request->user(), $request->validated());

        return response()->json($this->parties->summary($party), 201);
    }

    /** Pessoas são vinculadas ao lançar títulos ou recorrências. */
    private function authorizeLaunch(Request $request): void
    {
        abort_unless(app(FinanceModule::class)->isEnabled($this->company($request)) && ($this->can($request, FinancePermission::ManageReceivables) || $this->can($request, FinancePermission::ManagePayables)), 403);
    }
}
