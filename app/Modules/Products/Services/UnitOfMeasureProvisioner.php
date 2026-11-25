<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Tenancy\Models\Company;

/**
 * Cria as unidades comuns da empresa no primeiro uso do módulo. A operação é idempotente.
 */
class UnitOfMeasureProvisioner
{
    /** @var list<array{string, string, bool}> */
    private const DEFAULTS = [
        ['UN', 'Unidade', false],
        ['CX', 'Caixa', false],
        ['PCT', 'Pacote', false],
        ['KG', 'Quilograma', true],
        ['G', 'Grama', true],
        ['L', 'Litro', true],
        ['ML', 'Mililitro', true],
        ['M', 'Metro', true],
        ['CM', 'Centímetro', true],
        ['H', 'Hora', true],
        ['SERV', 'Serviço', false],
    ];

    public function ensureDefaults(Company $company): void
    {
        if (UnitOfMeasure::query()->where('company_id', $company->id)->where('is_default', true)->exists()) {
            return;
        }

        UnitOfMeasure::query()->insertOrIgnore(array_map(fn (array $unit): array => [
            'company_id' => $company->id,
            'code' => $unit[0],
            'name' => $unit[1],
            'allows_fraction' => $unit[2],
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], self::DEFAULTS));
    }

    public function defaultFor(Company $company, ProductType $type): UnitOfMeasure
    {
        $this->ensureDefaults($company);

        return UnitOfMeasure::query()
            ->where('company_id', $company->id)
            ->where('code', $type === ProductType::Service ? 'SERV' : 'UN')
            ->firstOrFail();
    }
}
