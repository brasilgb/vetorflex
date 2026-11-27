<?php

namespace App\Modules\Pos\Support;

use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Core\Documents\CommercialDocument;
use App\Modules\Core\Documents\DocumentFormat;
use App\Modules\Core\Documents\PdfWriter;
use App\Modules\Pos\Models\PosPayment;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Services\PosModule;
use App\Modules\Sales\Models\SaleOrderItem;
use App\Modules\Tenancy\Models\Company;

/**
 * Comprovante comercial não fiscal da venda no caixa: dados para impressão no navegador (bobinas de 58 e 80 mm) e PDF.
 * Não é NFC-e e informa isso de forma explícita. Nunca exibe custo ou margem.
 */
class PosReceipt
{
    public const NOTICE = 'DOCUMENTO SEM VALOR FISCAL — comprovante comercial, não substitui nota fiscal.';

    public function __construct(private PosModule $module) {}

    /** @return array<string, mixed> */
    public function data(PosSale $sale, bool $isCopy): array
    {
        $company = Company::query()->findOrFail($sale->company_id);
        $settings = $this->module->settings($company);
        $order = $sale->order()->with('items')->firstOrFail();

        return [
            'company' => ['name' => $company->name, 'document' => $company->settings['document'] ?? null],
            'header' => $settings->receiptHeader,
            'footer' => $settings->receiptFooter,
            'notice' => self::NOTICE,
            'is_copy' => $isCopy,
            'number' => $order->number,
            'date' => $sale->completed_at?->setTimezone($company->timezone)->format('d/m/Y H:i'),
            'terminal' => $sale->terminal->name,
            'session' => $sale->session->number,
            'operator' => $sale->user_name,
            'customer' => $order->customer_snapshot === null ? null : [
                'name' => $order->customer_snapshot['name'] ?? null,
                'document' => $order->customer_snapshot['document'] ?? null,
            ],
            'items' => $order->items->map(fn (SaleOrderItem $item): array => [
                'description' => $item->description,
                'quantity' => DocumentFormat::quantity($item->quantity),
                'unit' => $item->unit_code,
                'unit_price' => $item->unit_price,
                'discount' => $item->discount,
                'total' => $item->total,
            ])->values()->all(),
            'items_total' => $order->items_total,
            'discount' => $order->discount,
            'total' => $order->total,
            'payments' => $sale->payments->map(fn (PosPayment $payment): array => [
                'method' => $payment->method->label(),
                'amount' => $payment->amount,
                'tendered' => $payment->tendered,
                'is_received' => $payment->is_received,
                'due_date' => $payment->is_received ? null : $payment->due_date->format('d/m/Y'),
                'is_informed' => $payment->is_received && $payment->method !== PaymentMethod::Cash,
            ])->values()->all(),
            'change' => $sale->change_total,
            'refunded' => $sale->refunded_total,
        ];
    }

    public function pdf(PosSale $sale, bool $isCopy): string
    {
        $data = $this->data($sale, $isCopy);
        $document = new CommercialDocument(
            title: $isCopy ? 'COMPROVANTE DE VENDA — 2ª VIA' : 'COMPROVANTE DE VENDA',
            notice: self::NOTICE,
            issuer: ['name' => $data['company']['name'], 'lines' => array_values(array_filter([$data['company']['document'] ? "CNPJ/CPF: {$data['company']['document']}" : null, $data['header']]))],
            facts: [['Venda', (string) $data['number']], ['Data', (string) $data['date']], ['Caixa', "{$data['terminal']} · sessão {$data['session']}"], ['Operador', $data['operator']]],
        );

        if ($data['customer'] !== null) {
            $document->party('Cliente', (string) $data['customer']['name'], array_filter([$data['customer']['document'] ? "CPF/CNPJ: {$data['customer']['document']}" : null]));
        }

        $available = PdfWriter::WIDTH - 72;
        $document->table(
            [['label' => 'Item', 'width' => $available - 280], ['label' => 'Qtd.', 'width' => 60, 'align' => 'right'], ['label' => 'Preço', 'width' => 70, 'align' => 'right'], ['label' => 'Desconto', 'width' => 70, 'align' => 'right'], ['label' => 'Total', 'width' => 80, 'align' => 'right']],
            array_values(array_map(fn (array $item): array => [$item['description'], "{$item['quantity']} {$item['unit']}", DocumentFormat::money($item['unit_price']), DocumentFormat::money($item['discount']), DocumentFormat::money($item['total'])], $data['items'])),
        );
        $document->totals([
            ['Itens', DocumentFormat::money($data['items_total']), false],
            ['Desconto', DocumentFormat::money($data['discount']), false],
            ['Total', DocumentFormat::money($data['total']), true],
            ['Troco', DocumentFormat::money($data['change']), false],
        ]);
        $document->section('Pagamentos', array_values(array_map(fn (array $payment): string => "{$payment['method']}: ".DocumentFormat::money($payment['amount'])
            .($payment['is_received'] ? ($payment['is_informed'] ? ' (informado pelo operador)' : '') : " — a prazo, vence em {$payment['due_date']}"), $data['payments'])) ?: ['Sem pagamento.']);

        if ($data['footer']) {
            $document->section('Observações', [(string) $data['footer']]);
        }

        return $document->output();
    }
}
