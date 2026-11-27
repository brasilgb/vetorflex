<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Inventory\Models\InventoryWarehouse;
use App\Modules\Inventory\Support\StockCsv;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Importação de mercadorias por CSV: cada linha válida cria uma mercadoria pelo cadastro simplificado, com saldo
 * inicial opcional no depósito informado (pelo código). SKU ou código de barras já existentes são ignorados, sem
 * alterar saldos: o arquivo não serve para movimentar estoque já controlado.
 */
class StockImportService
{
    public function __construct(private StockCsv $csv, private StockItems $items, private WarehouseService $warehouses, private AuditService $audit) {}

    /** @return array{created: int, skipped: list<array{line: int, message: string}>, errors: list<array{line: int, message: string}>} */
    public function import(Company $company, User $user, UploadedFile $file): array
    {
        $result = ['created' => 0, 'skipped' => [], 'errors' => []];
        $this->warehouses->defaultFor($company->id);
        $warehouses = InventoryWarehouse::query()->where('company_id', $company->id)->where('is_active', true)->pluck('id', 'code');

        foreach ($this->csv->read($file) as $row) {
            $data = $row['data'];

            if ($row['errors'] !== [] || blank($data['nome'] ?? null)) {
                $result['errors'][] = ['line' => $row['line'], 'message' => $row['errors'][0] ?? 'Informe o nome.'];

                continue;
            }

            $sku = Str::upper(trim($data['sku'] ?? ''));
            $barcode = Str::upper(trim($data['codigo_barras'] ?? ''));
            $exists = ProductVariant::query()->where('company_id', $company->id)
                ->where(fn ($query) => $query->when($sku !== '', fn ($query) => $query->orWhere('sku', $sku))->when($barcode !== '', fn ($query) => $query->orWhere('barcode', $barcode)))
                ->when($sku === '' && $barcode === '', fn ($query) => $query->whereRaw('1 = 0'))->exists();

            if ($exists) {
                $result['skipped'][] = ['line' => $row['line'], 'message' => 'SKU ou código de barras já cadastrado; saldo não alterado.'];

                continue;
            }

            $warehouseCode = Str::upper(trim($data['deposito'] ?? ''));

            if ($warehouseCode !== '' && ! $warehouses->has($warehouseCode)) {
                $result['errors'][] = ['line' => $row['line'], 'message' => "Depósito {$warehouseCode} não encontrado."];

                continue;
            }

            try {
                $this->items->create($company, $user, [
                    'name' => trim($data['nome']),
                    'sku' => $sku === '' ? null : $sku,
                    'barcode' => $barcode === '' ? null : $barcode,
                    'internal_code' => blank($data['codigo_interno'] ?? null) ? null : trim($data['codigo_interno']),
                    'category_name' => blank($data['categoria'] ?? null) ? null : trim($data['categoria']),
                    'unit_code' => blank($data['unidade'] ?? null) ? null : trim($data['unidade']),
                    'minimum_stock' => $this->decimal($data['estoque_minimo'] ?? null) ?? '0',
                    'maximum_stock' => $this->decimal($data['estoque_maximo'] ?? null),
                    'cost_price' => $this->decimal($data['custo'] ?? null),
                    'sale_price' => $this->decimal($data['preco_venda'] ?? null),
                    'initial_stock' => $this->decimal($data['saldo_inicial'] ?? null),
                    'inventory_warehouse_id' => $warehouseCode === '' ? null : $warehouses[$warehouseCode],
                ]);
                $result['created']++;
            } catch (ValidationException $exception) {
                $result['errors'][] = ['line' => $row['line'], 'message' => collect($exception->errors())->flatten()->first() ?? 'Linha inválida.'];
            }
        }

        $this->audit->record('inventory.items_imported', $company, $user, null, ['created' => $result['created'], 'skipped' => count($result['skipped']), 'errors' => count($result['errors'])]);

        return $result;
    }

    private function decimal(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $parsed = StockCsv::parseDecimal($value);

        return is_numeric($parsed) ? $parsed : throw ValidationException::withMessages(['file' => "Valor numérico inválido: {$value}."]);
    }
}
