<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductCatalog;
use App\Modules\Purchases\Enums\PaymentCondition;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use App\Modules\Purchases\Models\PurchasePaymentSchedule;
use App\Modules\Purchases\Models\SupplierProduct;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Purchases\Support\PaymentSchedule;
use App\Modules\Purchases\Support\PurchaseMath;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro e máquina de estados do pedido. Nenhuma operação deste serviço movimenta estoque.
 *
 * Quem não pode informar custos (`purchases.manage_cost`) edita apenas itens e quantidades:
 * os valores enviados são descartados e os já gravados são preservados.
 */
class PurchaseOrderService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private ProductCatalog $catalog,
        private SequenceService $sequences,
        private AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, User $user, array $data, bool $canManageCost, bool $isQuick = false): PurchaseOrder
    {
        $key = $data['idempotency_key'] ?? null;
        $hash = $this->requestHash($data);

        try {
            return DB::transaction(function () use ($company, $user, $data, $canManageCost, $isQuick, $key, $hash): PurchaseOrder {
                $existing = $this->existing($company, $key, $hash, lock: false);

                if ($existing !== null) {
                    return $existing;
                }

                $order = new PurchaseOrder([
                    'company_id' => $company->id,
                    'number' => $this->sequences->next($company, 'purchase_order'),
                    'status' => PurchaseOrderStatus::Draft,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'is_quick' => $isQuick,
                    'idempotency_key' => $key,
                    'request_hash' => $key === null ? null : $hash,
                ]);
                $this->fill($company, $order, $data, $canManageCost);
                $this->recordTransition($order, null, PurchaseOrderStatus::Draft, $user, $isQuick ? 'Compra rápida.' : null);
                $this->audit->record('purchase.order_created', $company, $user, $order, ['number' => $order->number]);

                return $order;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($company, $key, $hash, lock: true) ?? throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(PurchaseOrder $order, User $user, array $data, bool $canManageCost): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user, $data, $canManageCost): PurchaseOrder {
            $order = $this->lock($order);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Somente pedidos em rascunho podem ser editados.']);
            }

            $this->fill($order->company, $order, $data, $canManageCost);
            $this->audit->record('purchase.order_updated', $order->company, $user, $order, ['number' => $order->number]);

            return $order;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Confirma o pedido e fixa o custo de aquisição de cada item. A previsão de pagamento precisa fechar o total.
     */
    public function confirm(PurchaseOrder $order, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user): PurchaseOrder {
            $order = $this->lock($order);
            $this->ensureTransition($order, PurchaseOrderStatus::Confirmed);
            $items = $order->items()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Inclua ao menos um item antes de confirmar.']);
            }

            foreach ($items as $item) {
                if (! $item->is_free && BigDecimal::of($item->unit_cost)->isZero()) {
                    throw ValidationException::withMessages(['items' => "Informe o custo de \"{$item->description}\" ou marque o item como gratuito."]);
                }
            }

            if (! $order->supplier->is_active) {
                throw ValidationException::withMessages(['supplier_profile_id' => 'O fornecedor está inativo.']);
            }

            PaymentSchedule::build(
                $order->payment_condition,
                $order->payment_method?->value,
                array_values($order->paymentSchedules->map(fn (PurchasePaymentSchedule $schedule): array => ['due_date' => $schedule->due_date->format('Y-m-d'), 'amount' => $schedule->amount])->all()),
                $order->total,
                $order->order_date->format('Y-m-d'),
            );

            $adjustment = (string) BigDecimal::of($order->freight)->plus($order->insurance)->plus($order->other_costs)->minus($order->discount);
            $allocated = PurchaseMath::allocate(array_values($items->map(fn (PurchaseOrderItem $item): array => ['total' => $item->total, 'quantity' => $item->quantity])->all()), $adjustment);

            foreach ($items->values() as $index => $item) {
                $item->update(['acquisition_total' => $allocated[$index]]);
            }

            $order->update(['confirmed_at' => now()]);
            $this->transition($order, PurchaseOrderStatus::Confirmed, $user);

            return $order;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function cancel(PurchaseOrder $order, User $user, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user, $reason): PurchaseOrder {
            $order = $this->lock($order);

            if ($order->items()->where('received_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['status' => 'O pedido possui mercadorias recebidas. Estorne os recebimentos ou registre devolução.']);
            }

            $this->transition($order, PurchaseOrderStatus::Cancelled, $user, $reason);

            return $order;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** Encerra o saldo pendente de um pedido parcialmente recebido. Custos não recebidos não são rateados. */
    public function closeBalance(PurchaseOrder $order, User $user, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user, $reason): PurchaseOrder {
            $order = $this->lock($order);

            if ($order->status !== PurchaseOrderStatus::PartiallyReceived) {
                throw ValidationException::withMessages(['status' => 'Somente pedidos parcialmente recebidos têm saldo a encerrar.']);
            }

            $order->update(['balance_closed_at' => now()]);
            $this->transition($order, PurchaseOrderStatus::Received, $user, "Saldo pendente encerrado: {$reason}");

            return $order;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** Usado também pelos recebimentos, já dentro da transação e com o pedido bloqueado. */
    public function transition(PurchaseOrder $order, PurchaseOrderStatus $next, User $user, ?string $reason = null): void
    {
        $this->ensureTransition($order, $next);
        $previous = $order->status;
        $order->update(['status' => $next]);
        $this->recordTransition($order, $previous, $next, $user, $reason);
        $this->audit->record('purchase.order_status_changed', $order->company, $user, $order, ['from' => $previous->value, 'to' => $next->value]);
    }

    public function lock(PurchaseOrder $order): PurchaseOrder
    {
        return PurchaseOrder::query()->where('company_id', $order->company_id)->lockForUpdate()->findOrFail($order->id);
    }

    private function ensureTransition(PurchaseOrder $order, PurchaseOrderStatus $next): void
    {
        if (! $order->status->canTransitionTo($next)) {
            throw ValidationException::withMessages(['status' => "Não é possível passar de \"{$order->status->label()}\" para \"{$next->label()}\"."]);
        }
    }

    private function recordTransition(PurchaseOrder $order, ?PurchaseOrderStatus $previous, PurchaseOrderStatus $next, User $user, ?string $reason): void
    {
        $order->statusHistory()->create([
            'company_id' => $order->company_id,
            'from_status' => $previous,
            'to_status' => $next,
            'reason' => $reason,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'occurred_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function fill(Company $company, PurchaseOrder $order, array $data, bool $canManageCost): void
    {
        $supplier = SupplierProfile::query()->where('company_id', $company->id)->with('party')->whereKey($data['supplier_profile_id'])->firstOrFail();

        if (! $supplier->is_active && $supplier->id !== $order->supplier_profile_id) {
            throw ValidationException::withMessages(['supplier_profile_id' => 'O fornecedor está inativo.']);
        }

        $order->fill([
            ...Arr::only($data, ['order_date', 'expected_date', 'external_reference', 'notes']),
            'supplier_profile_id' => $supplier->id,
            'supplier_snapshot' => $this->supplierSnapshot($supplier),
        ]);

        if ($canManageCost) {
            $order->fill([
                'discount' => PurchaseMath::money($data['discount'] ?? '0'),
                'freight' => PurchaseMath::money($data['freight'] ?? '0'),
                'insurance' => PurchaseMath::money($data['insurance'] ?? '0'),
                'other_costs' => PurchaseMath::money($data['other_costs'] ?? '0'),
                'payment_condition' => $data['payment_condition'] ?? PaymentCondition::Undefined->value,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_notes' => $data['payment_notes'] ?? null,
            ]);
        }

        $order->save();
        $items = $this->replaceItems($company, $order, $supplier, $data['items'], $canManageCost);
        $itemsTotal = array_reduce($items, fn (BigDecimal $carry, PurchaseOrderItem $item): BigDecimal => $carry->plus($item->total), BigDecimal::zero());

        if ($itemsTotal->isLessThan($order->discount)) {
            throw ValidationException::withMessages(['discount' => 'O desconto geral não pode superar o total dos itens.']);
        }

        $total = $itemsTotal->minus($order->discount)->plus($order->freight)->plus($order->insurance)->plus($order->other_costs);
        $order->update(['items_total' => PurchaseMath::money($itemsTotal), 'total' => PurchaseMath::money($total)]);

        if ($canManageCost) {
            $schedule = PaymentSchedule::build($order->payment_condition, $order->payment_method?->value, $data['installments'] ?? [], $order->total, $order->order_date->format('Y-m-d'));
            $order->paymentSchedules()->delete();
            $order->paymentSchedules()->createMany(array_map(fn (array $row): array => [...$row, 'company_id' => $company->id], $schedule));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<PurchaseOrderItem>
     */
    private function replaceItems(Company $company, PurchaseOrder $order, SupplierProfile $supplier, array $rows, bool $canManageCost): array
    {
        $previous = $order->items()->get()->keyBy('id');
        $order->items()->delete();
        $variants = $this->catalog->sellableUnits($company)->whereKey(array_column($rows, 'product_variant_id'))->get()->keyBy('id');
        $supplierCodes = SupplierProduct::query()->where('supplier_profile_id', $supplier->id)->whereIn('product_variant_id', $variants->keys())->pluck('supplier_code', 'product_variant_id');
        $items = [];

        foreach ($rows as $position => $row) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get((int) $row['product_variant_id']);

            if ($variant === null) {
                throw ValidationException::withMessages(["items.{$position}.product_variant_id" => 'Item inativo ou inexistente.']);
            }

            $unit = $variant->product->unit;

            if (! $unit->allows_fraction && PurchaseMath::hasFraction((string) $row['quantity'])) {
                throw ValidationException::withMessages(["items.{$position}.quantity" => "A unidade {$unit->code} não aceita quantidade fracionária."]);
            }

            $kept = $previous->get($row['id'] ?? 0);
            $costs = $canManageCost
                ? ['is_free' => (bool) ($row['is_free'] ?? false), 'unit_cost' => (string) ($row['unit_cost'] ?? '0'), 'discount' => PurchaseMath::money($row['discount'] ?? '0')]
                : ['is_free' => $kept->is_free ?? false, 'unit_cost' => $kept->unit_cost ?? '0', 'discount' => $kept->discount ?? '0.00'];

            if ($costs['is_free']) {
                $costs = ['is_free' => true, 'unit_cost' => '0', 'discount' => '0.00'];
            }

            $items[] = $order->items()->create([
                'company_id' => $company->id,
                'product_variant_id' => $variant->id,
                'position' => $position + 1,
                'description' => $variant->displayName(),
                'sku' => $variant->sku,
                'supplier_code' => $supplierCodes->get($variant->id),
                'unit_code' => $unit->code,
                'allows_fraction' => $unit->allows_fraction,
                'is_service' => $variant->product->type === ProductType::Service,
                'quantity' => (string) $row['quantity'],
                ...$costs,
                'total' => PurchaseMath::itemTotal((string) $row['quantity'], $costs['unit_cost'], $costs['discount'], $position),
            ]);
        }

        return $items;
    }

    /** @return array<string, string|null> */
    private function supplierSnapshot(SupplierProfile $supplier): array
    {
        $party = $supplier->party;
        $address = $party->addresses()->orderBy('id')->first();

        return [
            'name' => $party->name,
            'trade_name' => $party->trade_name,
            'document' => BrazilianDocument::format($party->document),
            'state_registration' => $party->state_registration,
            'email' => $party->email,
            'phone' => $party->phone ?? $party->mobile ?? $party->whatsapp,
            'address' => $address === null ? null : collect([
                trim("{$address->street}, {$address->number}", ', '),
                $address->district,
                trim("{$address->city}/{$address->state}", '/'),
                $address->postal_code,
            ])->filter()->implode(' · '),
        ];
    }

    private function existing(Company $company, ?string $key, string $hash, bool $lock): ?PurchaseOrder
    {
        if ($key === null) {
            return null;
        }

        $existing = PurchaseOrder::query()->where('company_id', $company->id)->where('idempotency_key', $key)
            ->when($lock, fn ($query) => $query->sharedLock())->first();

        if ($existing !== null && ! hash_equals((string) $existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta requisição já foi usada para outro pedido. Recarregue a página.']);
        }

        return $existing;
    }

    /** @param array<string, mixed> $data */
    private function requestHash(array $data): string
    {
        return hash('sha256', (string) json_encode(Arr::sortRecursive(Arr::except($data, ['idempotency_key']))));
    }
}
