<?php

namespace App\Modules\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ponto de atendimento (caixa, balcão). Sem operadores vinculados, qualquer usuário com `pos.open_session` abre o caixa.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $code
 * @property string|null $location
 * @property bool $is_active
 * @property array{receipt_width?: int}|null $settings
 * @property-read PosSession|null $openSession
 */
class PosTerminal extends Model
{
    protected $fillable = ['company_id', 'name', 'code', 'location', 'is_active', 'settings'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'settings' => 'array'];
    }

    /** @return BelongsToMany<User, $this> */
    public function operators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pos_terminal_operators')->withPivot('company_id')->withTimestamps();
    }

    /** @return HasMany<PosSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(PosSession::class);
    }

    /** @return HasOne<PosSession, $this> */
    public function openSession(): HasOne
    {
        return $this->hasOne(PosSession::class, 'open_terminal_id');
    }

    public function receiptWidth(): int
    {
        return (int) ($this->settings['receipt_width'] ?? 80) === 58 ? 58 : 80;
    }
}
