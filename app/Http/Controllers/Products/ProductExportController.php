<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\AuditService;
use App\Modules\Products\Enums\BarcodeType;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\CategoryService;
use App\Modules\Products\Services\ProductSearch;
use App\Modules\Products\Support\ProductCsv;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExportController extends Controller
{
    use InteractsWithCompany;

    /**
     * Uma linha por unidade comercializável. Custos e saldos só são incluídos para quem pode vê-los.
     */
    public function __invoke(Request $request, ProductSearch $search, ProductCsv $csv, CategoryService $categories, AuditService $audit): StreamedResponse
    {
        $company = $this->company($request);
        $canViewCost = $this->allows($request, ProductPermission::ViewCost->value);
        $canViewInventory = $this->allows($request, ProductPermission::ViewInventory->value);
        $query = $search->query($company, $request, $canViewInventory)->with(['brand', 'unit', 'variants.balance']);
        $paths = $categories->paths($company);
        $audit->record('product.exported', $company, $request->user());

        return response()->streamDownload(function () use ($query, $csv, $paths, $canViewCost, $canViewInventory): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, ProductCsv::BOM);
            $isHeaderWritten = false;

            foreach ($query->lazyById(200, 'products.id', 'id') as $product) {
                foreach ($product->variants as $variant) {
                    $row = $this->row($product, $variant, $paths, $canViewCost, $canViewInventory);

                    if (! $isHeaderWritten) {
                        $csv->write($output, array_keys($row));
                        $isHeaderWritten = true;
                    }

                    $csv->write($output, $row);
                }
            }

            if (! $isHeaderWritten) {
                $csv->write($output, ProductCsv::COLUMNS);
            }

            fclose($output);
        }, 'produtos-'.now($company->timezone)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<string, string|null>
     */
    private function row(Product $product, ProductVariant $variant, array $paths, bool $canViewCost, bool $canViewInventory): array
    {
        return [
            'nome' => $product->name,
            'tipo' => $product->type->label(),
            'variacao' => $variant->label,
            'sku' => $variant->sku,
            'codigo_interno' => $product->internal_code,
            'codigo_barras' => $variant->barcode,
            'tipo_codigo' => match ($variant->barcode_type) {
                BarcodeType::Gtin => 'gtin',
                BarcodeType::Internal => 'interno',
                null => null,
            },
            'categoria' => $paths[$product->product_category_id ?? 0] ?? null,
            'marca' => $product->brand?->name,
            'unidade' => $product->unit->code,
            'referencia_fabricante' => $product->manufacturer_reference,
            'descricao_curta' => $product->short_description,
            'descricao' => $product->description,
            ...($canViewCost ? ['preco_custo' => ProductCsv::formatDecimal($variant->cost_price)] : []),
            'preco_venda' => ProductCsv::formatDecimal($variant->sale_price),
            'preco_promocional' => ProductCsv::formatDecimal($variant->promotional_price),
            'estoque_minimo' => ProductCsv::formatDecimal($variant->minimum_stock),
            'controla_estoque' => $product->controlsStock() ? 'sim' : 'nao',
            ...($canViewInventory ? ['saldo' => $product->controlsStock() ? ProductCsv::formatDecimal($variant->balance->quantity ?? '0.0000') : null] : []),
            'ncm' => $product->ncm,
            'cest' => $product->cest,
            'origem' => $product->merchandise_origin,
            'observacoes' => $product->notes,
            'situacao' => $product->is_active && $variant->is_active ? 'ativo' : 'inativo',
        ];
    }
}
