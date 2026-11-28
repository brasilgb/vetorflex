<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Inventory\Models\InventoryWarehouse;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Depósitos da empresa. O depósito principal é criado sob demanda e nunca é inativado; é nele que caem as
 * operações sem depósito informado (Compras, Vendas, PDV e o cadastro de Produtos).
 */
class WarehouseService
{
    public function __construct(private AuditService $audit) {}

    public function defaultFor(int $companyId): InventoryWarehouse
    {
        $existing = InventoryWarehouse::query()->where('default_company_id', $companyId)->first();

        if ($existing !== null) {
            return $existing;
        }

        InventoryWarehouse::query()->insertOrIgnore([
            'company_id' => $companyId,
            'name' => 'Depósito principal',
            'code' => 'PRINCIPAL',
            'is_default' => true,
            'is_active' => true,
            'default_company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryWarehouse::query()->where('default_company_id', $companyId)->firstOrFail();
    }

    /** Depósito ativo da empresa, ou o principal quando nenhum é informado. */
    public function resolve(int $companyId, ?int $warehouseId): InventoryWarehouse
    {
        if ($warehouseId === null) {
            return $this->defaultFor($companyId);
        }

        $warehouse = InventoryWarehouse::query()->where('company_id', $companyId)->find($warehouseId)
            ?? throw ValidationException::withMessages(['inventory_warehouse_id' => 'Depósito não encontrado.']);

        if (! $warehouse->is_active) {
            throw ValidationException::withMessages(['inventory_warehouse_id' => "O depósito {$warehouse->name} está inativo."]);
        }

        return $warehouse;
    }

    /** @param array{name: string, code: string, location?: string|null, is_active?: bool} $data */
    public function save(Company $company, User $user, InventoryWarehouse $warehouse, array $data): InventoryWarehouse
    {
        $this->defaultFor($company->id);

        return DB::transaction(function () use ($company, $user, $warehouse, $data): InventoryWarehouse {
            if ($warehouse->exists && $warehouse->is_default && ! ($data['is_active'] ?? true)) {
                throw ValidationException::withMessages(['is_active' => 'O depósito principal não pode ser inativado.']);
            }

            $warehouse->fill([
                'company_id' => $company->id,
                'name' => $data['name'],
                'code' => mb_strtoupper($data['code']),
                'location' => $data['location'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ])->save();
            $this->audit->record($warehouse->wasRecentlyCreated ? 'inventory.warehouse_created' : 'inventory.warehouse_updated', $company, $user, $warehouse, ['name' => $warehouse->name, 'code' => $warehouse->code]);

            return $warehouse;
        });
    }
}
