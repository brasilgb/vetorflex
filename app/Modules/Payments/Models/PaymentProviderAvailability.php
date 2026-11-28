<?php

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Disponibilidade de cada provedor na plataforma, decidida pelo RootAdmin. Só provedores disponíveis podem ser
 * configurados pelas empresas; contas existentes de um provedor indisponibilizado deixam de emitir cobranças.
 *
 * @property int $id
 * @property string $identifier
 * @property string $name
 * @property bool $is_available
 */
class PaymentProviderAvailability extends Model
{
    protected $table = 'payment_providers';

    protected $fillable = ['identifier', 'name', 'is_available'];

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }
}
