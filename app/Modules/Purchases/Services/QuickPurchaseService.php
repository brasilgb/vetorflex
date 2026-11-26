<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registro de uma compra que já aconteceu: cria, confirma e recebe integralmente o pedido, numa única transação,
 * pelos mesmos serviços do fluxo completo.
 */
class QuickPurchaseService
{
    public function __construct(
        private PurchaseOrderService $orders,
        private PurchaseReceiptService $receipts,
        private SupplierService $suppliers,
    ) {}

    /** @param array<string, mixed> $data */
    public function register(Company $company, User $user, array $data): PurchaseOrder
    {
        try {
            return DB::transaction(function () use ($company, $user, $data): PurchaseOrder {
                $existing = PurchaseOrder::query()->where('company_id', $company->id)->where('idempotency_key', $data['idempotency_key'])->first();

                // Na repetição, o fornecedor criado na primeira requisição é reutilizado e o conteúdo continua comparável.
                if (isset($data['new_supplier'])) {
                    $data['supplier_profile_id'] = $existing->supplier_profile_id ?? $this->suppliers->create($company, $user, $data['new_supplier'])->id;
                }

                $order = $this->orders->create($company, $user, $data, canManageCost: true, isQuick: true);

                if ($order->status !== PurchaseOrderStatus::Draft) {
                    return $order;
                }

                $order = $this->orders->confirm($order, $user);
                $this->receipts->receive($order, $user, [
                    'received_at' => $order->order_date->format('Y-m-d'),
                    'document_reference' => $data['document_reference'] ?? null,
                    'notes' => null,
                    'idempotency_key' => "{$data['idempotency_key']}:receipt",
                    'items' => $order->items()->get()->map(fn ($item): array => ['purchase_order_item_id' => $item->id, 'quantity' => $item->quantity])->all(),
                ]);

                return $order->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            return PurchaseOrder::query()->where('company_id', $company->id)->where('idempotency_key', $data['idempotency_key'])->sharedLock()->first() ?? throw $exception;
        }
    }
}
