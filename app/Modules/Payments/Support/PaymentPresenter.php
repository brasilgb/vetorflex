<?php

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentChargeEvent;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Providers\ProviderRegistry;
use Brick\Math\BigDecimal;

/**
 * Formato dos registros de Pagamentos para as telas. Credenciais, tokens e conteúdo bruto de webhooks nunca saem daqui.
 */
class PaymentPresenter
{
    public function __construct(private ProviderRegistry $providers) {}

    /** @return array<string, mixed> */
    public function charge(PaymentCharge $charge): array
    {
        $open = BigDecimal::of($charge->amount)->minus($charge->paid_amount);

        return [
            'id' => $charge->id,
            'number' => $charge->number,
            'status' => $charge->status->value,
            'status_label' => $charge->status->label(),
            'method' => $charge->method,
            'provider' => $charge->provider,
            'provider_name' => $charge->provider === null ? null : $this->providers->get($charge->provider)->name(),
            'environment' => $charge->environment,
            'amount' => $charge->amount,
            'paid_amount' => $charge->paid_amount,
            'refunded_amount' => $charge->refunded_amount,
            'open_amount' => (string) BigDecimal::max(BigDecimal::zero(), $open)->toScale(2),
            'fee_amount' => $charge->fee_amount,
            'net_amount' => $charge->net_amount,
            'description' => $charge->description,
            'reference' => $charge->reference,
            'due_date' => $charge->due_date->format('Y-m-d'),
            'expires_at' => $charge->expires_at?->toIso8601String(),
            'payer_name' => $charge->payer_name,
            'payer_document' => $charge->payer_document,
            'origin' => $charge->origin->value,
            'origin_label' => $charge->origin->label(),
            'origin_type' => $charge->origin_type,
            'origin_id' => $charge->origin_id,
            'origin_reference' => $charge->origin_label,
            'provider_charge_id' => $charge->provider_charge_id,
            'provider_status' => $charge->provider_status,
            'paid_at' => $charge->paid_at?->toIso8601String(),
            'cancelled_at' => $charge->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $charge->cancellation_reason,
            'failure_reason' => $charge->failure_reason,
            'last_synced_at' => $charge->last_synced_at?->toIso8601String(),
            'created_at' => $charge->created_at?->toIso8601String(),
            'created_by_name' => $charge->created_by_name,
        ];
    }

    /** @return array{pix_copy_paste: string|null, qr_code_image: string|null, payment_url: string|null} */
    public function pix(PaymentCharge $charge): array
    {
        return ['pix_copy_paste' => $charge->pix_copy_paste, 'qr_code_image' => $charge->qr_code_image, 'payment_url' => $charge->payment_url];
    }

    /** @return array<string, mixed> */
    public function transaction(PaymentTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'provider_transaction_id' => $transaction->provider_transaction_id,
            'amount' => $transaction->amount,
            'fee_amount' => $transaction->fee_amount,
            'net_amount' => $transaction->net_amount,
            'refunded_amount' => $transaction->refunded_amount,
            'refundable' => (string) BigDecimal::of($transaction->amount)->minus($transaction->refunded_amount)->toScale(2),
            'paid_at' => $transaction->paid_at->toIso8601String(),
            'payer_name' => $transaction->payer_name,
        ];
    }

    /** @return array<string, mixed> */
    public function refund(PaymentRefund $refund): array
    {
        return [
            'id' => $refund->id,
            'amount' => $refund->amount,
            'status' => $refund->status->value,
            'status_label' => $refund->status->label(),
            'reason' => $refund->reason,
            'failure_reason' => $refund->failure_reason,
            'requested_by_name' => $refund->requested_by_name,
            'created_at' => $refund->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function event(PaymentChargeEvent $event): array
    {
        return ['id' => $event->id, 'event' => $event->event, 'description' => $event->description, 'user_name' => $event->user_name, 'created_at' => $event->created_at?->toIso8601String()];
    }

    /** @return array<string, mixed> Sem credenciais: só indica quais foram informadas. */
    public function account(PaymentProviderAccount $account, string $webhookUrl): array
    {
        $credentials = $account->credentials ?? [];

        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'provider_name' => $this->providers->get($account->provider)->name(),
            'environment' => $account->environment,
            'name' => $account->name,
            'pix_key' => $account->pix_key,
            'methods' => $account->methods ?? ['pix'],
            'expiration_seconds' => $account->expiration_seconds,
            'finance_account_id' => $account->finance_account_id,
            'is_active' => $account->is_active,
            'status' => $account->status->value,
            'status_label' => $account->status->label(),
            'last_tested_at' => $account->last_tested_at?->toIso8601String(),
            'last_error' => $account->last_error,
            'webhook_registered_at' => $account->webhook_registered_at?->toIso8601String(),
            'webhook_url' => $webhookUrl,
            'has_client_id' => filled($credentials['client_id'] ?? null),
            'has_client_secret' => filled($credentials['client_secret'] ?? null),
            'has_certificate' => filled($credentials['certificate'] ?? null),
            'client_id_hint' => filled($credentials['client_id'] ?? null) ? '…'.substr((string) $credentials['client_id'], -4) : null,
        ];
    }

    /** @return array<string, mixed> Sem o conteúdo bruto da notificação. */
    public function webhook(PaymentWebhookEvent $event): array
    {
        return [
            'id' => $event->id,
            'provider' => $event->provider,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
            'attempts' => $event->attempts,
            'error' => $event->error,
            'charges' => is_array($event->payload['pix'] ?? null) ? count($event->payload['pix']) : 0,
            'created_at' => $event->created_at?->toIso8601String(),
            'processed_at' => $event->processed_at?->toIso8601String(),
        ];
    }
}
