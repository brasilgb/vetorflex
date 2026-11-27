<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Enums\WebhookStatus;
use App\Modules\Payments\Jobs\ProcessPaymentWebhook;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Providers\ProviderRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recebimento e processamento de notificações. A empresa é identificada somente pelo token secreto da URL da conta;
 * nada no conteúdo da notificação escolhe o contexto empresarial. O evento é gravado antes de tudo (conteúdo idêntico
 * uma só vez) e processado por fila: cada cobrança citada é consultada no provedor e sincronizada.
 */
class WebhookService
{
    public function __construct(private ProviderRegistry $providers, private ChargeService $charges) {}

    public function account(string $provider, string $token): ?PaymentProviderAccount
    {
        return PaymentProviderAccount::query()->where('provider', $provider)->where('webhook_token_hash', hash('sha256', $token))->first();
    }

    /** @param array<string, mixed> $payload */
    public function receive(PaymentProviderAccount $account, array $payload, ?string $ip): PaymentWebhookEvent
    {
        $hash = hash('sha256', (string) json_encode($payload));
        $ids = $this->providers->get($account->provider)->chargeIdsFromWebhook($payload);

        try {
            $event = PaymentWebhookEvent::create([
                'company_id' => $account->company_id,
                'payment_provider_account_id' => $account->id,
                'provider' => $account->provider,
                'payload_hash' => $hash,
                'payload' => $payload,
                'status' => $ids === [] ? WebhookStatus::Ignored : WebhookStatus::Received,
                'received_ip' => $ip,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Repetição idêntica: o evento já está gravado (e processado ou na fila).
            return PaymentWebhookEvent::query()->where('payment_provider_account_id', $account->id)->where('payload_hash', $hash)->firstOrFail();
        }

        if ($event->status === WebhookStatus::Received) {
            ProcessPaymentWebhook::dispatch($event->id);
        }

        return $event;
    }

    /** Processa o evento: sincroniza cada cobrança da conta citada. Lança a falha para a fila tentar de novo. */
    public function process(PaymentWebhookEvent $event): void
    {
        $account = PaymentProviderAccount::query()->find($event->payment_provider_account_id);

        if ($account === null) {
            $event->update(['status' => WebhookStatus::Ignored, 'error' => 'Conta do provedor não encontrada.', 'processed_at' => now()]);

            return;
        }

        $event->increment('attempts');
        $ids = $this->providers->get($account->provider)->chargeIdsFromWebhook($event->payload);
        $charges = PaymentCharge::query()->where('payment_provider_account_id', $account->id)->whereIn('provider_charge_id', $ids)->get();

        try {
            foreach ($charges as $charge) {
                $this->charges->sync($charge);
            }
        } catch (Throwable $exception) {
            $event->update(['status' => WebhookStatus::Failed, 'error' => Str::limit($exception->getMessage(), 490)]);

            throw $exception;
        }

        $event->update([
            'status' => $charges->isEmpty() ? WebhookStatus::Ignored : WebhookStatus::Processed,
            'error' => $charges->isEmpty() ? 'Nenhuma cobrança desta conta corresponde à notificação.' : null,
            'processed_at' => now(),
        ]);
    }

    /** Reprocessamento autorizado de um evento com falha ou ignorado. */
    public function reprocess(PaymentWebhookEvent $event): void
    {
        $event->update(['status' => WebhookStatus::Received, 'error' => null]);
        ProcessPaymentWebhook::dispatch($event->id);
    }

    /**
     * Conciliação periódica: consulta cobranças em aberto e marca vencidas. Falhas de uma cobrança não interrompem as demais.
     *
     * @return array{synced: int, failed: int, overdue: int}
     */
    public function reconcile(?int $companyId = null, int $limit = 200): array
    {
        $result = ['synced' => 0, 'failed' => 0, 'overdue' => 0];
        $open = PaymentCharge::query()->whereIn('status', [ChargeStatus::Pending, ChargeStatus::Processing, ChargeStatus::PartiallyPaid, ChargeStatus::Overdue, ChargeStatus::Expired])
            ->whereNotNull('provider_charge_id')->where('created_at', '>=', now()->subDays(60))
            ->when($companyId, fn ($query, int $id) => $query->where('company_id', $id))->orderBy('last_synced_at')->limit($limit)->get();

        foreach ($open as $charge) {
            try {
                $this->charges->sync($charge);
                $result['synced']++;
            } catch (Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
        }

        $result['overdue'] = PaymentCharge::query()->where('status', ChargeStatus::Pending)->whereDate('due_date', '<', now()->format('Y-m-d'))
            ->when($companyId, fn ($query, int $id) => $query->where('company_id', $id))->update(['status' => ChargeStatus::Overdue]);

        return $result;
    }
}
