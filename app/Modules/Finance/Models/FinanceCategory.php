<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\CategoryKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Categoria gerencial de receita ou despesa, com um nível opcional de subcategoria. Não é plano contábil.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $parent_id
 * @property CategoryKind $kind
 * @property string $name
 * @property bool $is_active
 * @property-read FinanceCategory|null $parent
 */
class FinanceCategory extends Model
{
    protected $fillable = ['company_id', 'parent_id', 'kind', 'name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['kind' => CategoryKind::class, 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<FinanceCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function fullName(): string
    {
        return $this->parent === null ? $this->name : "{$this->parent->name} › {$this->name}";
    }
}
