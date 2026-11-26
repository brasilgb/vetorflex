<?php

namespace App\Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Importação em duas etapas. As linhas lidas ficam criptografadas apenas até a confirmação.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $file_name
 * @property string $status
 * @property list<array{line: int, data: array<string, string>, errors: list<string>}>|null $rows
 * @property array{created: int, updated: int, linked: int, skipped: int, failed: int, errors: list<array{line: int, name: string, messages: list<string>}>}|null $report
 */
class SupplierImport extends Model
{
    use MassPrunable;

    public const PREVIEW = 'preview';

    public const COMPLETED = 'completed';

    protected $fillable = ['company_id', 'user_id', 'file_name', 'status', 'rows', 'report', 'completed_at'];

    protected $hidden = ['rows'];

    protected function casts(): array
    {
        return ['rows' => 'encrypted:array', 'report' => 'array', 'completed_at' => 'datetime'];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('status', self::PREVIEW)->where('created_at', '<', now()->subDay());
    }
}
