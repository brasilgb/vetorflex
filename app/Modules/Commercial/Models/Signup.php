<?php

namespace App\Modules\Commercial\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cadastro público. Guarda a escolha de aplicações e os dados da empresa até a verificação do e-mail; o
 * provisionamento usa o registro como chave idempotente e pode ser repetido com segurança após falha.
 *
 * @property int $id
 * @property string $idempotency_key
 * @property int|null $user_id
 * @property string $email
 * @property string $company_name
 * @property string|null $company_document
 * @property list<string> $modules
 * @property string $terms_version
 * @property CarbonImmutable $accepted_terms_at
 * @property string $status `pending`, `provisioned` ou `failed`.
 * @property int|null $company_id
 * @property string|null $error
 * @property string|null $ip_address
 * @property CarbonImmutable|null $provisioned_at
 * @property-read User|null $user
 * @property-read Company|null $company
 */
class Signup extends Model
{
    public const PENDING = 'pending';

    public const PROVISIONED = 'provisioned';

    public const FAILED = 'failed';

    protected $fillable = ['idempotency_key', 'user_id', 'email', 'company_name', 'company_document', 'modules', 'terms_version', 'accepted_terms_at', 'status', 'company_id', 'error', 'ip_address', 'provisioned_at'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'accepted_terms_at' => 'datetime', 'provisioned_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
