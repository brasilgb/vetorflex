<?php

namespace App\Modules\Sales\Documents;

use App\Modules\Core\Documents\CommercialDocument;
use App\Modules\Core\Documents\DocumentFormat as Format;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleFulfillmentItem;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleOrderItem;
use App\Modules\Sales\Models\SalePaymentSchedule;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Sales\Models\SaleQuoteItem;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnItem;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;

/**
 * Documentos comerciais de venda, sem natureza fiscal, gerados a partir dos dados históricos.
 * Destinam-se ao cliente: nunca apresentam custos internos nem margens.
 */
class SaleDocument
{
    private const NOTICE = 'Documento sem valor fiscal.';

    public function quote(SaleQuote $quote): string
    {
        $document = $this->document($quote->company, 'ORÇAMENTO', [
            ['Número', "#{$quote->number}"],
            ['Emissão', $quote->issue_date->format('d/m/Y')],
            ['Validade', $quote->valid_until->format('d/m/Y')],
            ['Situação', $quote->status->label()],
        ]);
        $this->customer($document, $quote->customer_snapshot);
        $this->items($document, array_values($quote->items->all()));
        $this->totals($document, $quote);
        $this->payment($document, $quote);
        $this->texts($document, $quote->commercial_terms, $quote->notes);
        $document->section('Emissão', ["Responsável: {$quote->seller_name}. Gerado em ".now($quote->company->timezone)->format('d/m/Y H:i').'.']);

        return $document->output();
    }

    public function order(SaleOrder $order): string
    {
        $document = $this->document($order->company, 'PEDIDO DE VENDA', array_values(array_filter([
            ['Número', "#{$order->number}"],
            ['Data', $order->order_date->format('d/m/Y')],
            $order->expected_date === null ? null : ['Previsão', $order->expected_date->format('d/m/Y')],
            ['Situação', $order->status->label()],
            $order->quote === null ? null : ['Orçamento', "#{$order->quote->number}"],
            $order->external_reference === null ? null : ['Referência', $order->external_reference],
        ])));
        $this->customer($document, $order->customer_snapshot);
        $this->items($document, array_values($order->items->all()));
        $this->totals($document, $order);
        $this->payment($document, $order);
        $document->section('Atendimento', array_values(array_filter([$order->delivery_method->label(), $order->delivery_address['text'] ?? null])));
        $this->texts($document, $order->commercial_terms, $order->notes);
        $document->section('Emissão', ["Responsável: {$order->seller_name}. Gerado em ".now($order->company->timezone)->format('d/m/Y H:i').'.']);

        return $document->output();
    }

    public function fulfillment(SaleFulfillment $fulfillment): string
    {
        $order = $fulfillment->order;
        $document = $this->document($order->company, 'COMPROVANTE DE ATENDIMENTO', [
            ['Atendimento', "#{$fulfillment->number}"],
            ['Venda', "#{$order->number}"],
            ['Data', $fulfillment->fulfilled_at->format('d/m/Y')],
            ['Situação', $fulfillment->status->label()],
        ]);
        $this->customer($document, $order->customer_snapshot);
        $document->table(
            [['label' => 'Item', 'width' => 383.0], ['label' => 'Qtd.', 'width' => 80.0, 'align' => 'right'], ['label' => 'Un.', 'width' => 60.0, 'align' => 'center']],
            array_values($fulfillment->items->map(fn (SaleFulfillmentItem $item): array => [$item->orderItem->description, Format::quantity($item->quantity), $item->orderItem->unit_code])->all()),
        );
        $document->section('Atendimento', array_values(array_filter([$fulfillment->method->label(), $order->delivery_address['text'] ?? null, $fulfillment->notes])));
        $document->section('Recebimento das mercadorias', ['Recebi os itens acima em perfeitas condições.', '', 'Assinatura: ______________________________________   Data: ____/____/______']);
        $document->section('Emissão', ["Registrado por {$fulfillment->user_name}. Este comprovante não substitui documento fiscal."]);

        return $document->output();
    }

    public function saleReturn(SaleReturn $return): string
    {
        $order = $return->order;
        $document = $this->document($order->company, 'DEVOLUÇÃO DE VENDA', [
            ['Devolução', "#{$return->number}"],
            ['Venda', "#{$order->number}"],
            ['Atendimento', "#{$return->fulfillment->number}"],
            ['Data', $return->returned_at->format('d/m/Y')],
        ]);
        $this->customer($document, $order->customer_snapshot);
        $document->table(
            [['label' => 'Item', 'width' => 323.0], ['label' => 'Qtd.', 'width' => 70.0, 'align' => 'right'], ['label' => 'Un.', 'width' => 40.0, 'align' => 'center'], ['label' => 'Valor', 'width' => 90.0, 'align' => 'right']],
            array_values($return->items->map(fn (SaleReturnItem $item): array => [$item->fulfillmentItem->orderItem->description, Format::quantity($item->quantity), $item->fulfillmentItem->orderItem->unit_code, Format::money($item->historical_value)])->all()),
        );
        $document->totals([['Valor devolvido', Format::money($return->total_value), true]]);
        $document->section('Motivo', [$return->reason]);
        $document->section('Emissão', ["Registrado por {$return->user_name}. O tratamento financeiro da devolução é feito à parte."]);

        return $document->output();
    }

    /** @param list<array{string, string}> $facts */
    private function document(Company $company, string $title, array $facts): CommercialDocument
    {
        $settings = $company->settings ?? [];

        return new CommercialDocument(
            title: $title,
            notice: self::NOTICE,
            issuer: ['name' => $company->name, 'lines' => array_values(array_filter([
                ($settings['document'] ?? null) ? "CNPJ/CPF: {$settings['document']}" : null,
                $settings['address'] ?? null,
                implode(' · ', array_filter([$settings['phone'] ?? null, $settings['email'] ?? null])) ?: null,
            ]))],
            facts: $facts,
        );
    }

    /** @param array<string, mixed>|null $customer */
    private function customer(CommercialDocument $document, ?array $customer): void
    {
        if ($customer === null) {
            $document->party('Cliente', 'Consumidor não identificado', []);

            return;
        }

        $document->party('Cliente', (string) ($customer['name'] ?? ''), array_values(array_filter([
            $customer['trade_name'] ?? null,
            ($customer['document'] ?? null) ? "CNPJ/CPF: {$customer['document']}" : null,
            $customer['address'] ?? null,
            implode(' · ', array_filter([$customer['phone'] ?? null, $customer['email'] ?? null, ($customer['contact'] ?? null) ? "Contato: {$customer['contact']}" : null])) ?: null,
        ])));
    }

    /** @param list<SaleQuoteItem|SaleOrderItem> $items */
    private function items(CommercialDocument $document, array $items): void
    {
        $document->table(
            [['label' => 'Item', 'width' => 233.0], ['label' => 'Qtd.', 'width' => 55.0, 'align' => 'right'], ['label' => 'Un.', 'width' => 30.0, 'align' => 'center'], ['label' => 'Preço unit.', 'width' => 70.0, 'align' => 'right'], ['label' => 'Desconto', 'width' => 60.0, 'align' => 'right'], ['label' => 'Total', 'width' => 75.0, 'align' => 'right']],
            array_map(fn (SaleQuoteItem|SaleOrderItem $item): array => [
                trim($item->description.($item->sku ? " (SKU {$item->sku})" : '').($item->notes ? " — {$item->notes}" : '')),
                Format::quantity($item->quantity),
                $item->unit_code,
                Format::decimal($item->unit_price),
                BigDecimal::of($item->discount)->isZero() ? '' : Format::decimal($item->discount),
                Format::decimal($item->total),
            ], $items),
        );
    }

    private function totals(CommercialDocument $document, SaleQuote|SaleOrder $sale): void
    {
        $document->totals(array_values(array_filter([
            ['Total dos itens', Format::money($sale->items_total), false],
            BigDecimal::of($sale->discount)->isZero() ? null : ['Desconto', '- '.Format::money($sale->discount), false],
            BigDecimal::of($sale->freight)->isZero() ? null : ['Frete', Format::money($sale->freight), false],
            BigDecimal::of($sale->other_costs)->isZero() ? null : ['Outras despesas', Format::money($sale->other_costs), false],
            ['Total', Format::money($sale->total), true],
        ])));
    }

    private function payment(CommercialDocument $document, SaleQuote|SaleOrder $sale): void
    {
        $document->section('Condições de pagamento', [
            implode(' · ', array_filter([$sale->payment_condition->label(), $sale->payment_method?->label()])),
            ...$sale->paymentSchedules->map(fn (SalePaymentSchedule $schedule): string => sprintf(
                '%s %d — vencimento %s — %s%s',
                $schedule->is_entry ? 'Entrada' : 'Parcela',
                $schedule->sequence,
                $schedule->due_date->format('d/m/Y'),
                Format::money($schedule->amount),
                $schedule->method === null ? '' : " ({$schedule->method->label()})",
            ))->all(),
            ...($sale->payment_notes ? [$sale->payment_notes] : []),
        ]);
    }

    private function texts(CommercialDocument $document, ?string $terms, ?string $notes): void
    {
        if ($terms) {
            $document->section('Condições comerciais', [$terms]);
        }

        if ($notes) {
            $document->section('Observações', [$notes]);
        }
    }
}
