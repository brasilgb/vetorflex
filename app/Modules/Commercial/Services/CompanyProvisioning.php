<?php

namespace App\Modules\Commercial\Services;

use App\Models\User;
use App\Modules\Commercial\Data\ProvisioningRequest;
use App\Modules\Commercial\Enums\CommercialStatus;
use App\Modules\Core\Services\AuditService;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\ModuleManager\Services\ModuleActivationService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Único ponto de criação de empresas assinantes (cadastro público e RootAdmin): empresa, proprietário, módulos com
 * dependências na ordem correta, configurações padrão e período de teste, tudo numa transação. Idempotente pela chave:
 * repetir o pedido devolve a mesma empresa. Também amplia ou reduz as aplicações de uma empresa existente sem
 * reiniciar o período de teste.
 */
class CompanyProvisioning
{
    public function __construct(private ApplicationCatalog $catalog, private ModuleActivationService $activation, private AuditService $audit) {}

    public function provision(ProvisioningRequest $request): Company
    {
        $existing = Company::query()->where('provisioning_key', $request->key)->first();

        if ($existing !== null) {
            return $existing;
        }

        // O RootAdmin pode cadastrar a empresa antes de escolher as aplicações; o cadastro público exige ao menos uma.
        $quote = $request->modules === [] && ! $request->onlyAvailable
            ? ['selected' => [], 'required' => [], 'modules' => [], 'total' => null, 'unpriced' => []]
            : $this->catalog->quote($request->modules, $request->onlyAvailable);

        try {
            return DB::transaction(function () use ($request, $quote): Company {
                $now = now();
                $company = Company::create([
                    'name' => Str::limit(trim($request->companyName), 160, ''),
                    'slug' => $request->slug ?? $this->slug($request->companyName),
                    'status' => 'active',
                    'timezone' => $request->timezone,
                    'currency' => 'BRL',
                    'document' => preg_replace('/\D+/', '', (string) $request->document) ?: null,
                    'commercial_status' => $request->trialDays === null ? CommercialStatus::Active : CommercialStatus::Trial,
                    'trial_started_at' => $request->trialDays === null ? null : $now,
                    'trial_ends_at' => $request->trialDays === null ? null : $now->copy()->addDays($request->trialDays)->endOfDay(),
                    'contracted_at' => $request->trialDays === null ? $now : null,
                    'origin' => $request->origin,
                    'provisioning_key' => $request->key,
                ]);
                if ($request->owner !== null) {
                    $company->users()->attach($request->owner, ['role' => 'owner', 'is_active' => true, 'permissions' => []]);
                }

                $modules = PlatformModule::query()->whereIn('identifier', $quote['modules'])->get()->keyBy('identifier');

                foreach ($quote['modules'] as $identifier) {
                    $this->activation->activate($company, $modules[$identifier], $request->actor);
                }

                $this->audit->record('company.created', $company, $request->actor, $company, [
                    'origin' => $request->origin,
                    'modules' => $quote['modules'],
                    'required' => $quote['required'],
                    'trial_ends_at' => $company->trial_ends_at?->toIso8601String(),
                ]);

                return $company;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Pedido concorrente com a mesma chave: a outra transação criou a empresa.
            return Company::query()->where('provisioning_key', $request->key)->first() ?? throw $exception;
        }
    }

    /**
     * Acrescenta aplicações (com dependências) à empresa. Valores e disponibilidade vêm do catálogo.
     *
     * @param  list<string>  $identifiers
     * @return list<string> Aplicações efetivamente ativadas.
     */
    public function addApplications(Company $company, User $actor, array $identifiers, bool $onlyAvailable = true): array
    {
        $quote = $this->catalog->quote($identifiers, $onlyAvailable);

        return DB::transaction(function () use ($company, $actor, $quote): array {
            $active = $this->activeIdentifiers($company);
            $modules = PlatformModule::query()->whereIn('identifier', $quote['modules'])->get()->keyBy('identifier');
            $activated = [];

            foreach ($quote['modules'] as $identifier) {
                if (! in_array($identifier, $active, true)) {
                    $this->activation->activate($company, $modules[$identifier], $actor);
                    $activated[] = $identifier;
                }
            }

            return $activated;
        });
    }

    /** Remove uma aplicação. Dependências de outras aplicações ativas não podem sair; os dados são preservados. */
    public function removeApplication(Company $company, User $actor, string $identifier): void
    {
        $module = PlatformModule::query()->where('identifier', $identifier)->first() ?? throw ValidationException::withMessages(['module' => 'Aplicação não encontrada.']);
        $dependents = PlatformModule::query()->whereIn('identifier', $this->activeIdentifiers($company))->get()
            ->filter(fn (PlatformModule $other): bool => in_array($identifier, $other->dependencies ?? [], true))->pluck('name')->all();

        if ($dependents !== []) {
            throw ValidationException::withMessages(['module' => "{$module->name} é necessária para: ".implode(', ', $dependents).'. Remova essas aplicações antes.']);
        }

        $this->activation->deactivate($company, $module, $actor);
    }

    /** @return list<string> */
    public function activeIdentifiers(Company $company): array
    {
        return array_values(PlatformModule::query()->whereIn('id', CompanyModule::query()->where('company_id', $company->id)->whereNull('deactivated_at')->select('platform_module_id'))->pluck('identifier')->all());
    }

    private function slug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'empresa', 60, '');
        $slug = $base;

        while (Company::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
