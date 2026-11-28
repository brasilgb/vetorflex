<?php

namespace App\Modules\Fiscal\Providers;

use App\Modules\Fiscal\Data\FiscalCapabilities;
use App\Modules\Fiscal\Data\ProviderDocument;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Contrato dos provedores de documentos fiscais. Os módulos comerciais nunca dependem de um provedor específico;
 * cada provedor declara as capacidades implementadas e lança `FiscalProviderException` nas demais.
 */
interface FiscalProvider
{
    public function identifier(): string;

    public function name(): string;

    public function capabilities(): FiscalCapabilities;

    /** Diagnóstico da conta da plataforma, sem efeito colateral. */
    public function testPlatform(): void;

    /** Cadastra (ou atualiza) a empresa como emissora e envia as configurações de emissão. */
    public function registerIssuer(FiscalSetting $setting): FiscalSetting;

    /** @return array{subject: string|null, expires_at: CarbonImmutable} */
    public function uploadCertificate(FiscalSetting $setting, string $contents, string $password): array;

    /**
     * Dados que o provedor exige para o documento, já validados.
     *
     * @return array<string, mixed>
     *
     * @throws FiscalValidationException
     */
    public function payload(FiscalDocument $document, FiscalSetting $setting): array;

    /** @param array<string, mixed> $payload */
    public function transmit(FiscalSetting $setting, FiscalDocument $document, array $payload): ProviderDocument;

    /** Consulta sem acionar a autoridade fiscal; localiza pelo identificador de integração se ainda não houver referência. */
    public function find(FiscalSetting $setting, FiscalDocument $document): ?ProviderDocument;

    public function cancel(FiscalSetting $setting, FiscalDocument $document, string $reason): ProviderDocument;

    public function download(FiscalSetting $setting, FiscalDocument $document, string $format): string;

    public function registerWebhook(string $url): void;

    /**
     * Valida a assinatura e extrai a notificação.
     *
     * @return array{event_id: string, event: string, document: ProviderDocument, integration_id: string|null}|null
     */
    public function parseWebhook(Request $request): ?array;
}
