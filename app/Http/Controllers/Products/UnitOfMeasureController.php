<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\SaveUnitOfMeasureRequest;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Products\Services\UnitOfMeasureProvisioner;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UnitOfMeasureController extends Controller
{
    use InteractsWithCompany;

    public function index(Request $request, UnitOfMeasureProvisioner $provisioner): Response
    {
        $company = $this->company($request);
        $provisioner->ensureDefaults($company);

        return Inertia::render('products/units', [
            'records' => UnitOfMeasure::query()->where('company_id', $company->id)
                ->withCount('products')
                ->when($request->string('search')->trim()->value(), fn (Builder $query, string $search) => $query
                    ->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
                ->orderByDesc('is_default')->orderBy('id')->paginate(20)->withQueryString()
                ->through(fn (UnitOfMeasure $unit): array => $unit->only(['id', 'code', 'name', 'allows_fraction', 'is_default', 'is_active', 'products_count'])),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(SaveUnitOfMeasureRequest $request): RedirectResponse
    {
        UnitOfMeasure::create([...$request->validated(), 'company_id' => $this->company($request)->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Unidade cadastrada.']);

        return back();
    }

    public function update(SaveUnitOfMeasureRequest $request, UnitOfMeasure $unit): RedirectResponse
    {
        $unit->fill($request->validated());

        if ($unit->is_default && $unit->isDirty(['code', 'name', 'allows_fraction'])) {
            throw ValidationException::withMessages(['code' => 'Unidades padrão só podem ser ativadas ou inativadas.']);
        }

        if ($unit->isDirty('allows_fraction') && ! $unit->allows_fraction && $unit->products()->exists()) {
            throw ValidationException::withMessages(['allows_fraction' => 'A unidade já é usada por produtos e deve continuar aceitando frações.']);
        }

        $unit->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Unidade atualizada.']);

        return back();
    }
}
