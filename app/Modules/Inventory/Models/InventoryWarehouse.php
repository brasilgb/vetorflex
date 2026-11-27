<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Depósito ou localização de estoque. Cada empresa com estoque tem um depósito principal (`is_default`), usado quando
 * a operação não informa depósito; `default_company_id` único garante um só principal por empresa.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $code
 * @property string|null $location
 * @property bool $is_default
 * @property bool $is_active
 */
class InventoryWarehouse extends Model
{
    protected $fillable = ['company_id', 'name', 'code', 'location', 'is_default', 'is_active', 'default_company_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
