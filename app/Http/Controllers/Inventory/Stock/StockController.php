<?php

namespace App\Http\Controllers\Inventory\Stock;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Enums\InventoryPermission;
use App\Modules\Inventory\Models\InventoryWarehouse;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Base dos controladores do módulo Estoque: empresa ativa (nunca a enviada pelo navegador), permissões e avisos.
 */
abstract class StockController extends Controller
{
    use InteractsWithCompany;

    protected function can(Request $request, InventoryPermission $permission): bool
    {
        return $this->allows($request, $permission->value);
    }

    protected function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }

    /** @return list<array{id: int, name: string, code: string, is_default: bool}> */
    protected function warehouseOptions(Company $company): array
    {
        return array_values(InventoryWarehouse::query()->where('company_id', $company->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (InventoryWarehouse $warehouse): array => ['id' => $warehouse->id, 'name' => $warehouse->name, 'code' => $warehouse->code, 'is_default' => $warehouse->is_default])->all());
    }
}
