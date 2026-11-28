<?php

namespace App\Modules\Commercial\Services;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\ModuleManager\Models\PlatformModule;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cardápio de aplicações a partir do catálogo central (`platform_modules`), única fonte de verdade. Só módulos
 * implementados e disponibilizados pelo RootAdmin são oferecidos. Seleção, dependências e valores são sempre
 * recalculados aqui; nada vindo do navegador é aceito como preço ou permissão.
 *
 * @phpstan-type Quote array{selected: list<string>, required: list<string>, modules: list<string>, total: string|null, unpriced: list<string>}
 */
class ApplicationCatalog
{
    /** @return Collection<int, PlatformModule> */
    public function available(): Collection
    {
        return PlatformModule::query()->where('commercially_available', true)->where('development_status', PlatformModule::IMPLEMENTED)->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Resolve a seleção: inclui as dependências obrigatórias (transitivas) e soma os valores configurados.
     *
     * @param  list<string>  $identifiers
     * @return Quote
     */
    public function quote(array $identifiers, bool $onlyAvailable = true): array
    {
        // Fora do cadastro público, o RootAdmin pode conceder qualquer módulo do catálogo (como antes), mesmo sem venda aberta.
        $catalog = ($onlyAvailable ? $this->available() : PlatformModule::query()->orderBy('sort_order')->get())->keyBy('identifier');
        $selected = array_values(array_unique(array_filter($identifiers, 'is_string')));

        if ($selected === []) {
            throw ValidationException::withMessages(['modules' => 'Escolha ao menos uma aplicação.']);
        }

        $unknown = array_values(array_diff($selected, $catalog->keys()->all()));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['modules' => 'Aplicação indisponível: '.implode(', ', $unknown).'.']);
        }

        $resolved = [];
        $visit = function (string $identifier, array $path) use (&$visit, &$resolved, $catalog): void {
            if (in_array($identifier, $path, true)) {
                throw ValidationException::withMessages(['modules' => 'Dependência circular no catálogo: '.implode(' → ', [...$path, $identifier]).'.']);
            }

            $module = $catalog->get($identifier) ?? throw ValidationException::withMessages(['modules' => "A aplicação depende de \"{$identifier}\", que não está disponível."]);

            foreach ($module->dependencies ?? [] as $dependency) {
                $visit($dependency, [...$path, $identifier]);
            }

            // Ordem topológica: dependências antes de quem depende delas.
            if (! in_array($identifier, $resolved, true)) {
                $resolved[] = $identifier;
            }
        };

        foreach ($selected as $identifier) {
            $visit($identifier, []);
        }

        $total = BigDecimal::zero();
        $unpriced = [];

        foreach ($resolved as $identifier) {
            $price = $catalog[$identifier]->monthly_price;

            if ($price === null) {
                $unpriced[] = $identifier;
            } else {
                $total = $total->plus((string) $price);
            }
        }

        return [
            'selected' => $selected,
            'required' => array_values(array_diff($resolved, $selected)),
            'modules' => $resolved,
            'total' => $unpriced === $resolved ? null : CommercialMath::money($total),
            'unpriced' => $unpriced,
        ];
    }

    /** @return array<string, mixed> */
    public function present(PlatformModule $module): array
    {
        return [
            'identifier' => $module->identifier,
            'name' => $module->name,
            'tagline' => $module->tagline,
            'description' => $module->description,
            'icon' => $module->icon,
            'category' => $module->category,
            'benefits' => $module->benefits ?? [],
            'monthly_price' => $module->monthly_price === null ? null : (string) $module->monthly_price,
            'dependencies' => $module->dependencies ?? [],
            'optional_integrations' => array_values($module->getAttribute('optional_integrations') ?? []),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function menu(): array
    {
        return array_values($this->available()->map(fn (PlatformModule $module): array => $this->present($module))->all());
    }
}
