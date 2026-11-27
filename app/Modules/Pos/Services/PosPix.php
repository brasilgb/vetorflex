<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Payments\Enums\ChargeOrigin;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Providers\ProviderException;
use App\Modules\Payments\Services\ChargeService;
use App\Modules\Payments\Services\PaymentsModule;
use App\Modules\Pos\Models\PosPayment;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Pix dinâmico no caixa, pelo módulo Pagamentos (opcional). Gerar o QR Code não confirma nada: a venda só aceita o
 * pagamento quando a cobrança está paga segundo a consulta ao provedor, é desta empresa e do PDV, tem o mesmo valor
 * e ainda não foi usada em outra venda (`pos_payments.payment_charge_id` único). Sem Pagamentos, o PDV segue com
 * pagamentos registrados manualmente.
 */
class PosPix
{
    /** Intervalo mínimo entre consultas ao provedor durante a espera do caixa. */
    private const SYNC_INTERVAL_SECONDS = 3;

    public function __construct(private PaymentsModule $payments, private ChargeService $charges) {}

    public function isAvailable(Company $company): bool
    {
        return $this->payments->hasProviderPix($company);
    }

    /** Cobrança Pix do carrinho; a mesma chave do carrinho devolve a cobrança já gerada. */
    public function create(PosSession $session, User $operator, string $amount, string $cartKey, string $idempotencyKey): PaymentCharge
    {
        $company = Company::query()->findOrFail($session->company_id);

        if (! $this->isAvailable($company)) {
            throw ValidationException::withMessages(['pix' => 'Pix confirmado pelo provedor não está disponível. Registre o Pix manualmente.']);
        }

        // Um Pix em aberto por carrinho: reaproveita o mesmo valor; com outro valor, o anterior é cancelado antes.
        $open = PaymentCharge::query()->where('company_id', $company->id)->where('active_origin_key', "pos:{$session->id}:{$cartKey}")->first();

        if ($open !== null && BigDecimal::of($open->amount)->isEqualTo(CommercialMath::money($amount))) {
            return $this->status($open);
        }

        if ($open !== null) {
            $this->cancel($open, $operator);
        }

        $session->loadMissing('terminal');
        $charge = $this->charges->create($company, $operator, [
            'origin' => ChargeOrigin::Pos->value,
            'origin_id' => $session->id,
            'origin_key' => "{$session->id}:{$cartKey}",
            'amount' => CommercialMath::money($amount),
            'description' => "PDV {$session->terminal->name} — sessão {$session->number}",
            'due_date' => now($company->timezone)->format('Y-m-d'),
            'expires_in' => 900,
            'metadata' => ['terminal_id' => $session->pos_terminal_id, 'terminal' => $session->terminal->name, 'session_id' => $session->id, 'session_number' => $session->number, 'operator_id' => $operator->id, 'operator' => $operator->name],
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($charge->status === ChargeStatus::Failed) {
            throw ValidationException::withMessages(['pix' => $charge->failure_reason ?? 'O provedor recusou a geração do Pix.']);
        }

        return $charge;
    }

    /** Situação atual, consultando o provedor no máximo a cada poucos segundos. */
    public function status(PaymentCharge $charge): PaymentCharge
    {
        if ($charge->status->isOpen() && ($charge->last_synced_at === null || $charge->last_synced_at->diffInSeconds(now()) >= self::SYNC_INTERVAL_SECONDS)) {
            try {
                return $this->charges->sync($charge);
            } catch (ProviderException) {
                return $charge;
            }
        }

        return $charge;
    }

    public function cancel(PaymentCharge $charge, User $operator): PaymentCharge
    {
        return $this->charges->cancel($charge, $operator, 'Pix do caixa cancelado pelo operador.');
    }

    /**
     * Confirma, sob trava, cada pagamento Pix ligado a uma cobrança e associa a cobrança à venda. Chamar dentro da
     * transação da finalização.
     *
     * @param  list<array{method: string, amount: string, payment_charge_id: int|null}>  $payments
     */
    public function claim(Company $company, array $payments, SaleOrder $order): void
    {
        foreach ($payments as $index => $payment) {
            if ($payment['payment_charge_id'] === null) {
                continue;
            }

            $charge = PaymentCharge::query()->where('company_id', $company->id)->where('origin', ChargeOrigin::Pos)->lockForUpdate()->find($payment['payment_charge_id']);
            $received = $charge === null ? BigDecimal::zero() : BigDecimal::of($charge->paid_amount)->minus($charge->refunded_amount);

            if ($charge === null || $charge->status !== ChargeStatus::Paid) {
                throw ValidationException::withMessages(["payments.{$index}.payment_charge_id" => 'O Pix ainda não foi confirmado pelo provedor. Aguarde a confirmação ou registre outra forma de pagamento.']);
            }

            if (! $received->isEqualTo($payment['amount'])) {
                throw ValidationException::withMessages(["payments.{$index}.payment_charge_id" => 'O valor confirmado do Pix ('.CommercialMath::money($received).') difere do pagamento informado.']);
            }

            if (PosPayment::query()->where('payment_charge_id', $charge->id)->exists()) {
                throw ValidationException::withMessages(["payments.{$index}.payment_charge_id" => 'Este Pix já foi usado em outra venda.']);
            }

            $charge->update(['origin_label' => "PDV — venda #{$order->number}", 'metadata' => [...($charge->metadata ?? []), 'sale_order_id' => $order->id]]);
        }
    }
}
