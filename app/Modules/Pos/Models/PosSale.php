<?php

namespace App\Modules\Pos\Models;

use App\Modules\Pos\Enums\PosSaleStatus;
use App\Modules\Sales\Models\SaleOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Atendimento no caixa. Concluído, aponta para a venda comercial única (`sale_orders`, origem PDV); suspenso, guarda o
 * carrinho sem reserva, saída de estoque ou movimento financeiro.
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_session_id
 * @property int $pos_terminal_id
 * @property int|null $sale_order_id
 * @property PosSaleStatus $status
 * @property int|null $user_id
 * @property string $user_name
 * @property int|null $customer_party_id
 * @property string|null $label
 * @property array<string, mixed>|null $cart
 * @property string $total
 * @property string $received_total
 * @property string $term_total
 * @property string $change_total
 * @property string $refunded_total
 * @property string|null $authorized_by_name
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by_name
 * @property string|null $cancellation_reason
 * @property int $reprints
 * @property string $idempotency_key
 * @property string|null $request_hash
 * @property-read PosSession $session
 * @property-read PosTerminal $terminal
 * @property-read SaleOrder|null $order
 */
class PosSale extends Model
{
    protected $fillable = ['company_id', 'pos_session_id', 'pos_terminal_id', 'sale_order_id', 'status', 'user_id', 'user_name', 'customer_party_id', 'label', 'cart', 'total', 'received_total', 'term_total', 'change_total', 'refunded_total', 'authorized_by_name', 'suspended_at', 'completed_at', 'cancelled_at', 'cancelled_by_name', 'cancellation_reason', 'reprints', 'idempotency_key', 'request_hash'];

    protected $attributes = ['total' => '0', 'received_total' => '0', 'term_total' => '0', 'change_total' => '0', 'refunded_total' => '0', 'reprints' => 0];

    protected function casts(): array
    {
        return [
            'status' => PosSaleStatus::class,
            'cart' => 'array',
            'total' => 'decimal:2',
            'received_total' => 'decimal:2',
            'term_total' => 'decimal:2',
            'change_total' => 'decimal:2',
            'refunded_total' => 'decimal:2',
            'suspended_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reprints' => 'integer',
        ];
    }

    /** @return BelongsTo<PosSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /** @return BelongsTo<PosTerminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    /** @return BelongsTo<SaleOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class, 'sale_order_id');
    }

    /** @return HasMany<PosPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PosPayment::class)->orderBy('sequence');
    }

    /** @return HasMany<PosRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PosRefund::class);
    }
}
