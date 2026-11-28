<?php

namespace App\Modules\Commercial\Data;

use App\Models\User;

/**
 * Pedido de criação de uma empresa assinante, igual para o cadastro público e para o RootAdmin.
 */
readonly class ProvisioningRequest
{
    /**
     * @param  list<string>  $modules  Aplicações escolhidas; as dependências são resolvidas pelo serviço.
     * @param  int|null  $trialDays  Dias de teste; nulo registra a empresa já contratada.
     * @param  bool  $onlyAvailable  Cadastro público: só aplicações disponibilizadas comercialmente.
     * @param  string|null  $slug  Identificador escolhido pelo RootAdmin; sem ele, gerado a partir do nome.
     */
    public function __construct(
        public string $key,
        public ?User $owner,
        public User $actor,
        public string $companyName,
        public array $modules,
        public ?string $document = null,
        public string $timezone = 'America/Sao_Paulo',
        public ?int $trialDays = 14,
        public string $origin = 'public',
        public bool $onlyAvailable = true,
        public ?string $slug = null,
    ) {}
}
