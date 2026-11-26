<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quantidade reservada por um documento de origem. Somente InventoryReservationService grava esta tabela.
 *
 * @property int $id
 * @property int $company_id
 * @property int $product_variant_id
 * @property string $reference_type
 * @property string $reference_id
 * @property string $quantity
 */
class InventoryReservation extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }
}
