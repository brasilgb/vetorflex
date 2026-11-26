<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Comprovante, boleto ou nota anexados a um título, em disco privado.
 *
 * @property int $id
 * @property int $company_id
 * @property int $finance_title_id
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property string|null $user_name
 */
class FinanceAttachment extends Model
{
    protected $fillable = ['company_id', 'finance_title_id', 'path', 'original_name', 'mime_type', 'size', 'user_id', 'user_name'];
}
