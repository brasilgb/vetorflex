<?php

namespace App\Modules\Fiscal\Jobs;

use App\Modules\Fiscal\Models\FiscalWebhookEvent;
use App\Modules\Fiscal\Services\FiscalWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processamento de uma notificação fiscal na fila. Esgotadas as tentativas, fica com falha e pode ser reprocessada
 * por quem tem `fiscal.reprocess`.
 */
class ProcessFiscalWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $eventId) {}

    public function handle(FiscalWebhookService $webhooks): void
    {
        $event = FiscalWebhookEvent::query()->find($this->eventId);

        if ($event !== null && $event->status !== FiscalWebhookService::PROCESSED) {
            $webhooks->process($event);
        }
    }

    public function failed(?Throwable $exception): void
    {
        FiscalWebhookEvent::query()->whereKey($this->eventId)->update(['status' => FiscalWebhookService::FAILED]);
    }
}
