<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;

/**
 * Registro de mudanças de situação (usuário, data e hora, situação anterior e nova, motivo) e auditoria.
 */
class SaleHistory
{
    public function __construct(private AuditService $audit) {}

    public function record(SaleQuote|SaleOrder $document, ?string $from, string $to, ?User $user, ?string $reason): void
    {
        $document->statusHistory()->create([
            'company_id' => $document->company_id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'user_id' => $user?->id,
            'user_name' => $user->name ?? 'Sistema',
            'occurred_at' => now(),
        ]);

        if ($from !== null) {
            $prefix = $document instanceof SaleOrder ? 'sales.order_status_changed' : 'sales.quote_status_changed';
            $this->audit->record($prefix, $document->company, $user, $document, ['number' => $document->number, 'from' => $from, 'to' => $to]);
        }
    }
}
