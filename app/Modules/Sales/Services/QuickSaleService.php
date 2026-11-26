<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Sales\Data\SaleAbilities;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleOrderItem;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Venda de balcão: cria, confirma, atende integralmente e conclui a venda numa única transação, pelos mesmos
 * serviços do fluxo completo. Qualquer falha (por exemplo, estoque insuficiente) desfaz tudo.
 */
class QuickSaleService
{
    public function __construct(
        private SaleOrderService $orders,
        private SaleFulfillmentService $fulfillments,
    ) {}

    /** @param array<string, mixed> $data */
    public function register(Company $company, User $user, array $data, SaleAbilities $abilities): SaleOrder
    {
        try {
            return DB::transaction(function () use ($company, $user, $data, $abilities): SaleOrder {
                $order = $this->orders->create($company, $user, $data, $abilities, isQuick: true);

                if ($order->status !== SaleOrderStatus::Draft) {
                    return $order;
                }

                $order = $this->orders->confirm($order, $user);
                $this->fulfillments->fulfill($order, $user, [
                    'fulfilled_at' => $order->order_date->format('Y-m-d'),
                    'method' => $order->delivery_method->value,
                    'notes' => null,
                    'idempotency_key' => "{$data['idempotency_key']}:fulfillment",
                    'items' => $order->items()->get()->map(fn (SaleOrderItem $item): array => ['sale_order_item_id' => $item->id, 'quantity' => $item->quantity])->all(),
                ]);

                return $this->orders->complete($order->refresh(), $user, 'Venda rápida.');
            });
        } catch (UniqueConstraintViolationException $exception) {
            return SaleOrder::query()->where('company_id', $company->id)->where('idempotency_key', $data['idempotency_key'])->first() ?? throw $exception;
        }
    }
}
