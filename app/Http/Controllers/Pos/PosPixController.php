<?php

namespace App\Http\Controllers\Pos;

use App\Modules\Payments\Enums\ChargeOrigin;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Services\PosPix;
use App\Modules\Pos\Services\PosSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pix dinâmico da frente de caixa: gera o QR Code pelo módulo Pagamentos, informa a situação (confirmada somente
 * pela consulta ao provedor) e cancela o Pix não pago. A cobrança precisa ser da sessão aberta do operador.
 */
class PosPixController extends PosController
{
    public function __construct(private PosPix $pix, private PosSessionService $sessions) {}

    public function store(Request $request, PosSession $session): JsonResponse
    {
        $this->sessions->assertOperable($session, $request->user());
        $validated = $request->validate([
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'cart_key' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $charge = $this->pix->create($session, $request->user(), (string) $validated['amount'], $validated['cart_key'], $validated['idempotency_key']);

        return response()->json($this->present($charge), 201);
    }

    public function show(Request $request, PosSession $session, PaymentCharge $charge): JsonResponse
    {
        $this->assertBelongs($request, $session, $charge);

        return response()->json($this->present($this->pix->status($charge)));
    }

    public function cancel(Request $request, PosSession $session, PaymentCharge $charge): JsonResponse
    {
        $this->assertBelongs($request, $session, $charge);

        return response()->json($this->present($this->pix->cancel($charge, $request->user())));
    }

    private function assertBelongs(Request $request, PosSession $session, PaymentCharge $charge): void
    {
        $this->sessions->assertOperable($session, $request->user());
        abort_unless($charge->origin === ChargeOrigin::Pos && $charge->origin_id === $session->id, 404);
    }

    /** @return array<string, mixed> */
    private function present(PaymentCharge $charge): array
    {
        return [
            'id' => $charge->id,
            'status' => $charge->status->value,
            'status_label' => $charge->status->label(),
            'amount' => $charge->amount,
            'paid_amount' => $charge->paid_amount,
            'is_confirmed' => $charge->status->value === 'paid',
            'expires_at' => $charge->expires_at?->toIso8601String(),
            'pix_copy_paste' => $charge->pix_copy_paste,
            'qr_code_image' => $charge->qr_code_image,
            'environment' => $charge->environment,
        ];
    }
}
