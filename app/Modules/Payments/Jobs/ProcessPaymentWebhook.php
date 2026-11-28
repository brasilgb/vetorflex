<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Enums\WebhookStatus;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processamento de um evento de webhook na fila, com retentativas espaçadas. Esgotadas as tentativas, o evento fica
 * com falha registrada e pode ser reprocessado por quem tem `payments.reprocess`.
 */
class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $eventId) {}

    public function handle(WebhookService $webhooks): void
    {
        $event = PaymentWebhookEvent::query()->find($this->eventId);

        if ($event === null || $event->status === WebhookStatus::Processed) {
            return;
        }

        $webhooks->process($event);
    }

    public function failed(?Throwable $exception): void
    {
        PaymentWebhookEvent::query()->whereKey($this->eventId)->update(['status' => WebhookStatus::Failed]);
    }
}
