<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notificações dos provedores. A conta (e portanto a empresa) vem do token secreto da URL; a origem é conferida
 * pela lista de IPs configurada (`payments.webhooks.allowed_ips`), quando a verificação está ligada. A resposta é
 * sempre rápida: o processamento acontece na fila. Na Efí, o registro dispensa mTLS (`x-skip-mtls-checking`); a confirmação vem sempre da consulta à API (ver PAYMENTS.md).
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookService $webhooks, string $provider, string $token): JsonResponse
    {
        $account = $webhooks->account($provider, $token);
        abort_if($account === null, 404);

        $allowed = config("payments.webhooks.allowed_ips.{$provider}", []);
        abort_if(config('payments.webhooks.enforce_ips') && is_array($allowed) && $allowed !== [] && ! in_array($request->ip(), $allowed, true), 403);

        $webhooks->receive($account, $request->json()->all(), $request->ip());

        return response()->json(['received' => true]);
    }
}
