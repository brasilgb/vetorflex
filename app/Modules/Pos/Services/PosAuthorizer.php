<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMembership;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Autorização de operações sensíveis no caixa. Quem tem a permissão da operação (ou `pos.authorize`) segue direto;
 * os demais precisam das credenciais de um supervisor ativo da mesma empresa com `pos.authorize`. As tentativas são
 * limitadas por operador, e quem autorizou fica registrado na operação.
 */
class PosAuthorizer
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private PosModule $module) {}

    /**
     * @param  array{email?: string|null, password?: string|null}|null  $credentials
     * @return User Quem responde pela operação: o próprio operador ou o supervisor.
     */
    public function authorize(Company $company, User $operator, ?PosPermission $permission, ?array $credentials, string $operation): User
    {
        if (($permission !== null && $this->can($company, $operator, $permission)) || $this->can($company, $operator, PosPermission::Authorize)) {
            return $operator;
        }

        if (blank($credentials['email'] ?? null) || blank($credentials['password'] ?? null)) {
            throw ValidationException::withMessages(['authorization' => "{$operation}: esta operação exige autorização de um supervisor."]);
        }

        $key = "pos-authorize:{$company->id}:{$operator->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['authorization' => 'Muitas tentativas de autorização. Aguarde um minuto.']);
        }

        $supervisor = User::query()->where('email', mb_strtolower(trim((string) $credentials['email'])))->first();

        if ($supervisor === null || ! Hash::check((string) $credentials['password'], $supervisor->password) || ! $this->can($company, $supervisor, PosPermission::Authorize)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['authorization' => 'Credenciais de supervisor inválidas ou sem permissão para autorizar.']);
        }

        RateLimiter::clear($key);

        return $supervisor;
    }

    /** Permissão de qualquer usuário na empresa: vínculo ativo, PDV habilitado e concessão individual (ou proprietário). */
    public function can(Company $company, User $user, PosPermission $permission): bool
    {
        $membership = CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $user->id)->where('is_active', true)->first();

        if ($membership === null || $company->status !== 'active' || ! $this->module->isEnabled($company)) {
            return false;
        }

        return $membership->isOwner() || in_array($permission->value, $membership->permissions ?? [], true);
    }
}
