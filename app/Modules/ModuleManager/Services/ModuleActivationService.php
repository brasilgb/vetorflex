<?php

namespace App\Modules\ModuleManager\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModuleActivationService
{
    public function __construct(private AuditService $audit) {}

    public function activate(Company $company, PlatformModule $module, User $actor): CompanyModule
    {
        return DB::transaction(function () use ($company, $module, $actor): CompanyModule {
            $this->ensureDependenciesAreActive($company, $module);

            $assignment = CompanyModule::query()->firstOrNew([
                'company_id' => $company->id,
                'platform_module_id' => $module->id,
            ]);
            $assignment->fill(['activated_by' => $actor->id, 'activated_at' => now(), 'deactivated_at' => null]);
            $assignment->save();

            $this->audit->record('module.activated', $company, $actor, $assignment, ['module' => $module->identifier]);

            return $assignment;
        });
    }

    public function deactivate(Company $company, PlatformModule $module, User $actor): CompanyModule
    {
        return DB::transaction(function () use ($company, $module, $actor): CompanyModule {
            $dependents = PlatformModule::query()
                ->whereJsonContains('dependencies', $module->identifier)
                ->pluck('id');

            $hasActiveDependent = CompanyModule::query()
                ->where('company_id', $company->id)
                ->whereIn('platform_module_id', $dependents)
                ->whereNull('deactivated_at')
                ->exists();

            if ($hasActiveDependent) {
                throw ValidationException::withMessages(['module' => 'Desative primeiro os módulos que dependem deste módulo.']);
            }

            $assignment = CompanyModule::query()
                ->where('company_id', $company->id)
                ->where('platform_module_id', $module->id)
                ->whereNull('deactivated_at')
                ->firstOrFail();
            $assignment->update(['deactivated_at' => now()]);
            $this->audit->record('module.deactivated', $company, $actor, $assignment, ['module' => $module->identifier]);

            return $assignment;
        });
    }

    private function ensureDependenciesAreActive(Company $company, PlatformModule $module): void
    {
        $dependencies = $module->dependencies ?? [];

        if ($dependencies === []) {
            return;
        }

        $activeDependencies = PlatformModule::query()
            ->whereIn('identifier', $dependencies)
            ->whereHas('companyModules', fn ($query) => $query->where('company_id', $company->id)->whereNull('deactivated_at'))
            ->pluck('identifier')
            ->all();

        $missingDependencies = array_values(array_diff($dependencies, $activeDependencies));

        if ($missingDependencies !== []) {
            throw ValidationException::withMessages(['module' => 'Dependências não ativas: '.implode(', ', $missingDependencies).'.']);
        }
    }
}
