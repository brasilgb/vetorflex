<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Support\PaymentPresenter;
use App\Modules\Tenancy\Models\Company;

/**
 * Cobranças de uma origem (venda ou conta a receber) para as telas de outros módulos. Devolve nulo quando
 * Pagamentos não está contratado ou o usuário não pode consultar cobranças: a tela de origem segue igual.
 */
class ChargeDirectory
{
    public function __construct(private PaymentsModule $module, private PaymentPresenter $presenter) {}

    /** @return array{origin: string, origin_id: int, can_create: bool, charges: list<array<string, mixed>>}|null */
    public function forOrigin(Company $company, string $originType, int $originId, bool $canView, bool $canCreate): ?array
    {
        if (! $canView || ! $this->module->isEnabled($company)) {
            return null;
        }

        return [
            'origin' => $originType === 'sale_order' ? 'sale' : 'finance',
            'origin_id' => $originId,
            'can_create' => $canCreate,
            'charges' => array_values(PaymentCharge::query()->where('company_id', $company->id)->where('origin_type', $originType)->where('origin_id', $originId)
                ->latest('id')->limit(20)->get()->map(fn (PaymentCharge $charge): array => $this->presenter->charge($charge))->all()),
        ];
    }
}
