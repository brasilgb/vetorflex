<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Enums\CompanyRole;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMembership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Concessão de permissões individuais pelo painel da empresa, comum a todos os módulos.
 *
 * Regras: o proprietário administra administradores e operadores; o administrador administra apenas operadores.
 * Ninguém altera o próprio acesso, o de um proprietário ou o papel RootAdmin, e só se concede o que se possui.
 * Permissões de módulos desativados, ou que o responsável não possui, são preservadas como estavam.
 */
class MemberPermissionService
{
    public function __construct(private AuditService $audit) {}

    public function canManageTeam(CompanyMembership $actor): bool
    {
        return $actor->isOwner() || $actor->role === CompanyRole::Administrator;
    }

    public function canManage(CompanyMembership $actor, User $actorUser, CompanyMembership $target, User $targetUser): bool
    {
        return $actorUser->id !== $targetUser->id
            && $target->role !== CompanyRole::Owner
            && ($actor->isOwner() || ($actor->role === CompanyRole::Administrator && $target->role === CompanyRole::Operator));
    }

    /**
     * Permissões que o responsável pode conceder ou revogar: as dos módulos habilitados que ele próprio possui.
     *
     * @param  Collection<int, PlatformModule>  $modules
     * @param  list<string>  $actorPermissions  Permissões efetivas do responsável.
     * @return list<string>
     */
    public function grantable(Collection $modules, array $actorPermissions): array
    {
        $available = $modules->flatMap(fn (PlatformModule $module): array => array_column($module->permissions ?? [], 'key'))->all();

        return array_values(array_intersect($available, $actorPermissions));
    }

    /**
     * @param  list<string>  $requested  Estado desejado das permissões que o responsável pode administrar.
     * @param  list<string>  $grantable
     */
    public function update(Company $company, User $actorUser, CompanyMembership $actor, User $targetUser, array $requested, array $grantable): void
    {
        DB::transaction(function () use ($company, $actorUser, $actor, $targetUser, $requested, $grantable): void {
            /** @var CompanyMembership|null $target */
            $target = CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $targetUser->id)->lockForUpdate()->first();

            if ($target === null || ! $this->canManage($actor, $actorUser, $target, $targetUser)) {
                throw ValidationException::withMessages(['permissions' => 'Você não pode alterar as permissões deste usuário.']);
            }

            $forbidden = array_diff($requested, $grantable);

            if ($forbidden !== []) {
                throw ValidationException::withMessages(['permissions' => 'Você só pode conceder permissões que possui: '.implode(', ', $forbidden).'.']);
            }

            $current = $target->permissions ?? [];
            $kept = array_diff($current, $grantable);
            $next = array_values(array_unique([...$kept, ...$requested]));
            sort($next);
            $granted = array_values(array_diff($next, $current));
            $revoked = array_values(array_diff($current, $next));

            if ($granted === [] && $revoked === []) {
                return;
            }

            $target->permissions = $next;
            $target->save();
            $this->audit->record('company.permissions_changed', $company, $actorUser, $targetUser, ['granted' => $granted, 'revoked' => $revoked, 'user_name' => $targetUser->name]);
        });
    }
}
