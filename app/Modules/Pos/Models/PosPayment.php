<?php

namespace App\Modules\Pos\Models;

use App\Modules\Core\Commerce\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pagamento informado pelo operador. `is_received` distingue o recebido no ato (sem processamento bancário) do valor
 * a prazo; `schedule_sequence` liga o pagamento ao vencimento da venda comercial.
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_sale_id
 * @property int $sequence
 * @property PaymentMethod $method
 * @property string $amount
 * @property string|null $tendered
 * @property string $change
 * @property bool $is_received
 * @property CarbonImmutable $due_date
 * @property int|null $schedule_sequence
 * @property int|null $payment_charge_id Cobrança Pix confirmada pelo provedor (módulo Pagamentos), quando houver.
 * @property bool $is_provider_confirmed Pagamento confirmado pelo provedor, e não apenas informado pelo operador.
 */
class PosPayment extends Model
{
    protected $fillable = ['company_id', 'pos_sale_id', 'sequence', 'method', 'amount', 'tendered', 'change', 'is_received', 'due_date', 'schedule_sequence', 'payment_charge_id', 'is_provider_confirmed'];

    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'amount' => 'decimal:2', 'tendered' => 'decimal:2', 'change' => 'decimal:2', 'is_received' => 'boolean', 'is_provider_confirmed' => 'boolean', 'due_date' => 'date:Y-m-d'];
    }
}
