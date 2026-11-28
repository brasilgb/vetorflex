<?php

namespace App\Modules\Fiscal\Providers\Spedy;

use App\Modules\Fiscal\Providers\FiscalProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP da API v1 da Spedy (docs.spedy.com.br, consultada em 27/11/2026). Reaproveitado do VetorOS
 * (`App\Services\Fiscal\Spedy\SpedyClient`, em produção) e adaptado: a URL e o ambiente vêm da configuração da
 * plataforma no VetorFlex, e os erros viram `FiscalProviderException`.
 *
 * Duas credenciais (`X-Api-Key`): a chave da empresa titular da conta, única que acessa `/companies/*`, e a chave de
 * cada empresa emissora, que só emite e consulta as próprias notas.
 */
class SpedyClient
{
    private const MODEL_PATHS = ['nfe' => 'product-invoices', 'nfce' => 'consumer-invoices', 'nfse' => 'service-invoices'];

    public function __construct(private readonly string $baseUrl, private readonly string $apiKey) {}

    public static function modelPath(string $model): string
    {
        return self::MODEL_PATHS[$model] ?? throw new \InvalidArgumentException("Modelo fiscal desconhecido: {$model}");
    }

    /** @return array<string, mixed> */
    public function listCompanies(int $page = 1, int $pageSize = 1): array
    {
        return $this->handle(fn () => $this->request()->get('companies', ['page' => $page, 'pageSize' => $pageSize]), 'companies');
    }

    /**
     * Procura o CNPJ entre as empresas da conta (a API não filtra por CNPJ), até um limite seguro.
     *
     * @return array<string, mixed>|null
     */
    public function findCompanyByFederalTaxNumber(string $federalTaxNumber, int $pageSize = 100, int $maxPages = 20): ?array
    {
        $target = preg_replace('/\D+/', '', $federalTaxNumber);

        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $this->listCompanies($page, $pageSize);
            // Envelope paginado da API (`items`, `totalCount`, `hasNext`).
            $items = is_array($result['items'] ?? null) ? $result['items'] : [];

            foreach ($items as $company) {
                if (is_array($company) && preg_replace('/\D+/', '', (string) ($company['federalTaxNumber'] ?? '')) === $target) {
                    return $company;
                }
            }

            if (count($items) < $pageSize) {
                return null;
            }
        }

        throw new FiscalProviderException('Não foi possível concluir a verificação de CNPJ na conta Spedy (muitas empresas). Contate o suporte.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCompany(array $payload): array
    {
        return $this->send('post', 'companies', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateCompany(string $companyId, array $payload): array
    {
        return $this->send('put', "companies/{$companyId}", $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateCompanySettings(string $companyId, array $payload): array
    {
        return $this->send('put', "companies/{$companyId}/settings", $payload);
    }

    /** @return array<string, mixed> */
    public function uploadCertificate(string $companyId, string $contents, string $password): array
    {
        if (parse_url($this->baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new FiscalProviderException('O envio do certificado exige conexão segura com o emissor.');
        }

        return $this->handle(
            fn () => $this->request()->withOptions(['allow_redirects' => false])->attach('certificateFile', $contents, 'certificado.pfx')->post("companies/{$companyId}/certificates", ['password' => $password]),
            "companies/{$companyId}/certificates"
        );
    }

    public function webhookSecret(): ?string
    {
        $secret = $this->send('get', 'webhooks/secret')['secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /** @return array<string, mixed> */
    public function createWebhook(string $event, string $url): array
    {
        return $this->send('post', 'webhooks', ['event' => $event, 'url' => $url]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createInvoice(string $model, array $payload): array
    {
        return $this->send('post', self::modelPath($model), $payload);
    }

    /** @return array<string, mixed> */
    public function getInvoice(string $model, string $invoiceId): array
    {
        return $this->send('get', self::modelPath($model)."/{$invoiceId}");
    }

    /**
     * Localiza a nota enviada sem resposta (timeout) pelo identificador de integração.
     *
     * @return array<string, mixed>|null
     */
    public function findInvoiceByIntegrationId(string $model, string $integrationId): ?array
    {
        $result = $this->handle(fn () => $this->request()->get(self::modelPath($model), ['integrationId' => $integrationId, 'pageSize' => 1]), self::modelPath($model));
        $invoice = $result['items'][0] ?? null;

        return is_array($invoice) && ($invoice['integrationId'] ?? $integrationId) === $integrationId ? $invoice : null;
    }

    /** @return array<string, mixed> */
    public function cancelInvoice(string $model, string $invoiceId, string $reason): array
    {
        return $this->send('delete', self::modelPath($model)."/{$invoiceId}", ['reason' => $reason]);
    }

    public function downloadInvoiceFile(string $model, string $invoiceId, string $format): string
    {
        $path = self::modelPath($model)."/{$invoiceId}/".($format === 'xml' ? 'xml' : 'pdf');

        try {
            $response = $this->request()->get($path);
        } catch (ConnectionException) {
            throw new FiscalProviderException('Não foi possível obter o arquivo da nota fiscal. Tente novamente em instantes.');
        }

        if (! $response->successful()) {
            throw $this->exceptionFor($response, $path);
        }

        return $response->body();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        return $this->handle(fn () => match ($method) {
            'get' => $this->request()->get($path),
            'delete' => $this->request()->send('DELETE', $path, ['json' => $payload]),
            'put' => $this->request()->put($path, $payload),
            default => $this->request()->post($path, $payload),
        }, $path);
    }

    /** @return array<string, mixed> */
    private function handle(callable $call, string $path): array
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            Log::warning('Spedy: falha de conexão', ['path' => $path]);

            throw new FiscalProviderException('Não foi possível comunicar com o serviço de emissão fiscal. Tente novamente em instantes.');
        }

        if (! $response->successful()) {
            throw $this->exceptionFor($response, $path);
        }

        return (array) ($response->json() ?? []);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->acceptJson()
            ->timeout((int) config('fiscal.spedy.timeout', 30))
            // 429: limite de 5 req/s por chave. Reenvio é seguro: as emissões usam integrationId.
            ->retry(3, fn (int $attempt) => 1000 * $attempt, fn ($exception) => $exception instanceof RequestException && $exception->response->status() === 429, throw: false);
    }

    private function exceptionFor(Response $response, string $path): FiscalProviderException
    {
        $status = $response->status();
        // O provedor pode ecoar senha ou conteúdo do arquivo em erros de certificado.
        $certificateRequest = str_ends_with($path, '/certificates');
        $errors = $certificateRequest ? [] : $this->extractErrors((array) ($response->json() ?? []));
        Log::warning('Spedy: requisição recusada', ['path' => $path, 'status' => $status, 'errors' => $errors]);

        $message = match (true) {
            $status === 401 => 'Chave de API da Spedy inválida, revogada ou de outro ambiente. Confira a integração.',
            $status === 403 => 'Acesso negado pela Spedy: chave sem permissão para esta operação ou recurso fora do plano.',
            $status === 404 => 'Registro não encontrado no serviço de emissão fiscal.',
            $status === 429 => 'Muitas solicitações ao serviço de emissão fiscal. Aguarde alguns segundos e tente novamente.',
            $status >= 500 => 'O serviço de emissão fiscal está indisponível no momento. Tente novamente em instantes.',
            $certificateRequest => 'Certificado recusado pelo emissor. Confira o arquivo A1 e a senha.',
            $errors !== [] => 'Dados recusados pelo serviço de emissão fiscal: '.implode(' ', array_slice($errors, 0, 5)),
            default => 'Solicitação recusada pelo serviço de emissão fiscal.',
        };

        return new FiscalProviderException($message, $status, $errors);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function extractErrors(array $body): array
    {
        $messages = [];

        foreach (['message', 'title', 'detail', 'error'] as $key) {
            if (is_string($body[$key] ?? null) && $body[$key] !== '') {
                $messages[] = $body[$key];
            }
        }

        foreach (Arr::wrap($body['errors'] ?? []) as $field => $error) {
            foreach (Arr::wrap($error) as $item) {
                $text = is_array($item) ? ($item['message'] ?? $item['description'] ?? null) : $item;

                if (is_string($text) && $text !== '') {
                    $messages[] = is_string($field) ? "{$field}: {$text}" : $text;
                }
            }
        }

        return array_values(array_unique(array_map(fn (string $message): string => mb_substr(trim($message), 0, 300), $messages)));
    }
}
