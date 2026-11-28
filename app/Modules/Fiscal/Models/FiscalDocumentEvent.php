<?php

namespace App\Modules\Fiscal\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Histórico imutável do documento fiscal.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_document_id
 * @property string $event
 * @property string $description
 * @property string|null $from_status
 * @property string|null $to_status
 * @property int|null $user_id
 * @property string|null $user_name
 * @property CarbonImmutable|null $created_at
 */
class FiscalDocumentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'fiscal_document_id', 'event', 'description', 'from_status', 'to_status', 'user_id', 'user_name'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O histórico fiscal é imutável.'));
        static::deleting(fn () => throw new LogicException('O histórico fiscal não pode ser excluído.'));
    }
}
