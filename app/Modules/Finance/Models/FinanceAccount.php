<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\AccountType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conta financeira. O saldo atual não é gravado: é o saldo inicial mais a soma das movimentações.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property AccountType $type
 * @property string|null $institution
 * @property string $opening_balance
 * @property CarbonImmutable $opening_date
 * @property bool $is_active
 * @property string|null $notes
 */
class FinanceAccount extends Model
{
    protected $fillable = ['company_id', 'name', 'type', 'institution', 'opening_balance', 'opening_date', 'is_active', 'notes'];

    protected $attributes = ['opening_balance' => '0', 'is_active' => true];

    protected function casts(): array
    {
        return ['type' => AccountType::class, 'opening_balance' => 'decimal:2', 'opening_date' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }

    /** @return HasMany<FinanceMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(FinanceMovement::class);
    }
}
