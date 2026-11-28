<?php

namespace App\Modules\Fiscal\Models;

use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Enums\FiscalOrigin;
use App\Modules\Fiscal\Enums\FiscalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Documento fiscal. Não é uma venda: guarda o que foi documentado (itens, destinatário, totais) e o vínculo opcional
 * com a origem. O `integration_id` é gravado antes da transmissão e identifica o documento no provedor: reenvios por
 * falha ou rejeição atualizam o mesmo documento, nunca criam outro. Documentos autorizados não podem ser excluídos.
 *
 * @property int $id
 * @property int $company_id
 * @property int $local_number
 * @property DocumentType $type
 * @property FiscalStatus $status
 * @property FiscalOrigin $origin
 * @property string|null $origin_type
 * @property int|null $origin_id
 * @property string|null $origin_label
 * @property string|null $provider
 * @property string|null $environment
 * @property string $integration_id
 * @property int|null $receiver_party_id
 * @property array<string, mixed>|null $receiver
 * @property list<array<string, mixed>> $items
 * @property array<string, mixed>|null $tax_data
 * @property string|null $description
 * @property string|null $notes
 * @property string|null $payment_method
 * @property string $items_total
 * @property string $discount_total
 * @property string $total
 * @property string|null $provider_reference
 * @property string|null $provider_status
 * @property string|null $number
 * @property string|null $series
 * @property string|null $access_key
 * @property string|null $authorization_protocol
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $error_message
 * @property string|null $xml_path
 * @property string|null $pdf_path
 * @property string|null $xml_sha256
 * @property string|null $pdf_sha256
 * @property array<string, mixed>|null $request_summary
 * @property array<string, mixed>|null $response_summary
 * @property string $idempotency_key
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, FiscalDocumentEvent> $events
 */
class FiscalDocument extends Model
{
    protected $fillable = [
        'company_id', 'local_number', 'type', 'status', 'origin', 'origin_type', 'origin_id', 'origin_label', 'provider', 'environment', 'integration_id',
        'receiver_party_id', 'receiver', 'items', 'tax_data', 'description', 'notes', 'payment_method', 'items_total', 'discount_total', 'total',
        'provider_reference', 'provider_status', 'number', 'series', 'access_key', 'authorization_protocol', 'issued_at', 'submitted_at', 'cancelled_at',
        'cancel_reason', 'error_message', 'xml_path', 'pdf_path', 'xml_sha256', 'pdf_sha256', 'request_summary', 'response_summary', 'idempotency_key',
        'created_by', 'created_by_name',
    ];

    protected static function booted(): void
    {
        static::deleting(function (FiscalDocument $document): void {
            if ($document->status !== FiscalStatus::Draft) {
                throw new LogicException('Documentos fiscais transmitidos não podem ser excluídos.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => FiscalStatus::class,
            'origin' => FiscalOrigin::class,
            'receiver' => 'array',
            'items' => 'array',
            'tax_data' => 'array',
            'items_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'request_summary' => 'array',
            'response_summary' => 'array',
        ];
    }

    /** @return HasMany<FiscalDocumentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FiscalDocumentEvent::class);
    }

    public function hasFiles(): bool
    {
        return in_array($this->status, [FiscalStatus::Authorized, FiscalStatus::CancelRequested, FiscalStatus::Cancelled], true);
    }
}
