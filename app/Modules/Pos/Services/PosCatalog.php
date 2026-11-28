<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Pos\Models\PosFavorite;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductCatalog;
use App\Modules\Sales\Services\SaleItems;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo do caixa sobre o catálogo compartilhado (`ProductCatalog` e `SaleItems`): busca por código de barras, SKU
 * ou descrição, leitura exata para leitores que digitam como teclado e favoritos. Não há catálogo paralelo.
 */
class PosCatalog
{
    private const FAVORITES_LIMIT = 24;

    public function __construct(private ProductCatalog $catalog, private SaleItems $items, private AuditService $audit) {}

    /** @return list<array<string, mixed>> */
    public function search(Company $company, string $term): array
    {
        return $this->items->search($company, $term, false);
    }

    /**
     * Código lido pelo leitor (código de barras ou SKU exato).
     *
     * @return array<string, mixed>|null
     */
    public function scan(Company $company, string $code): ?array
    {
        $variant = $this->catalog->findByCode($company, $code);

        return $variant === null ? null : ($this->items->search($company, $code, false)[0] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function favorites(Company $company): array
    {
        $ids = PosFavorite::query()->where('company_id', $company->id)->orderBy('position')->orderBy('id')->pluck('product_variant_id')->all();
        $variants = $this->catalog->sellableUnits($company)->whereKey($ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id): ?array => $variants->has($id) ? $this->items->summary($company, $variants->get($id), null, false) : null, $ids)));
    }

    /** @param list<int> $variantIds */
    public function saveFavorites(Company $company, User $user, array $variantIds): void
    {
        $variantIds = array_slice(array_values(array_unique(array_map('intval', $variantIds))), 0, self::FAVORITES_LIMIT);
        $valid = $this->catalog->sellableUnits($company)->whereKey($variantIds)->pluck('product_variants.id')->all();

        if (count($valid) !== count($variantIds)) {
            throw ValidationException::withMessages(['favorites' => 'Selecione itens ativos do catálogo.']);
        }

        DB::transaction(function () use ($company, $variantIds): void {
            PosFavorite::query()->where('company_id', $company->id)->delete();

            foreach ($variantIds as $position => $id) {
                PosFavorite::create(['company_id' => $company->id, 'product_variant_id' => $id, 'position' => $position]);
            }
        });
        $this->audit->record('pos.favorites_updated', $company, $user, null, ['count' => count($variantIds)]);
    }

    /** @return list<array{id: int, name: string}> */
    public function sellableOptions(Company $company): array
    {
        return array_values($this->catalog->sellableUnits($company)->limit(500)->get()
            ->map(fn (ProductVariant $variant): array => ['id' => $variant->id, 'name' => $variant->displayName()])->sortBy('name')->values()->all());
    }
}
