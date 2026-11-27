<?php

namespace App\Modules\Payments\Services;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Payments\Enums\ChargeOrigin;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Origem de uma cobrança. Venda e conta a receber só são aceitas com o módulo correspondente habilitado e sempre da
 * empresa ativa; o valor padrão é o saldo devido, descontado o que outras cobranças já receberam.
 *
 * @phpstan-type Resolved array{type: string|null, id: int|null, key: string|null, label: string|null, balance: string|null, description: string|null, due_date: string|null, party_id: int|null, payer_name: string|null}
 */
class ChargeOrigins
{
    public function __construct(private PaymentsModule $module) {}

    /** @return Resolved */
    public function resolve(Company $company, ChargeOrigin $origin, ?int $originId, ?string $originKey): array
    {
        return match ($origin) {
            ChargeOrigin::Manual => ['type' => null, 'id' => null, 'key' => null, 'label' => null, 'balance' => null, 'description' => null, 'due_date' => null, 'party_id' => null, 'payer_name' => null],
            ChargeOrigin::Sale => $this->sale($company, $originId),
            ChargeOrigin::Finance => $this->title($company, $originId),
            ChargeOrigin::Pos => ['type' => 'pos_session', 'id' => $originId, 'key' => $originKey === null ? null : "pos:{$originKey}", 'label' => null, 'balance' => null, 'description' => 'Pagamento no PDV', 'due_date' => null, 'party_id' => null, 'payer_name' => null],
        };
    }

    /** Quanto já foi recebido (líquido de devoluções) por cobranças da origem. */
    public function received(Company $company, string $type, int $id): BigDecimal
    {
        $charges = PaymentCharge::query()->where('company_id', $company->id)->where('origin_type', $type)->where('origin_id', $id);

        return BigDecimal::of((string) ((clone $charges)->sum('paid_amount') ?: '0'))->minus((string) ($charges->sum('refunded_amount') ?: '0'));
    }

    /** @return Resolved */
    private function sale(Company $company, ?int $orderId): array
    {
        if (! $this->module->isEnabled($company, 'sales')) {
            throw ValidationException::withMessages(['origin' => 'Cobrança de venda exige o módulo Vendas habilitado.']);
        }

        $order = SaleOrder::query()->where('company_id', $company->id)->find($orderId) ?? throw ValidationException::withMessages(['origin_id' => 'Venda não encontrada.']);

        if (in_array($order->status, [SaleOrderStatus::Draft, SaleOrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['origin_id' => 'Somente vendas confirmadas podem ser cobradas.']);
        }

        $balance = BigDecimal::of($order->total)->minus($this->received($company, 'sale_order', $order->id));

        return [
            'type' => 'sale_order',
            'id' => $order->id,
            'key' => "sale_order:{$order->id}",
            'label' => "Venda #{$order->number}",
            'balance' => CommercialMath::money(BigDecimal::max(BigDecimal::zero(), $balance)),
            'description' => "Venda #{$order->number}",
            'due_date' => null,
            'party_id' => $order->customer_party_id,
            'payer_name' => $order->customer_snapshot['name'] ?? null,
        ];
    }

    /** @return Resolved */
    private function title(Company $company, ?int $titleId): array
    {
        if (! $this->module->isEnabled($company, 'finance')) {
            throw ValidationException::withMessages(['origin' => 'Cobrança de conta a receber exige o módulo Financeiro habilitado.']);
        }

        $title = FinanceTitle::query()->where('company_id', $company->id)->where('kind', TitleKind::Receivable)->find($titleId)
            ?? throw ValidationException::withMessages(['origin_id' => 'Conta a receber não encontrada.']);

        if (! $title->status->isOpen()) {
            throw ValidationException::withMessages(['origin_id' => "Conta a receber \"{$title->status->label()}\" não pode ser cobrada."]);
        }

        // O saldo em aberto do título já reflete as baixas feitas por cobranças anteriores (Financeiro é a fonte).
        return [
            'type' => 'finance_title',
            'id' => $title->id,
            'key' => "finance_title:{$title->id}",
            'label' => "Título #{$title->number}",
            'balance' => CommercialMath::money($title->openBalance()),
            'description' => $title->description,
            'due_date' => $title->due_date->format('Y-m-d'),
            'party_id' => $title->party_id,
            'payer_name' => $title->party_name,
        ];
    }
}
