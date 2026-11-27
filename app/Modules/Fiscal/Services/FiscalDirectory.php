<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Support\FiscalPresenter;
use App\Modules\Tenancy\Models\Company;

/**
 * Documentos fiscais de uma origem (venda, atendimento do PDV ou conta a receber) para as telas de outros módulos.
 * Nulo quando Documentos Fiscais não está contratado ou o usuário não pode consultá-los: a tela de origem segue igual.
 */
class FiscalDirectory
{
    private const ORIGINS = ['sale_order' => 'sale', 'pos_sale' => 'pos', 'finance_title' => 'finance'];

    public function __construct(private FiscalModule $module, private FiscalPresenter $presenter) {}

    /** @return array{origin: string, origin_id: int, can_create: bool, documents: list<array<string, mixed>>}|null */
    public function forOrigin(Company $company, string $originType, int $originId, bool $canView, bool $canCreate): ?array
    {
        if (! $canView || ! $this->module->isEnabled($company)) {
            return null;
        }

        return [
            'origin' => self::ORIGINS[$originType],
            'origin_id' => $originId,
            'can_create' => $canCreate,
            'documents' => array_values(FiscalDocument::query()->where('company_id', $company->id)->where('origin_type', $originType)->where('origin_id', $originId)
                ->latest('id')->limit(20)->get()->map(fn (FiscalDocument $document): array => $this->presenter->document($document))->all()),
        ];
    }
}
