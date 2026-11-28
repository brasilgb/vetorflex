<?php

namespace App\Modules\Fiscal\Providers\Spedy;

use App\Modules\Fiscal\Data\FiscalCapabilities;
use App\Modules\Fiscal\Data\ProviderDocument;
use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Enums\FiscalStatus;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalProviderAvailability;
use App\Modules\Fiscal\Models\FiscalSetting;
use App\Modules\Fiscal\Providers\FiscalProvider;
use App\Modules\Fiscal\Providers\FiscalProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Spedy (API v1). Reaproveita o que está em produção no VetorOS: cadastro da empresa como emissora pela chave da
 * empresa titular (a chave da emissora só é exibida na criação e é gravada antes de qualquer outra chamada), envio do
 * certificado A1 direto ao provedor, emissão assíncrona com `integrationId` estável (reenvio atualiza a mesma nota),
 * consulta sem acionar a SEFAZ, cancelamento, XML/PDF, mapa de estados e webhook assinado (Standard Webhooks).
 *
 * Declara NFS-e, NF-e e NFC-e, conforme a documentação vigente; a cobertura de cada município e UF é da Spedy e
 * aparece como rejeição explícita quando não houver. Contingência e carta de correção não são declaradas.
 */
class SpedyProvider implements FiscalProvider
{
    private const STATUS_MAP = [
        'created' => FiscalStatus::Processing,
        'enqueued' => FiscalStatus::Processing,
        'received' => FiscalStatus::Processing,
        // Contingência não é declarada pelo VetorFlex: o documento segue "Processando" até a autorização.
        'inContingent' => FiscalStatus::Processing,
        'authorized' => FiscalStatus::Authorized,
        'rejected' => FiscalStatus::Rejected,
        'denied' => FiscalStatus::Denied,
        'canceled' => FiscalStatus::Cancelled,
        'removed' => FiscalStatus::CommunicationFailure,
        'disabled' => FiscalStatus::CommunicationFailure,
    ];

    /** Códigos SPD transitórios (docs.spedy.com.br, "Erros e rejeições"). */
    private const TRANSITIONAL_CODES = ['SPD001', 'SPD002', 'SPD006', 'SPD998', 'SPD999'];

    public function __construct(private SpedyPayloadBuilder $payloads) {}

    public function identifier(): string
    {
        return 'spedy';
    }

    public function name(): string
    {
        return 'Spedy';
    }

    public function capabilities(): FiscalCapabilities
    {
        return new FiscalCapabilities(
            types: [DocumentType::Nfse, DocumentType::Nfe, DocumentType::Nfce],
            query: true,
            cancel: true,
            xml: true,
            pdf: true,
            webhooks: true,
            issuerRegistration: true,
            certificateUpload: true,
        );
    }

    public function testPlatform(): void
    {
        $this->owner()->listCompanies();
    }

    public function registerIssuer(FiscalSetting $setting): FiscalSetting
    {
        $payload = $this->payloads->company($setting);
        $environment = $this->platform()->environment;

        // Vínculo de outro ambiente (sandbox ↔ produção são contas separadas): o de sandbox é refeito; o de
        // produção nunca é descartado, pois a chave da emissora não pode ser recuperada.
        if (filled($setting->provider_company_id) && $setting->provider_environment !== $environment) {
            if ($setting->provider_environment === 'production') {
                throw new FiscalProviderException('O cadastro desta empresa pertence à conta de produção da Spedy, mas a plataforma está em sandbox.');
            }

            $setting->forceFill(['provider_company_id' => null, 'provider_credentials' => null, 'provider_environment' => null, 'registration_status' => FiscalSetting::REGISTRATION_PENDING, 'certificate_subject' => null, 'certificate_expires_at' => null])->save();
        }

        if (blank($setting->provider_company_id)) {
            DB::transaction(function () use ($setting, $payload, $environment): void {
                $locked = FiscalSetting::query()->lockForUpdate()->findOrFail($setting->id);

                if (filled($locked->provider_company_id)) {
                    $setting->setRawAttributes($locked->getAttributes(), true);

                    return;
                }

                $owner = $this->owner();
                $existing = $owner->findCompanyByFederalTaxNumber($payload['federalTaxNumber']);

                if ($existing !== null) {
                    throw new FiscalProviderException(sprintf('O CNPJ já está cadastrado na conta Spedy (%s, empresa %s). A chave dessa empresa só é exibida na criação e não pode ser recuperada pela API: remova o cadastro duplicado no painel da Spedy ou contate o suporte.', $environment, (string) ($existing['id'] ?? '?')));
                }

                $response = $owner->createCompany($payload);
                $companyId = $response['id'] ?? null;
                $apiKey = $response['apiCredentials']['apiKey'] ?? null;

                if (blank($companyId) || blank($apiKey)) {
                    throw new FiscalProviderException('O serviço de emissão fiscal não retornou as credenciais da empresa. Contate o suporte.');
                }

                // A chave só é exibida uma vez pela Spedy: gravada antes de qualquer outra chamada.
                $locked->forceFill(['provider' => 'spedy', 'provider_company_id' => (string) $companyId, 'provider_credentials' => ['api_key' => (string) $apiKey], 'provider_environment' => $environment])->save();
                $setting->setRawAttributes($locked->getAttributes(), true);
            });
        } else {
            $this->owner()->updateCompany((string) $setting->provider_company_id, $payload);
        }

        $this->owner()->updateCompanySettings((string) $setting->provider_company_id, $this->payloads->companySettings($setting));

        return $setting;
    }

    public function uploadCertificate(FiscalSetting $setting, string $contents, string $password): array
    {
        if (blank($setting->provider_company_id)) {
            throw new FiscalProviderException('Conclua o cadastro da empresa emissora antes de enviar o certificado.');
        }

        $certificate = $this->owner()->uploadCertificate((string) $setting->provider_company_id, $contents, $password);

        try {
            $expiresAt = filled($certificate['expirationAt'] ?? null) ? CarbonImmutable::parse((string) $certificate['expirationAt']) : null;
        } catch (\Throwable) {
            throw new FiscalProviderException('O emissor retornou uma validade inválida para o certificado.');
        }

        // Um HTTP 2xx sozinho não confirma o certificado: exige validade vigente informada pelo provedor.
        if ($expiresAt === null || $expiresAt->isPast()) {
            throw new FiscalProviderException('O emissor não confirmou um certificado vigente. Confira o arquivo e a senha.');
        }

        return ['subject' => is_string($certificate['subject'] ?? null) ? mb_substr($certificate['subject'], 0, 255) : null, 'expires_at' => $expiresAt];
    }

    public function payload(FiscalDocument $document, FiscalSetting $setting): array
    {
        return $this->payloads->invoice($document, $setting);
    }

    public function transmit(FiscalSetting $setting, FiscalDocument $document, array $payload): ProviderDocument
    {
        return $this->toDocument($this->company($setting)->createInvoice($document->type->value, $payload));
    }

    public function find(FiscalSetting $setting, FiscalDocument $document): ?ProviderDocument
    {
        $client = $this->company($setting);

        if (blank($document->provider_reference)) {
            $invoice = $client->findInvoiceByIntegrationId($document->type->value, $document->integration_id);

            return $invoice === null ? null : $this->toDocument($invoice);
        }

        return $this->toDocument($client->getInvoice($document->type->value, (string) $document->provider_reference));
    }

    public function cancel(FiscalSetting $setting, FiscalDocument $document, string $reason): ProviderDocument
    {
        return $this->toDocument($this->company($setting)->cancelInvoice($document->type->value, (string) $document->provider_reference, $reason));
    }

    public function download(FiscalSetting $setting, FiscalDocument $document, string $format): string
    {
        return $this->company($setting)->downloadInvoiceFile($document->type->value, (string) $document->provider_reference, $format);
    }

    public function registerWebhook(string $url): void
    {
        $owner = $this->owner();
        $owner->createWebhook('invoice.status_changed', $url);
        $secret = $owner->webhookSecret();
        $platform = $this->platform();
        $platform->update(['platform_credentials' => [...($platform->platform_credentials ?? []), ...($secret === null ? [] : ['webhook_secret' => $secret])], 'webhook_registered_at' => now(), 'last_error' => null]);
    }

    public function parseWebhook(Request $request): ?array
    {
        $secret = (string) $this->platform()->webhookSecret();

        if ($secret === '' || ! $this->validSignature($request, $secret)) {
            return null;
        }

        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);

        if (! str_starts_with($event, 'invoice.') || blank($data['id'] ?? null)) {
            return ['event_id' => (string) $request->header('webhook-id'), 'event' => $event, 'document' => new ProviderDocument(null, '', null), 'integration_id' => null];
        }

        return [
            'event_id' => (string) $request->header('webhook-id'),
            'event' => $event,
            'document' => $this->toDocument($data),
            'integration_id' => is_string($data['integrationId'] ?? null) ? $data['integrationId'] : null,
        ];
    }

    /** @param array<string, mixed> $invoice */
    public function toDocument(array $invoice): ProviderDocument
    {
        $providerStatus = (string) ($invoice['status'] ?? '');
        $status = self::STATUS_MAP[$providerStatus] ?? null;
        $detail = (array) ($invoice['processingDetail'] ?? []);
        $authorization = (array) ($invoice['authorization'] ?? []);
        $number = $invoice['number'] ?? null;
        $message = trim((string) ($detail['message'] ?? ''));
        $code = trim((string) ($detail['code'] ?? ''));

        // Códigos transitórios da Spedy (RPS em processamento, cancelamento em andamento, nota na fila, erro interno
        // ou de comunicação com SEFAZ/prefeitura, que a Spedy retenta): seguir aguardando, nunca reemitir.
        if ($status === FiscalStatus::Rejected && in_array($code, self::TRANSITIONAL_CODES, true)) {
            $status = FiscalStatus::Processing;
        }

        return new ProviderDocument(
            reference: isset($invoice['id']) ? (string) $invoice['id'] : null,
            providerStatus: $providerStatus,
            status: $status,
            number: filled($number) && (string) $number !== '0' ? (string) $number : null,
            series: isset($invoice['series']) ? (string) $invoice['series'] : null,
            accessKey: is_string($invoice['accessKey'] ?? null) ? $invoice['accessKey'] : null,
            protocol: is_string($authorization['protocol'] ?? null) ? $authorization['protocol'] : null,
            issuedAt: $status === FiscalStatus::Authorized ? ($this->date($authorization['date'] ?? $invoice['issuedOn'] ?? null) ?? CarbonImmutable::now()) : null,
            cancelledAt: $status === FiscalStatus::Cancelled ? ($this->date($invoice['cancellation']['date'] ?? null) ?? CarbonImmutable::now()) : null,
            errorMessage: in_array($status, [FiscalStatus::Rejected, FiscalStatus::Denied, FiscalStatus::CommunicationFailure], true)
                ? mb_substr(trim(($code !== '' ? "[{$code}] " : '').($message ?: 'Documento recusado pela autoridade fiscal.')), 0, 1000)
                : null,
            environment: is_string($invoice['environmentType'] ?? null) ? $invoice['environmentType'] : null,
            issuerTaxNumber: preg_replace('/\D+/', '', (string) data_get($invoice, 'company.federalTaxNumber')) ?: null,
            summary: array_filter([
                'id' => $invoice['id'] ?? null,
                'status' => $invoice['status'] ?? null,
                'model' => $invoice['model'] ?? null,
                'environmentType' => $invoice['environmentType'] ?? null,
                'number' => $invoice['number'] ?? null,
                'series' => $invoice['series'] ?? null,
                'accessKey' => $invoice['accessKey'] ?? null,
                'amount' => $invoice['amount'] ?? null,
                'processingDetail' => $invoice['processingDetail'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    private function platform(): FiscalProviderAvailability
    {
        return FiscalProviderAvailability::query()->where('identifier', 'spedy')->first()
            ?? throw new FiscalProviderException('A Spedy não está configurada na plataforma.');
    }

    private function owner(): SpedyClient
    {
        $platform = $this->platform();
        $key = $platform->ownerKey() ?? throw new FiscalProviderException('Emissão fiscal indisponível: a conta da plataforma na Spedy não está configurada.');

        return new SpedyClient($this->baseUrl($platform), $key);
    }

    private function company(FiscalSetting $setting): SpedyClient
    {
        $key = $setting->apiKey() ?? throw new FiscalProviderException('Empresa emissora ainda não cadastrada no provedor fiscal.');

        return new SpedyClient($this->baseUrl($this->platform()), $key);
    }

    private function baseUrl(FiscalProviderAvailability $platform): string
    {
        return (string) config('fiscal.spedy.base_urls.'.($platform->environment === 'production' ? 'production' : 'sandbox'));
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (blank($value) || ! is_string($value)) {
            return null;
        }

        // Datas da Spedy sem deslocamento estão no horário de São Paulo.
        return CarbonImmutable::parse($value, 'America/Sao_Paulo')->setTimezone((string) config('app.timezone'));
    }

    /** Standard Webhooks: HMAC-SHA256 de "{id}.{timestamp}.{corpo}" com o segredo whsec_ (base64), comparação em tempo constante. */
    private function validSignature(Request $request, string $secret): bool
    {
        $id = (string) $request->header('webhook-id');
        $timestamp = (string) $request->header('webhook-timestamp');
        $signatures = (string) $request->header('webhook-signature');

        if ($id === '' || ! ctype_digit($timestamp) || $signatures === '' || abs(time() - (int) $timestamp) > (int) config('fiscal.spedy.webhook_tolerance', 300)) {
            return false;
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);

        if ($key === false || $key === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$request->getContent()}", $key, true));

        foreach (explode(' ', $signatures) as $part) {
            [$version, $signature] = array_pad(explode(',', $part, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
