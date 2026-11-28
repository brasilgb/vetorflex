<?php

namespace App\Http\Controllers\Sales;

use App\Modules\Core\Services\AuditService;
use App\Modules\Sales\Documents\SaleDocument;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Sales\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PDFs gerados a cada acesso autenticado; não há arquivo armazenado nem endereço público.
 */
class SaleDocumentController extends SalesController
{
    public function __construct(private SaleDocument $documents, private AuditService $audit) {}

    public function quote(Request $request, SaleQuote $quote): Response
    {
        $quote->load(['company', 'items', 'paymentSchedules']);

        return $this->pdf($request, $quote, $this->documents->quote($quote), "orcamento-{$quote->number}.pdf");
    }

    public function order(Request $request, SaleOrder $order): Response
    {
        $order->load(['company', 'items', 'paymentSchedules', 'quote']);

        return $this->pdf($request, $order, $this->documents->order($order), "pedido-venda-{$order->number}.pdf");
    }

    public function fulfillment(Request $request, SaleFulfillment $fulfillment): Response
    {
        $fulfillment->load(['order.company', 'items.orderItem']);

        return $this->pdf($request, $fulfillment, $this->documents->fulfillment($fulfillment), "atendimento-{$fulfillment->number}.pdf");
    }

    public function saleReturn(Request $request, SaleReturn $return): Response
    {
        $return->load(['order.company', 'fulfillment', 'items.fulfillmentItem.orderItem']);

        return $this->pdf($request, $return, $this->documents->saleReturn($return), "devolucao-venda-{$return->number}.pdf");
    }

    private function pdf(Request $request, SaleQuote|SaleOrder|SaleFulfillment|SaleReturn $model, string $content, string $filename): Response
    {
        $this->audit->record('sales.document_generated', $this->company($request), $request->user(), $model, ['number' => $model->number]);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
