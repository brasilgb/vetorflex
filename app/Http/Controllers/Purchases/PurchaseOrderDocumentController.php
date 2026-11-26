<?php

namespace App\Http\Controllers\Purchases;

use App\Modules\Core\Services\AuditService;
use App\Modules\Purchases\Documents\PurchaseOrderDocument;
use App\Modules\Purchases\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * O PDF é gerado a cada acesso autenticado; não há arquivo armazenado nem endereço público.
 */
class PurchaseOrderDocumentController extends PurchaseController
{
    public function __invoke(Request $request, PurchaseOrder $order, PurchaseOrderDocument $document, AuditService $audit): Response
    {
        $order->load(['company', 'items', 'paymentSchedules']);
        $audit->record('purchase.order_document_generated', $order->company, $request->user(), $order, ['number' => $order->number]);

        return response($document->render($order, $this->canViewCost($request)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"pedido-compra-{$order->number}.pdf\"",
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
