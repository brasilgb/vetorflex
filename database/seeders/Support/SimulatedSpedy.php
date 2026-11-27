<?php

namespace Database\Seeders\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Simulação local da API v1 da Spedy, usada pelos seeders de demonstração (local e testing) e pelos testes. Nenhuma
 * requisição sai do processo; empresas, notas, números, chaves de acesso, XML e PDF são fictícios e marcados como
 * demonstração. `integrationId` repetido atualiza a mesma nota, como na API real.
 */
class SimulatedSpedy
{
    /** @var array<string, array<string, mixed>> Notas por id. */
    public array $invoices = [];

    /** @var list<string> Chamadas recebidas ("MÉTODO caminho"), para conferência nos testes. */
    public array $calls = [];

    /** @var list<array<string, mixed>> Corpos das emissões recebidas. */
    public array $payloads = [];

    /** Resultado da próxima emissão: `authorized`, `rejected`, `enqueued`, `timeout`, `server_error` ou `bad_request`. */
    public string $nextOutcome = 'authorized';

    /** Resultado do próximo cancelamento: `canceled`, `refused` ou `timeout`. */
    public string $nextCancel = 'canceled';

    public string $issuerCnpj = '';

    private int $number = 1000;

    public function start(): self
    {
        // Fábrica nova: simulações registradas antes (por exemplo, a da Efí no seed de Pagamentos) não respondem por ela.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->respond($request));

        return $this;
    }

    /** Muda a situação de uma nota no "provedor" (simula o processamento assíncrono). */
    public function settle(string $id, string $status): void
    {
        $this->invoices[$id] = $this->finish([...$this->invoices[$id], 'status' => $status]);
    }

    private function respond(Request $request): mixed
    {
        $path = (string) preg_replace('#^/v1#', '', (string) parse_url($request->url(), PHP_URL_PATH));
        $method = $request->method();
        $this->calls[] = "{$method} {$path}";

        if ($path === '/companies' && $method === 'GET') {
            return Http::response(['items' => [], 'totalCount' => 0, 'pageCount' => 0, 'pageSize' => 100, 'hasNext' => false]);
        }

        if ($path === '/companies' && $method === 'POST') {
            $this->issuerCnpj = (string) ($request->data()['federalTaxNumber'] ?? '');

            return Http::response(['id' => (string) Str::uuid(), 'name' => $request->data()['name'] ?? null, 'apiCredentials' => ['apiKey' => 'chave-empresa-simulada-'.Str::random(12)]]);
        }

        if (preg_match('#^/companies/[^/]+/certificates$#', $path)) {
            return Http::response(['subject' => 'CN=EMPRESA DEMONSTRACAO:00000000000191', 'kind' => 'own', 'isDefault' => true, 'expirationAt' => now()->addYear()->format('Y-m-d\TH:i:s')]);
        }

        if (preg_match('#^/companies/[^/]+(/settings)?$#', $path)) {
            return Http::response(['ok' => true]);
        }

        if ($path === '/webhooks') {
            return Http::response(['id' => (string) Str::uuid(), 'event' => 'invoice.status_changed']);
        }

        if ($path === '/webhooks/secret') {
            return Http::response(['secret' => 'whsec_'.base64_encode('segredo-simulado-de-webhook-32b!'), 'createdAt' => now()->toIso8601String()]);
        }

        if (! preg_match('#^/(product-invoices|consumer-invoices|service-invoices)(?:/([^/]+))?(?:/(xml|pdf|check-status))?$#', $path, $match)) {
            return Http::response(['message' => 'Operação não simulada.'], 404);
        }

        $model = ['product-invoices' => 'productInvoice', 'consumer-invoices' => 'consumerInvoice', 'service-invoices' => 'serviceInvoice'][$match[1]];
        $id = $match[2] ?? null;
        $action = $match[3] ?? null;

        if ($id === null && $method === 'GET') {
            $integrationId = $request->data()['integrationId'] ?? null;
            $items = array_values(array_filter($this->invoices, fn (array $invoice): bool => $invoice['integrationId'] === $integrationId));

            return Http::response(['items' => $items, 'totalCount' => count($items), 'pageCount' => 1, 'pageSize' => 1, 'hasNext' => false]);
        }

        if ($id === null && $method === 'POST') {
            return $this->create($model, $request->data());
        }

        $invoice = $id === null ? null : ($this->invoices[$id] ?? null);

        if ($invoice === null) {
            return Http::response(['message' => 'Nota não encontrada.'], 404);
        }

        if ($action === 'xml') {
            return Http::response("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<nota demonstracao=\"true\" id=\"{$id}\" situacao=\"{$invoice['status']}\"><numero>{$invoice['number']}</numero></nota>", 200, ['Content-Type' => 'application/xml']);
        }

        if ($action === 'pdf') {
            return Http::response("%PDF-1.4\n% Documento de demonstração {$id} ({$invoice['status']})\n%%EOF", 200, ['Content-Type' => 'application/pdf']);
        }

        if ($method === 'DELETE') {
            return match ($this->nextCancel) {
                'refused' => Http::response(['message' => 'Prazo de cancelamento encerrado (simulado).'], 400),
                'timeout' => throw new ConnectionException('Tempo esgotado (simulado).'),
                default => Http::response($this->invoices[$id] = $this->finish([...$invoice, 'status' => 'canceled', 'cancellation' => ['date' => now()->format('Y-m-d\TH:i:s'), 'justification' => $request->data()['reason'] ?? null]])),
            };
        }

        return Http::response($invoice);
    }

    /** @param array<string, mixed> $data */
    private function create(string $model, array $data): mixed
    {
        $outcome = $this->nextOutcome;
        $this->nextOutcome = 'authorized';
        $this->payloads[] = $data;

        if ($outcome === 'timeout') {
            throw new ConnectionException('Tempo esgotado (simulado).');
        }

        if ($outcome === 'server_error') {
            return Http::response(['message' => 'Instabilidade simulada.'], 503);
        }

        if ($outcome === 'bad_request') {
            return Http::response(['message' => 'Dados inválidos (simulado).', 'errors' => ['receiver.federalTaxNumber' => ['CPF/CNPJ inválido.']]], 400);
        }

        $existing = collect($this->invoices)->first(fn (array $invoice): bool => $invoice['integrationId'] === ($data['integrationId'] ?? null));
        $id = $existing['id'] ?? (string) Str::uuid();
        $invoice = [
            'id' => $id,
            'integrationId' => $data['integrationId'] ?? null,
            'model' => $model,
            'environmentType' => $model === 'serviceInvoice' ? 'simulation' : 'development',
            'status' => $outcome,
            'series' => '1',
            'number' => 0,
            'amount' => $data['total']['invoiceAmount'] ?? ($data['payments'][0]['amount'] ?? null),
            'company' => ['federalTaxNumber' => $this->issuerCnpj],
        ];

        return Http::response($this->invoices[$id] = $this->finish($invoice));
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function finish(array $invoice): array
    {
        if ($invoice['status'] === 'authorized' && (int) ($invoice['number'] ?? 0) === 0) {
            $invoice['number'] = ++$this->number;
            $invoice['accessKey'] = $invoice['model'] === 'serviceInvoice' ? null : str_pad((string) random_int(0, 99999999), 44, '0', STR_PAD_LEFT);
            $invoice['authorization'] = ['protocol' => 'DEMO'.random_int(100000, 999999), 'date' => now()->format('Y-m-d\TH:i:s')];
            $invoice['processingDetail'] = ['status' => 'success', 'message' => 'Autorizado (demonstração).'];
        }

        if ($invoice['status'] === 'rejected') {
            $invoice['processingDetail'] = ['status' => 'failed', 'code' => '539', 'message' => 'Duplicidade de NF-e com diferença na chave de acesso (simulado).'];
        }

        return $invoice;
    }
}
