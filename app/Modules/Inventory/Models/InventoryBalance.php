<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Saldo materializado. Somente InventoryService grava esta tabela.
 *
 * @property int $id
 * @property int $company_id
 * @property int $product_variant_id
 * @property string $quantity
 * @property string|null $average_cost Custo médio ponderado móvel; nulo até o primeiro recebimento com valor.
 */
class InventoryBalance extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'average_cost' => 'decimal:6'];
    }
}
