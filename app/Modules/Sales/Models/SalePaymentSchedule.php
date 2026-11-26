<?php

namespace App\Modules\Sales\Models;

use App\Modules\Core\Commerce\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Previsão de recebimento de um orçamento ou pedido. Não representa pagamento recebido.
 *
 * @property int $id
 * @property int $sequence
 * @property bool $is_entry
 * @property CarbonImmutable $due_date
 * @property string $amount
 * @property PaymentMethod|null $method
 */
class SalePaymentSchedule extends Model
{
    protected $fillable = ['company_id', 'sale_quote_id', 'sale_order_id', 'sequence', 'is_entry', 'due_date', 'amount', 'method'];

    protected function casts(): array
    {
        return ['is_entry' => 'boolean', 'due_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'method' => PaymentMethod::class];
    }
}
