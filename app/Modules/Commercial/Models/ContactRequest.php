<?php

namespace App\Modules\Commercial\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pedido de contato comercial enviado pela página pública.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $company_name
 * @property string $message
 * @property list<string>|null $modules
 * @property string|null $ip_address
 * @property CarbonImmutable|null $handled_at
 * @property CarbonImmutable|null $created_at
 */
class ContactRequest extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'company_name', 'message', 'modules', 'ip_address', 'handled_at'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'handled_at' => 'datetime'];
    }
}
