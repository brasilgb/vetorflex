<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Models\PaymentProviderAvailability;
use Illuminate\Validation\ValidationException;

/**
 * Provedores implementados. Novos provedores (por exemplo, Sicredi) entram aqui somente quando implementados de
 * fato; não há integrações fictícias.
 */
class ProviderRegistry
{
    /** @var array<string, PaymentProvider> */
    private array $providers;

    public function __construct(EfiProvider $efi)
    {
        $this->providers = [$efi->identifier() => $efi];
    }

    public function get(string $identifier): PaymentProvider
    {
        return $this->providers[$identifier] ?? throw ValidationException::withMessages(['provider' => 'Provedor de pagamento não disponível.']);
    }

    /** @return array<string, PaymentProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    public function isAvailable(string $identifier): bool
    {
        return isset($this->providers[$identifier]) && PaymentProviderAvailability::query()->where('identifier', $identifier)->where('is_available', true)->exists();
    }

    /** Garante o registro de disponibilidade de cada provedor implementado, sem alterar a decisão do RootAdmin. */
    public function ensureRegistered(): void
    {
        foreach ($this->providers as $identifier => $provider) {
            PaymentProviderAvailability::query()->firstOrCreate(['identifier' => $identifier], ['name' => $provider->name(), 'is_available' => false]);
        }
    }
}
