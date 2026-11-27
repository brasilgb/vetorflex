<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\WebhookStatus;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificação recebida de um provedor, persistida antes de qualquer processamento. O conteúdo idêntico para a mesma
 * conta é gravado uma só vez (`payload_hash`); o processamento é feito por fila e pode ser repetido com autorização.
 *
 * @property int $id
 * @property int|null $company_id
 * @property int|null $payment_provider_account_id
 * @property string $provider
 * @property string $payload_hash
 * @property array<string, mixed> $payload
 * @property WebhookStatus $status
 * @property int $attempts
 * @property string|null $error
 * @property string|null $received_ip
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 */
class PaymentWebhookEvent extends Model
{
    protected $fillable = ['company_id', 'payment_provider_account_id', 'provider', 'payload_hash', 'payload', 'status', 'attempts', 'error', 'received_ip', 'processed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'status' => WebhookStatus::class, 'processed_at' => 'datetime'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
