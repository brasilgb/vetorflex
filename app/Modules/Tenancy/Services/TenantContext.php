<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Resolve a empresa ativa e as permissões efetivas. O resultado é memorizado apenas na requisição.
 */
class TenantContext
{
    public const SESSION_KEY = 'active_company_id';

    /** @return Collection<int, Company> */
    public function companiesFor(User $user): Collection
    {
        return $user->companies()
            ->where('companies.status', 'active')
            ->wherePivot('is_active', true)
            ->orderBy('companies.name')
            ->get();
    }

    public function activeCompany(Request $request): ?Company
    {
        if ($request->attributes->has('company')) {
            return $request->attributes->get('company');
        }

        $user = $request->user();

        if ($user === null || ! $request->hasSession()) {
            return null;
        }

        $companyId = $request->session()->get(self::SESSION_KEY);
        $company = $companyId === null ? null : $this->authorizedCompany($user, (int) $companyId);

        $request->attributes->set('company', $company);

        return $company;
    }

    public function authorizedCompany(User $user, int $companyId): ?Company
    {
        return $user->companies()
            ->whereKey($companyId)
            ->where('companies.status', 'active')
            ->wherePivot('is_active', true)
            ->first();
    }

    public function membership(Request $request): ?CompanyMembership
    {
        return $this->activeCompany($request)?->getRelation('pivot');
    }

    /** @return Collection<int, PlatformModule> */
    public function enabledModules(Request $request): Collection
    {
        $company = $this->activeCompany($request);

        if ($company === null) {
            return new Collection;
        }

        if (! $request->attributes->has('enabled_modules')) {
            $request->attributes->set('enabled_modules', $this->enabledModulesFor($company));
        }

        return $request->attributes->get('enabled_modules');
    }

    /** @return Collection<int, PlatformModule> */
    public function enabledModulesFor(Company $company): Collection
    {
        return PlatformModule::query()
            ->whereHas('companyModules', fn (Builder $query) => $query->where('company_id', $company->id)->whereNull('deactivated_at'))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Permissões que exigem, ao mesmo tempo, empresa ativa, vínculo ativo, módulo habilitado e concessão individual.
     *
     * @return list<string>
     */
    public function permissions(Request $request): array
    {
        $membership = $this->membership($request);

        if ($membership === null) {
            return [];
        }

        $available = $this->enabledModules($request)
            ->flatMap(fn (PlatformModule $module): array => array_column($module->permissions ?? [], 'key'))
            ->values()
            ->all();

        return $membership->isOwner()
            ? $available
            : array_values(array_intersect($available, $membership->permissions ?? []));
    }

    public function allows(Request $request, string $permission): bool
    {
        return in_array($permission, $this->permissions($request), true);
    }

    public function isOwner(Request $request): bool
    {
        return $this->membership($request)?->isOwner() ?? false;
    }
}
