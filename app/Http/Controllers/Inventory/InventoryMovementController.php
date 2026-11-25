<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ReverseInventoryMovementRequest;
use App\Http\Requests\Inventory\StoreInventoryMovementRequest;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Products\Support\ProductPresenter;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InventoryMovementController extends Controller
{
    use InteractsWithCompany;

    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request, ProductPresenter $presenter): Response
    {
        $company = $this->company($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return Inertia::render('products/movements', [
            'movements' => InventoryMovement::query()->where('company_id', $company->id)
                ->with(['variant.product.unit', 'reversal'])
                ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->whereHas('variant', fn (Builder $variant) => $variant
                    ->where(fn (Builder $variant) => $variant
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', Str::upper($search))
                        ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")))))
                ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
                ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '>=', Date::parse($date, $company->timezone)->startOfDay()->utc()))
                ->when($filters['until'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '<=', Date::parse($date, $company->timezone)->endOfDay()->utc()))
                ->latest('occurred_at')->latest('id')->paginate(20)->withQueryString()
                ->through($presenter->movement(...)),
            'filters' => $filters,
            'types' => MovementType::options(),
        ]);
    }

    public function store(StoreInventoryMovementRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $operation = new StockOperation(
            companyId: $this->company($request)->id,
            variantId: (int) $data['product_variant_id'],
            quantity: (string) $data['quantity'],
            user: $request->user(),
            reason: $data['reason'] ?? null,
            idempotencyKey: $data['idempotency_key'],
        );

        match (MovementType::from($data['type'])) {
            MovementType::Initial => $this->inventory->recordInitial($operation),
            MovementType::Entry => $this->inventory->recordEntry($operation),
            MovementType::Exit => $this->inventory->recordExit($operation),
            MovementType::Adjustment => $this->inventory->adjustTo($operation),
            MovementType::Reversal => abort(422),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Movimentação registrada.']);

        return back();
    }

    public function reverse(ReverseInventoryMovementRequest $request, InventoryMovement $movement): RedirectResponse
    {
        $this->inventory->reverse($movement, $request->user(), $request->validated('reason'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Movimentação estornada.']);

        return back();
    }
}
