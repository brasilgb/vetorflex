<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Purchases\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Previsão de vencimento. Não representa pagamento efetuado.
 *
 * @property int $id
 * @property int $sequence
 * @property bool $is_entry
 * @property CarbonImmutable $due_date
 * @property string $amount
 * @property PaymentMethod|null $method
 */
class PurchasePaymentSchedule extends Model
{
    protected $fillable = ['company_id', 'purchase_order_id', 'sequence', 'is_entry', 'due_date', 'amount', 'method'];

    protected function casts(): array
    {
        return ['is_entry' => 'boolean', 'due_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'method' => PaymentMethod::class];
    }
}
