<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Commerce\PaymentSchedule;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Inventory\Services\InventoryReservationService;
use App\Modules\Sales\Data\SaleAbilities;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SaleOrigin;
use App\Modules\Sales\Events\SaleCancelled;
use App\Modules\Sales\Events\SaleCompleted;
use App\Modules\Sales\Events\SaleConfirmed;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleOrderItem;
use App\Modules\Sales\Models\SalePaymentSchedule;
use App\Modules\Sales\Support\Idempotency;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro e máquina de estados do pedido de venda.
 *
 * Ordem de bloqueio: pedido → numeração → unidades comercializáveis em ordem crescente (reservas e estoque).
 * Com o estoque habilitado, a confirmação reserva os itens que controlam estoque; cancelamento e encerramento de
 * saldo liberam as reservas. A saída física ocorre somente no atendimento (SaleFulfillmentService).
 */
class SaleOrderService
{
    public const RESERVATION_REFERENCE = 'sale_order_item';

    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private SaleDocumentBuilder $builder,
        private SequenceService $sequences,
        private SaleHistory $history,
        private SalesStock $stock,
        private InventoryReservationService $reservations,
        private AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, User $user, array $data, SaleAbilities $abilities, bool $isQuick = false): SaleOrder
    {
        $key = $data['idempotency_key'] ?? null;
        $hash = Idempotency::hash($data);

        try {
            return DB::transaction(function () use ($company, $user, $data, $abilities, $isQuick, $key, $hash): SaleOrder {
                $existing = $this->existing($company, $key, $hash);

                if ($existing !== null) {
                    return $existing;
                }

                $order = new SaleOrder([
                    'company_id' => $company->id,
                    'number' => $this->sequences->next($company, 'sale_order'),
                    'status' => SaleOrderStatus::Draft,
                    'origin' => SaleOrigin::Direct,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'is_quick' => $isQuick,
                    'idempotency_key' => $key,
                    'request_hash' => $key === null ? null : $hash,
                ]);
                $this->builder->fill($company, $order, $user, $data, $abilities);
                $this->history->record($order, null, SaleOrderStatus::Draft->value, $user, $isQuick ? 'Venda rápida.' : null);
                $this->audit->record('sales.order_created', $company, $user, $order, ['number' => $order->number]);

                return $order;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($company, $key, $hash) ?? throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(SaleOrder $order, User $user, array $data, SaleAbilities $abilities): SaleOrder
    {
        return DB::transaction(function () use ($order, $user, $data, $abilities): SaleOrder {
            $order = $this->lock($order);

            if ($order->status !== SaleOrderStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Somente vendas em rascunho podem ser editadas.']);
            }

            $this->builder->fill($order->company, $order, $user, $data, $abilities);
            $this->audit->record('sales.order_updated', $order->company, $user, $order, ['number' => $order->number]);

            return $order;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Confirma a venda: valida cliente e pagamento, fixa o valor líquido de cada item (rateio do desconto geral)
     * e, com o estoque habilitado, reserva as mercadorias.
     */
    public function confirm(SaleOrder $order, User $user): SaleOrder
    {
        return DB::transaction(function () use ($order, $user): SaleOrder {
            $order = $this->lock($order);
            $this->ensureTransition($order, SaleOrderStatus::Confirmed);
            $items = $order->items()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Inclua ao menos um item antes de confirmar.']);
            }

            if ($order->customer !== null && ! $order->customer->is_active) {
                throw ValidationException::withMessages(['customer_party_id' => 'O cliente está inativo.']);
            }

            PaymentSchedule::build(
                $order->payment_condition,
                $order->payment_method?->value,
                array_values($order->paymentSchedules->map(fn (SalePaymentSchedule $schedule): array => ['due_date' => $schedule->due_date->format('Y-m-d'), 'amount' => $schedule->amount, 'method' => $schedule->method?->value])->all()),
                $order->total,
                $order->order_date->format('Y-m-d'),
            );

            $net = CommercialMath::allocate(array_values($items->map(fn (SaleOrderItem $item): array => ['total' => $item->total, 'quantity' => $item->quantity])->all()), (string) BigDecimal::of($order->discount)->negated());

            foreach ($items->values() as $index => $item) {
                $item->update(['net_total' => $net[$index]]);
            }

            $reservesStock = $this->stock->isEnabled($order->company);
            $order->update(['confirmed_at' => now(), 'reserves_stock' => $reservesStock]);

            if ($reservesStock) {
                $this->syncReservations($order, $items);
            }

            $this->transition($order, SaleOrderStatus::Confirmed, $user);
            SaleConfirmed::dispatch($order);

            return $order;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function cancel(SaleOrder $order, User $user, string $reason): SaleOrder
    {
        return DB::transaction(function () use ($order, $user, $reason): SaleOrder {
            $order = $this->lock($order);

            if ($order->items()->where('fulfilled_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['status' => 'A venda possui itens atendidos. Estorne os atendimentos, encerre o saldo pendente ou registre devolução.']);
            }

            $this->transition($order, SaleOrderStatus::Cancelled, $user, $reason);
            $this->releaseReservations($order);
            SaleCancelled::dispatch($order, $reason);

            return $order;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** Encerra o saldo não atendido de uma venda parcialmente atendida e libera as reservas restantes. */
    public function closeBalance(SaleOrder $order, User $user, string $reason): SaleOrder
    {
        return DB::transaction(function () use ($order, $user, $reason): SaleOrder {
            $order = $this->lock($order);

            if ($order->status !== SaleOrderStatus::PartiallyFulfilled) {
                throw ValidationException::withMessages(['status' => 'Somente vendas parcialmente atendidas têm saldo a encerrar.']);
            }

            $order->update(['balance_closed_at' => now()]);
            $this->releaseReservations($order);
            $this->transition($order, SaleOrderStatus::Fulfilled, $user, "Saldo não atendido encerrado: {$reason}");

            return $order;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** Conclusão comercial. Não registra recebimento: a situação financeira pertence ao Financeiro. */
    public function complete(SaleOrder $order, User $user, ?string $reason = null): SaleOrder
    {
        return DB::transaction(function () use ($order, $user, $reason): SaleOrder {
            $order = $this->lock($order);
            $this->ensureTransition($order, SaleOrderStatus::Completed);
            $order->update(['completed_at' => now()]);
            $this->transition($order, SaleOrderStatus::Completed, $user, $reason);
            SaleCompleted::dispatch($order);

            return $order;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** Usado também pelos atendimentos, já dentro da transação e com o pedido bloqueado. */
    public function transition(SaleOrder $order, SaleOrderStatus $next, User $user, ?string $reason = null): void
    {
        $this->ensureTransition($order, $next);
        $previous = $order->status;
        $order->update(['status' => $next]);
        $this->history->record($order, $previous->value, $next->value, $user, $reason);
    }

    /**
     * Mantém reservada a quantidade ainda não atendida de cada item que controla estoque, em ordem crescente de unidade.
     *
     * @param  Collection<int, SaleOrderItem>  $items
     */
    public function syncReservations(SaleOrder $order, Collection $items): void
    {
        if (! $order->reserves_stock) {
            return;
        }

        $isOpen = $order->balance_closed_at === null && in_array($order->status, [SaleOrderStatus::Draft, SaleOrderStatus::Confirmed, SaleOrderStatus::PartiallyFulfilled], true);

        foreach ($items->filter(fn (SaleOrderItem $item): bool => $item->controls_stock && $item->product_variant_id !== null)->sortBy(['product_variant_id', 'id']) as $item) {
            $pending = BigDecimal::max(BigDecimal::zero(), BigDecimal::of($item->quantity)->minus($item->fulfilled_quantity));
            $this->reservations->reserve($order->company_id, $item->product_variant_id, self::RESERVATION_REFERENCE, (string) $item->id, $isOpen ? (string) $pending : '0');
        }
    }

    public function releaseReservations(SaleOrder $order): void
    {
        if (! $order->reserves_stock) {
            return;
        }

        foreach ($order->items()->get()->filter(fn (SaleOrderItem $item): bool => $item->controls_stock && $item->product_variant_id !== null)->sortBy(['product_variant_id', 'id']) as $item) {
            $this->reservations->release($order->company_id, $item->product_variant_id, self::RESERVATION_REFERENCE, (string) $item->id);
        }
    }

    public function lock(SaleOrder $order): SaleOrder
    {
        return SaleOrder::query()->where('company_id', $order->company_id)->lockForUpdate()->findOrFail($order->id);
    }

    private function ensureTransition(SaleOrder $order, SaleOrderStatus $next): void
    {
        if (! $order->status->canTransitionTo($next)) {
            throw ValidationException::withMessages(['status' => "Não é possível passar a venda de \"{$order->status->label()}\" para \"{$next->label()}\"."]);
        }
    }

    private function existing(Company $company, ?string $key, string $hash): ?SaleOrder
    {
        if ($key === null) {
            return null;
        }

        $existing = SaleOrder::query()->where('company_id', $company->id)->where('idempotency_key', $key)->first();

        if ($existing !== null && ! hash_equals((string) $existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta requisição já foi usada para outra venda. Recarregue a página.']);
        }

        return $existing;
    }
}
