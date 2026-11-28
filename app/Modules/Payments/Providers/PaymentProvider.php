<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Data\ProviderCapabilities;
use App\Modules\Payments\Data\ProviderCharge;
use App\Modules\Payments\Data\ProviderRefund;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\PaymentTransaction;

/**
 * Contrato de integração com provedores de pagamento. Cada provedor declara as capacidades realmente
 * implementadas; operações não declaradas lançam `ProviderException`. Nenhum método recebe ou devolve dados de
 * cartão.
 */
interface PaymentProvider
{
    public function identifier(): string;

    public function name(): string;

    public function capabilities(): ProviderCapabilities;

    /** @throws ProviderException */
    public function testConnection(PaymentProviderAccount $account): void;

    /** Emite a cobrança Pix com o identificador já gravado em `provider_charge_id`. @throws ProviderException */
    public function createPixCharge(PaymentProviderAccount $account, PaymentCharge $charge): ProviderCharge;

    /** @throws ProviderException */
    public function fetchCharge(PaymentProviderAccount $account, PaymentCharge $charge): ProviderCharge;

    /** @throws ProviderException */
    public function cancelCharge(PaymentProviderAccount $account, PaymentCharge $charge): ProviderCharge;

    /** @throws ProviderException */
    public function refund(PaymentProviderAccount $account, PaymentTransaction $transaction, PaymentRefund $refund): ProviderRefund;

    /** @throws ProviderException */
    public function registerWebhook(PaymentProviderAccount $account, string $url): void;

    /**
     * Identificadores de cobrança citados numa notificação. A notificação só indica o que consultar: a situação vem
     * sempre da consulta ao provedor.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function chargeIdsFromWebhook(array $payload): array;
}
