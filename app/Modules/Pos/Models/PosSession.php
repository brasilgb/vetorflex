<?php

namespace App\Modules\Pos\Models;

use App\Modules\Pos\Enums\PosSessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sessão de caixa de um operador num terminal. Fechada, não aceita mais vendas nem movimentações diretas.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $pos_terminal_id
 * @property int|null $open_terminal_id
 * @property PosSessionStatus $status
 * @property int|null $user_id
 * @property string $user_name
 * @property string $opening_amount
 * @property string|null $opening_notes
 * @property CarbonImmutable $opened_at
 * @property bool $is_blind
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by_name
 * @property string|null $expected_cash
 * @property string|null $counted_cash
 * @property string|null $divergence
 * @property string|null $closing_notes
 * @property array<string, mixed>|null $summary
 * @property-read PosTerminal $terminal
 */
class PosSession extends Model
{
    protected $fillable = ['company_id', 'number', 'pos_terminal_id', 'open_terminal_id', 'status', 'user_id', 'user_name', 'opening_amount', 'opening_notes', 'opened_at', 'is_blind', 'closed_at', 'closed_by', 'closed_by_name', 'expected_cash', 'counted_cash', 'divergence', 'closing_notes', 'summary', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => PosSessionStatus::class,
            'opening_amount' => 'decimal:2',
            'opened_at' => 'datetime',
            'is_blind' => 'boolean',
            'closed_at' => 'datetime',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'divergence' => 'decimal:2',
            'summary' => 'array',
        ];
    }

    /** @return BelongsTo<PosTerminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    /** @return HasMany<PosSale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    /** @return HasMany<PosCashMovement, $this> */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === PosSessionStatus::Open;
    }

    /** Referência legível gravada na venda comercial. */
    public function reference(): string
    {
        return "{$this->terminal->name} · sessão {$this->number} · {$this->user_name}";
    }
}
