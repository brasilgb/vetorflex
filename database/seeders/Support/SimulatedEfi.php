<?php

namespace Database\Seeders\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Simulação local da API Pix da Efí, usada somente pelos seeders de demonstração (local e testing). Nenhuma
 * requisição sai do processo: cobranças, pagamentos e devoluções são fictícios.
 */
class SimulatedEfi
{
    /** @var array<string, array{status: string, pix: list<array<string, mixed>>}> Situação por txid. */
    private array $charges = [];

    public function start(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->respond($request));
    }

    /** Marca a cobrança como paga no "provedor" simulado. */
    public function pay(string $txid, string $amount, string $endToEndId): void
    {
        $this->charges[$txid] = ['status' => 'CONCLUIDA', 'pix' => [['endToEndId' => $endToEndId, 'txid' => $txid, 'valor' => $amount, 'horario' => now()->toIso8601String(), 'devolucoes' => []]]];
    }

    private function respond(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($path === '/oauth/token') {
            return Http::response(['access_token' => 'demo-token', 'token_type' => 'Bearer', 'expires_in' => 3600]);
        }

        if (preg_match('#^/v2/cob/([A-Za-z0-9]+)$#', $path, $match)) {
            $state = $this->charges[$match[1]] ??= ['status' => 'ATIVA', 'pix' => []];

            if ($request->method() === 'PATCH') {
                $state = $this->charges[$match[1]] = ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR', 'pix' => []];
            }

            return Http::response(array_filter(['txid' => $match[1], 'status' => $state['status'], 'loc' => ['id' => 1], 'pixCopiaECola' => '00020101021226830014BR.GOV.BCB.PIX2561demonstracao.vetorflex'.$match[1], 'pix' => $state['pix'] === [] ? null : $state['pix']]), $request->method() === 'PUT' ? 201 : 200);
        }

        if (preg_match('#^/v2/loc/\d+/qrcode$#', $path)) {
            return Http::response(['qrcode' => '00020101021226830014BR.GOV.BCB.PIX2561demonstracao.vetorflex', 'imagemQrcode' => 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#000"/><rect x="2" y="2" width="6" height="6" fill="#fff"/></svg>')]);
        }

        if ($path === '/v2/cob') {
            return Http::response(['cobs' => []]);
        }

        return Http::response(['nome' => 'nao_simulado', 'mensagem' => 'Operação não simulada na demonstração.'], 404);
    }
}
