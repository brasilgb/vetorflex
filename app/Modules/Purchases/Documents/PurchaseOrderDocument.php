<?php

namespace App\Modules\Purchases\Documents;

use App\Modules\Core\Documents\CommercialDocument;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use App\Modules\Purchases\Models\PurchasePaymentSchedule;
use Brick\Math\BigDecimal;

/**
 * Pedido de compra em PDF, a partir dos dados históricos do pedido. Sem permissão de custos, os valores são omitidos.
 */
class PurchaseOrderDocument
{
    public function render(PurchaseOrder $order, bool $withValues): string
    {
        $company = $order->company;
        $settings = $company->settings ?? [];
        $document = new CommercialDocument(
            title: 'PEDIDO DE COMPRA',
            notice: 'Documento sem valor fiscal.',
            issuer: ['name' => $company->name, 'lines' => array_values(array_filter([
                $settings['document'] ?? null ? "CNPJ/CPF: {$settings['document']}" : null,
                $settings['address'] ?? null,
                implode(' · ', array_filter([$settings['phone'] ?? null, $settings['email'] ?? null])) ?: null,
            ]))],
            facts: array_values(array_filter([
                ['Número', "#{$order->number}"],
                ['Data', $order->order_date->format('d/m/Y')],
                $order->expected_date === null ? null : ['Previsão de entrega', $order->expected_date->format('d/m/Y')],
                ['Situação', $order->status->label()],
                $order->external_reference === null ? null : ['Referência', $order->external_reference],
            ])),
        );
        $supplier = $order->supplier_snapshot;
        $document->party('Fornecedor', $supplier['name'] ?? '', array_values(array_filter([
            $supplier['trade_name'] ?? null,
            implode(' · ', array_filter([
                ($supplier['document'] ?? null) ? "CNPJ/CPF: {$supplier['document']}" : null,
                ($supplier['state_registration'] ?? null) ? "IE: {$supplier['state_registration']}" : null,
            ])) ?: null,
            $supplier['address'] ?? null,
            implode(' · ', array_filter([$supplier['phone'] ?? null, $supplier['email'] ?? null])) ?: null,
        ])));

        $columns = $withValues
            ? [['label' => 'Item', 'width' => 223.0], ['label' => 'Cód. forn.', 'width' => 60.0], ['label' => 'Qtd.', 'width' => 55.0, 'align' => 'right'], ['label' => 'Un.', 'width' => 28.0, 'align' => 'center'], ['label' => 'Custo unit.', 'width' => 60.0, 'align' => 'right'], ['label' => 'Desconto', 'width' => 45.0, 'align' => 'right'], ['label' => 'Total', 'width' => 52.0, 'align' => 'right']]
            : [['label' => 'Item', 'width' => 343.0], ['label' => 'Cód. forn.', 'width' => 80.0], ['label' => 'Qtd.', 'width' => 70.0, 'align' => 'right'], ['label' => 'Un.', 'width' => 30.0, 'align' => 'center']];
        $document->table($columns, array_values($order->items->map(fn (PurchaseOrderItem $item): array => [
            trim($item->description.($item->sku ? " (SKU {$item->sku})" : '').($item->is_free ? ' — bonificado' : '')),
            $item->supplier_code ?? '',
            self::quantity($item->quantity),
            $item->unit_code,
            ...($withValues ? [self::decimal($item->unit_cost, 2, 4), self::decimal($item->discount), self::decimal($item->total)] : []),
        ])->all()));

        if ($withValues) {
            $document->totals(array_values(array_filter([
                ['Total dos itens', self::money($order->items_total), false],
                BigDecimal::of($order->discount)->isZero() ? null : ['Desconto', '- '.self::money($order->discount), false],
                BigDecimal::of($order->freight)->isZero() ? null : ['Frete', self::money($order->freight), false],
                BigDecimal::of($order->insurance)->isZero() ? null : ['Seguro', self::money($order->insurance), false],
                BigDecimal::of($order->other_costs)->isZero() ? null : ['Outras despesas', self::money($order->other_costs), false],
                ['Total do pedido', self::money($order->total), true],
            ])));
            $document->section('Condições de pagamento', [
                implode(' · ', array_filter([$order->payment_condition->label(), $order->payment_method?->label()])),
                ...$order->paymentSchedules->map(fn (PurchasePaymentSchedule $schedule): string => sprintf(
                    '%s %d — vencimento %s — %s%s',
                    $schedule->is_entry ? 'Entrada' : 'Parcela',
                    $schedule->sequence,
                    $schedule->due_date->format('d/m/Y'),
                    self::money($schedule->amount),
                    $schedule->method === null ? '' : " ({$schedule->method->label()})",
                ))->all(),
                ...($order->payment_notes ? [$order->payment_notes] : []),
            ]);
        }

        if ($order->notes) {
            $document->section('Observações', [$order->notes]);
        }

        $document->section('Emissão', ["Responsável: {$order->user_name}. Emitido em ".now($company->timezone)->format('d/m/Y H:i').'. Este pedido não substitui documento fiscal.']);

        return $document->output();
    }

    public static function money(string $value): string
    {
        return 'R$ '.self::decimal($value);
    }

    /**
     * @param  int<0, max>  $minimum
     * @param  int<0, max>  $maximum
     */
    public static function decimal(string $value, int $minimum = 2, int $maximum = 2): string
    {
        $decimal = BigDecimal::of($value)->toScale($maximum);
        $text = (string) $decimal;
        [$integer, $fraction] = array_pad(explode('.', $text), 2, '');
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, $minimum, '0');
        $sign = str_starts_with($integer, '-') ? '-' : '';
        $integer = number_format((int) ltrim($integer, '-'), 0, ',', '.');

        return $sign.$integer.($fraction === '' ? '' : ",{$fraction}");
    }

    public static function quantity(string $value): string
    {
        return self::decimal($value, 0, 4);
    }
}
