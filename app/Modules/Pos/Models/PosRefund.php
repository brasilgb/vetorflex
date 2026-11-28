<?php

namespace App\Modules\Pos\Models;

use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Sales\Models\SaleReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Restituição ao cliente de uma devolução feita no caixa.
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_sale_id
 * @property int $sale_return_id
 * @property int|null $pos_session_id
 * @property PaymentMethod $method
 * @property string $amount
 * @property string $user_name
 * @property string|null $authorized_by_name
 * @property-read SaleReturn $saleReturn
 */
class PosRefund extends Model
{
    protected $fillable = ['company_id', 'pos_sale_id', 'sale_return_id', 'pos_session_id', 'method', 'amount', 'user_id', 'user_name', 'authorized_by_name'];

    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<SaleReturn, $this> */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }
}
