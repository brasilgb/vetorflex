<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Payments\Data\ProviderCapabilities;
use App\Modules\Payments\Data\ProviderCharge;
use App\Modules\Payments\Data\ProviderPayment;
use App\Modules\Payments\Data\ProviderRefund;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\PaymentTransaction;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Efí (API Pix), conforme a documentação oficial (dev.efipay.com.br, consultada em 27/11/2026):
 *
 * - rotas base `https://pix.api.efipay.com.br` (produção) e `https://pix-h.api.efipay.com.br` (homologação);
 * - OAuth2 `client_credentials` em `POST /oauth/token`, com client_id e client_secret em HTTP Basic, e certificado
 *   da conta (P12 ou PEM) exigido em todas as requisições (mTLS), inclusive em homologação;
 * - cobrança imediata `PUT /v2/cob/:txid`, consulta `GET /v2/cob/:txid`, remoção `PATCH /v2/cob/:txid` com
 *   `REMOVIDA_PELO_USUARIO_RECEBEDOR`, QR Code `GET /v2/loc/:id/qrcode`;
 * - devolução `PUT /v2/pix/:e2eId/devolucao/:id` (EM_PROCESSAMENTO, DEVOLVIDO, NAO_REALIZADO);
 * - webhook `PUT /v2/webhook/:chave` com `webhookUrl`; a notificação traz `pix[]` com `txid` e só indica o que
 *   consultar.
 *
 * Implementado somente o Pix. Boleto e cartão (API de Cobranças) não foram implementados e não são declarados.
 */
class EfiProvider implements PaymentProvider
{
    public const BASE_URLS = ['production' => 'https://pix.api.efipay.com.br', 'homologation' => 'https://pix-h.api.efipay.com.br'];

    private const TIMEOUT_SECONDS = 20;

    public function identifier(): string
    {
        return 'efi';
    }

    public function name(): string
    {
        return 'Efí';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(pix: true, paymentLink: true, query: true, cancel: true, refund: true, webhooks: true);
    }

    public function testConnection(PaymentProviderAccount $account): void
    {
        Cache::forget($this->tokenKey($account));
        $now = now();
        $this->call($account, fn (PendingRequest $http): Response => $http->get('/v2/cob', [
            'inicio' => $now->copy()->subDay()->utc()->format('Y-m-d\TH:i:s\Z'),
            'fim' => $now->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]));
    }

    public function createPixCharge(PaymentProviderAccount $account, PaymentCharge $charge): ProviderCharge
    {
        $body = array_filter([
            'calendario' => ['expiracao' => $this->expiration($account, $charge)],
            'devedor' => $this->debtor($charge),
            'valor' => ['original' => (string) $charge->amount],
            'chave' => $account->pix_key,
            'solicitacaoPagador' => Str::limit($charge->description, 137),
            'infoAdicionais' => [['nome' => 'Cobrança', 'valor' => "#{$charge->number}"]],
        ], fn (mixed $value): bool => $value !== null);
        $response = $this->call($account, fn (PendingRequest $http): Response => $http->put("/v2/cob/{$charge->provider_charge_id}", $body), allowConflict: true);

        // 409 (txid já usado): a emissão anterior chegou ao provedor; a cobrança existente é consultada.
        $data = $response->status() === 409 ? $this->call($account, fn (PendingRequest $http): Response => $http->get("/v2/cob/{$charge->provider_charge_id}"))->json() : $response->json();

        return $this->withQrCode($account, $this->toCharge($data));
    }

    public function fetchCharge(PaymentProviderAccount $account, PaymentCharge $charge): ProviderCharge
    {
        $data = $this->call($account, fn (PendingRequest $http): Response => $http->get("/v2/cob/{$charge->provider_charge_id}"))->json();

        return $this->toCharge($data, $charge);
    }

    public function cancelCharge(PaymentProviderAccount $account, PaymentCharge $charge): ProviderCharge
    {
        $data = $this->call($account, fn (PendingRequest $http): Response => $http->patch("/v2/cob/{$charge->provider_charge_id}", ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR']))->json();

        return $this->toCharge($data, $charge);
    }

    public function refund(PaymentProviderAccount $account, PaymentTransaction $transaction, PaymentRefund $refund): ProviderRefund
    {
        $data = $this->call($account, fn (PendingRequest $http): Response => $http->put("/v2/pix/{$transaction->provider_transaction_id}/devolucao/{$refund->provider_refund_id}", ['valor' => (string) $refund->amount]))->json();

        return $this->toRefund(is_array($data) ? $data : [], $refund->provider_refund_id, (string) $refund->amount);
    }

    public function registerWebhook(PaymentProviderAccount $account, string $url): void
    {
        if (blank($account->pix_key)) {
            throw new ProviderException('Informe a chave Pix da conta antes de registrar o webhook.');
        }

        // Sem mTLS no servidor da aplicação, a Efí aceita o registro com `x-skip-mtls-checking: true` (prática da
        // integração em produção do VetorOS). A origem é então autenticada pelo token secreto da URL, pela lista de
        // IPs e, sobretudo, pela consulta da cobrança na API antes de qualquer confirmação.
        $this->call($account, fn (PendingRequest $http): Response => $http->withHeaders(['x-skip-mtls-checking' => 'true'])->put('/v2/webhook/'.rawurlencode((string) $account->pix_key), ['webhookUrl' => $url]));
    }

    public function chargeIdsFromWebhook(array $payload): array
    {
        $ids = [];

        foreach (is_array($payload['pix'] ?? null) ? $payload['pix'] : [] as $pix) {
            if (is_array($pix) && is_string($pix['txid'] ?? null) && $pix['txid'] !== '') {
                $ids[] = $pix['txid'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Executa a requisição autenticada, renovando o token uma vez se o provedor o recusar.
     *
     * @param  Closure(PendingRequest): Response  $request
     */
    private function call(PaymentProviderAccount $account, Closure $request, bool $allowConflict = false, bool $isRetry = false): Response
    {
        return $this->withCertificate($account, function (array $options) use ($account, $request, $allowConflict, $isRetry): Response {
            try {
                $response = $request($this->http($account, $options)->withToken($this->token($account, $options)));
            } catch (ConnectionException) {
                throw new ProviderException('Não foi possível conectar à Efí. Verifique a conexão e o certificado.', true);
            }

            if ($response->status() === 401 && ! $isRetry) {
                Cache::forget($this->tokenKey($account));

                return $this->call($account, $request, $allowConflict, true);
            }

            if ($response->successful() || ($allowConflict && $response->status() === 409)) {
                return $response;
            }

            throw $this->failure($response);
        });
    }

    /** @param array<string, mixed> $options */
    private function token(PaymentProviderAccount $account, array $options): string
    {
        $cached = Cache::get($this->tokenKey($account));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $account->credentials ?? [];

        try {
            $response = $this->http($account, $options)->withBasicAuth((string) ($credentials['client_id'] ?? ''), (string) ($credentials['client_secret'] ?? ''))
                ->post('/oauth/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new ProviderException('Não foi possível conectar à Efí para autenticar. Verifique o certificado e o ambiente.', true);
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw $response->status() === 401 || $response->status() === 400
                ? new ProviderException('A Efí recusou as credenciais. Confira o Client ID, o Client Secret e o ambiente do certificado.', false, $response->status())
                : $this->failure($response);
        }

        $token = (string) $response->json('access_token');
        Cache::put($this->tokenKey($account), $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    /** @param array<string, mixed> $options */
    private function http(PaymentProviderAccount $account, array $options): PendingRequest
    {
        return Http::baseUrl(self::BASE_URLS[$account->environment] ?? self::BASE_URLS['homologation'])
            ->acceptJson()->asJson()->timeout(self::TIMEOUT_SECONDS)->withOptions($options);
    }

    /**
     * Grava o certificado decifrado num arquivo temporário (0600) só durante a requisição.
     *
     * @template TResult
     *
     * @param  Closure(array<string, mixed>): TResult  $callback
     * @return TResult
     */
    private function withCertificate(PaymentProviderAccount $account, Closure $callback): mixed
    {
        $credentials = $account->credentials ?? [];
        $contents = base64_decode((string) ($credentials['certificate'] ?? ''), true);

        if ($contents === false || $contents === '') {
            throw new ProviderException('Envie o certificado da conta Efí (arquivo .p12 ou .pem).');
        }

        $isPem = str_contains($contents, '-----BEGIN');
        $path = tempnam(sys_get_temp_dir(), 'efi-cert-');

        if ($path === false) {
            throw new ProviderException('Não foi possível preparar o certificado para a conexão.', true);
        }

        try {
            file_put_contents($path, $contents);
            chmod($path, 0600);
            $password = (string) ($credentials['certificate_password'] ?? '');
            $options = ['cert' => $password === '' ? $path : [$path, $password]];

            if (! $isPem) {
                $options['curl'] = [CURLOPT_SSLCERTTYPE => 'P12'];
            }

            return $callback($options);
        } finally {
            @unlink($path);
        }
    }

    private function tokenKey(PaymentProviderAccount $account): string
    {
        return "payments:efi:token:{$account->id}:".hash('sha256', $account->environment.'|'.($account->credentials['client_id'] ?? ''));
    }

    private function failure(Response $response): ProviderException
    {
        $data = $response->json();
        $detail = is_array($data) ? ($data['mensagem'] ?? $data['detail'] ?? $data['title'] ?? null) : null;
        $retryable = $response->status() === 429 || $response->serverError();
        $message = match (true) {
            $response->status() === 429 => 'A Efí limitou temporariamente as requisições. Tente novamente em instantes.',
            $response->status() === 403 => 'A Efí negou o acesso. Confira os escopos da aplicação e o certificado.',
            $response->status() === 404 => 'A cobrança não foi encontrada na Efí.',
            $response->serverError() => 'A Efí está indisponível no momento. Tente novamente mais tarde.',
            default => 'A Efí recusou a operação'.(is_string($detail) ? ': '.Str::limit($detail, 200) : '.'),
        };

        return new ProviderException($message, $retryable, $response->status());
    }

    private function toCharge(mixed $data, ?PaymentCharge $charge = null): ProviderCharge
    {
        $data = is_array($data) ? $data : [];
        $status = (string) ($data['status'] ?? '');
        $payments = [];

        foreach (is_array($data['pix'] ?? null) ? $data['pix'] : [] as $pix) {
            if (! is_array($pix) || ! is_string($pix['endToEndId'] ?? null)) {
                continue;
            }

            $refunds = [];

            foreach (is_array($pix['devolucoes'] ?? null) ? $pix['devolucoes'] : [] as $refund) {
                if (is_array($refund) && isset($refund['id'])) {
                    $refunds[] = $this->toRefund($refund, (string) $refund['id'], (string) ($refund['valor'] ?? '0'));
                }
            }

            $payments[] = new ProviderPayment(
                id: $pix['endToEndId'],
                amount: (string) ($pix['valor'] ?? '0'),
                paidAt: (string) ($pix['horario'] ?? now()->toIso8601String()),
                payerName: is_string($pix['pagador']['nome'] ?? null) ? $pix['pagador']['nome'] : ($data['devedor']['nome'] ?? null),
                payerDocument: $pix['pagador']['cpf'] ?? $pix['pagador']['cnpj'] ?? null,
                // A consulta documentada da cobrança não informa tarifa: a taxa fica vazia, nunca estimada.
                fee: null,
                refunds: $refunds,
            );
        }

        return new ProviderCharge(
            id: (string) ($data['txid'] ?? $charge->provider_charge_id ?? ''),
            status: $status,
            isRemoved: in_array($status, ['REMOVIDA_PELO_USUARIO_RECEBEDOR', 'REMOVIDA_PELO_PSP'], true),
            pixCopyPaste: is_string($data['pixCopiaECola'] ?? null) ? $data['pixCopiaECola'] : null,
            locationId: isset($data['loc']['id']) ? (int) $data['loc']['id'] : null,
            payments: $payments,
        );
    }

    private function withQrCode(PaymentProviderAccount $account, ProviderCharge $charge): ProviderCharge
    {
        if ($charge->locationId === null) {
            return $charge;
        }

        $qr = $this->call($account, fn (PendingRequest $http): Response => $http->get("/v2/loc/{$charge->locationId}/qrcode"))->json();

        return new ProviderCharge(
            id: $charge->id,
            status: $charge->status,
            isRemoved: $charge->isRemoved,
            pixCopyPaste: is_string($qr['qrcode'] ?? null) ? $qr['qrcode'] : $charge->pixCopyPaste,
            qrCodeImage: is_string($qr['imagemQrcode'] ?? null) && str_starts_with($qr['imagemQrcode'], 'data:image/') ? $qr['imagemQrcode'] : null,
            paymentUrl: is_string($qr['linkVisualizacao'] ?? null) ? $qr['linkVisualizacao'] : null,
            locationId: $charge->locationId,
            payments: $charge->payments,
        );
    }

    /** @param array<string, mixed> $data */
    private function toRefund(array $data, string $id, string $amount): ProviderRefund
    {
        return new ProviderRefund(
            id: $id,
            amount: (string) ($data['valor'] ?? $amount),
            status: match ($data['status'] ?? null) {
                'DEVOLVIDO' => RefundStatus::Refunded,
                'NAO_REALIZADO' => RefundStatus::Failed,
                default => RefundStatus::Processing,
            },
            rtrId: is_string($data['rtrId'] ?? null) ? $data['rtrId'] : null,
            reason: is_string($data['motivo'] ?? null) ? $data['motivo'] : null,
        );
    }

    /** @return array{cpf?: string, cnpj?: string, nome: string}|null A Efí exige nome junto do CPF ou CNPJ. */
    private function debtor(PaymentCharge $charge): ?array
    {
        $document = BrazilianDocument::normalize($charge->payer_document);

        if ($document === null || blank($charge->payer_name)) {
            return null;
        }

        return [strlen($document) === 14 ? 'cnpj' : 'cpf' => $document, 'nome' => Str::limit((string) $charge->payer_name, 200, '')];
    }

    private function expiration(PaymentProviderAccount $account, PaymentCharge $charge): int
    {
        return $charge->expires_at === null ? $account->expiration_seconds : max(60, (int) now()->diffInSeconds($charge->expires_at, false));
    }
}
