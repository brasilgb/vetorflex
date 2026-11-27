<?php

namespace App\Modules\Fiscal\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Notificação recebida de um provedor fiscal, única por `event_id` (`webhook-id` na Spedy). Guarda apenas o resumo
 * necessário ao processamento, sem dados pessoais completos.
 *
 * @property int $id
 * @property string $provider
 * @property string $event_id
 * @property string $event
 * @property int|null $company_id
 * @property int|null $fiscal_document_id
 * @property array<string, mixed>|null $data
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 */
class FiscalWebhookEvent extends Model
{
    protected $fillable = ['provider', 'event_id', 'event', 'company_id', 'fiscal_document_id', 'data', 'status', 'attempts', 'error', 'processed_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'processed_at' => 'datetime'];
    }
}
