<?php

namespace App\Http\Controllers\Fiscal;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Services\FiscalWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notificações dos provedores fiscais (conta da plataforma). A origem é autenticada pela assinatura; a resposta é
 * rápida e o processamento acontece na fila, sempre com nova consulta ao provedor.
 */
class FiscalWebhookController extends Controller
{
    public function __invoke(Request $request, FiscalWebhookService $webhooks, string $provider): JsonResponse
    {
        $event = $webhooks->receive($provider, $request);
        abort_if($event === null, 401);

        return response()->json(['received' => true]);
    }
}
