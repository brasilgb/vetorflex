<?php

namespace App\Modules\Fiscal\Providers;

use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Models\FiscalProviderAvailability;
use App\Modules\Fiscal\Providers\Spedy\SpedyProvider;
use Illuminate\Validation\ValidationException;

/**
 * Provedores fiscais implementados. Um novo provedor entra aqui somente quando implementado de fato.
 */
class FiscalProviderRegistry
{
    /** @var array<string, FiscalProvider> */
    private array $providers;

    public function __construct(SpedyProvider $spedy)
    {
        $this->providers = [$spedy->identifier() => $spedy];
    }

    public function get(string $identifier): FiscalProvider
    {
        return $this->providers[$identifier] ?? throw ValidationException::withMessages(['provider' => 'Provedor fiscal não disponível.']);
    }

    /** @return array<string, FiscalProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    public function availability(string $identifier): ?FiscalProviderAvailability
    {
        return isset($this->providers[$identifier]) ? FiscalProviderAvailability::query()->where('identifier', $identifier)->first() : null;
    }

    public function isAvailable(string $identifier): bool
    {
        return $this->availability($identifier)?->is_available === true;
    }

    /** Tipo liberado pela plataforma (RootAdmin) e efetivamente implementado pelo provedor. */
    public function allowsType(string $identifier, DocumentType $type): bool
    {
        $availability = $this->availability($identifier);

        return $availability !== null && $availability->is_available
            && in_array($type->value, $availability->document_types ?? [], true)
            && $this->providers[$identifier]->capabilities()->supports($type);
    }

    /** Garante o registro de cada provedor implementado, indisponível até decisão do RootAdmin. */
    public function ensureRegistered(): void
    {
        foreach ($this->providers as $identifier => $provider) {
            FiscalProviderAvailability::query()->firstOrCreate(['identifier' => $identifier], ['name' => $provider->name(), 'is_available' => false, 'document_types' => []]);
        }
    }
}
