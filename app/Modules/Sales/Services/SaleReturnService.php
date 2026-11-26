<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Enums\FulfillmentStatus;
use App\Modules\Sales\Events\SaleReturnRegistered;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleFulfillmentItem;
use App\Modules\Sales\Models\SaleOrderItem;
use App\Modules\Sales\Models\SaleReturn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Devolução comercial de itens atendidos. O valor devolvido é a parcela histórica da receita do atendimento e o custo,
 * a parcela do custo histórico; nada é recalculado pelo preço ou custo atual.
 *
 * Com reposição ao estoque, a entrada usa o custo histórico como valor, de modo que o custo médio volta a refletir
 * a mercadoria que retornou. Itens sem custo histórico entram sem valor e não alteram o custo médio.
 * O tratamento financeiro fica para o Financeiro (SaleReceivableSource).
 */
class SaleReturnService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private SaleOrderService $orders,
        private SalesStock $stock,
        private InventoryService $inventory,
        private SequenceService $sequences,
        private AuditService $audit,
    ) {}

    /**
     * Dados validados: `returned_at`, `reason`, `restock`, `idempotency_key` e `items` (`sale_fulfillment_item_id`, `quantity`).
     *
     * @param  array<string, mixed>  $data
     */
    public function register(SaleFulfillment $fulfillment, User $user, array $data): SaleReturn
    {
        $items = array_map(fn (array $row): array => [(int) $row['sale_fulfillment_item_id'], (string) BigDecimal::of($row['quantity'])->toScale(4)], $data['items']);
        sort($items);
        $hash = hash('sha256', (string) json_encode([$fulfillment->id, $data['returned_at'], $data['reason'], (bool) ($data['restock'] ?? true), $items]));

        try {
            return DB::transaction(fn (): SaleReturn => $this->registerLocked($fulfillment, $user, $data, $hash), self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($fulfillment->company_id, $data['idempotency_key'], $hash) ?? throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    private function registerLocked(SaleFulfillment $fulfillment, User $user, array $data, string $hash): SaleReturn
    {
        $order = $this->orders->lock($fulfillment->order);
        $fulfillment = SaleFulfillment::query()->lockForUpdate()->findOrFail($fulfillment->id);
        $existing = $this->existing($order->company_id, $data['idempotency_key'], $hash);

        if ($existing !== null) {
            return $existing;
        }

        if ($fulfillment->status !== FulfillmentStatus::Confirmed || ! $order->status->acceptsReturns()) {
            throw ValidationException::withMessages(['items' => 'Este atendimento não aceita devolução.']);
        }

        $fulfillmentItems = $fulfillment->items()->with('orderItem')->lockForUpdate()->get()->keyBy('id');
        $lines = [];

        foreach ($data['items'] as $index => $row) {
            $item = $fulfillmentItems->get((int) $row['sale_fulfillment_item_id']);
            $quantity = BigDecimal::of($row['quantity'])->toScale(4);

            if ($item === null) {
                throw ValidationException::withMessages(["items.{$index}.sale_fulfillment_item_id" => 'O item não pertence a este atendimento.']);
            }

            if ($quantity->isZero()) {
                continue;
            }

            $available = BigDecimal::of($item->quantity)->minus($item->returned_quantity);

            if ($quantity->isGreaterThan($available)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "Quantidade de \"{$item->orderItem->description}\" maior que a atendida e ainda não devolvida (".str_replace('.', ',', (string) $available->strippedOfTrailingZeros()).').']);
            }

            if (! $item->orderItem->allows_fraction && CommercialMath::hasFraction((string) $quantity)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "A unidade {$item->orderItem->unit_code} não aceita quantidade fracionária."]);
            }

            $lines[] = [$item, (string) $quantity];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Informe a quantidade devolvida de ao menos um item.']);
        }

        usort($lines, fn (array $first, array $second): int => [$first[0]->product_variant_id ?? 0, $first[0]->id] <=> [$second[0]->product_variant_id ?? 0, $second[0]->id]);
        $restock = (bool) ($data['restock'] ?? true);
        $return = SaleReturn::create([
            'company_id' => $order->company_id,
            'number' => $this->sequences->next($order->company, 'sale_return'),
            'sale_order_id' => $order->id,
            'sale_fulfillment_id' => $fulfillment->id,
            'returned_at' => $data['returned_at'],
            'reason' => $data['reason'],
            'restock' => $restock,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'idempotency_key' => $data['idempotency_key'],
            'request_hash' => $hash,
        ]);
        $moveStock = $restock && $this->stock->isEnabled($order->company);
        $total = BigDecimal::zero();
        $cost = BigDecimal::zero();
        $hasUnknownCost = false;
        $orderItems = $order->items()->lockForUpdate()->get()->keyBy('id');

        foreach ($lines as [$item, $quantity]) {
            [$historical, $costValue] = $this->returnItem($return, $item, $quantity, $user, $moveStock);
            $total = $total->plus($historical);
            $hasUnknownCost = $hasUnknownCost || $costValue === null;
            $cost = $cost->plus($costValue ?? '0');

            /** @var SaleOrderItem $orderItem */
            $orderItem = $orderItems->get($item->sale_order_item_id);
            $orderItem->update(['returned_quantity' => (string) BigDecimal::of($orderItem->returned_quantity)->plus($quantity)]);
        }

        $return->update(['total_value' => CommercialMath::money($total), 'cost_total' => $hasUnknownCost ? null : CommercialMath::money($cost)]);
        $this->audit->record('sales.return_registered', $order->company, $user, $return, ['number' => $return->number, 'order' => $order->number]);
        SaleReturnRegistered::dispatch($order, $return);

        return $return;
    }

    /** @return array{string, string|null} Valor histórico e custo histórico devolvidos. */
    private function returnItem(SaleReturn $return, SaleFulfillmentItem $item, string $quantity, User $user, bool $moveStock): array
    {
        $returnedAfter = (string) BigDecimal::of($item->returned_quantity)->plus($quantity);
        $historical = $this->share($item->revenue, $item->quantity, $item->returned_quantity, $returnedAfter);
        $costValue = $item->cost_total === null ? null : $this->share($item->cost_total, $item->quantity, $item->returned_quantity, $returnedAfter);
        $returnItem = $return->items()->create([
            'company_id' => $return->company_id,
            'sale_fulfillment_item_id' => $item->id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $quantity,
            'historical_value' => $historical,
            'cost_value' => $costValue,
        ]);

        if ($moveStock && $item->inventory_movement_id !== null) {
            $movement = $this->inventory->recordEntry(new StockOperation(
                companyId: $return->company_id,
                variantId: (int) $item->product_variant_id,
                quantity: $quantity,
                origin: MovementOrigin::Sale,
                user: $user,
                reason: "Devolução de venda #{$return->number}: {$return->reason}",
                referenceType: 'sale_return',
                referenceId: "{$return->id}:{$returnItem->id}",
                idempotencyKey: "sale-return-item:{$returnItem->id}",
                unitCost: $item->unit_cost === null ? null : (string) BigDecimal::of($item->unit_cost)->toScale(4, RoundingMode::HalfUp),
                entryValue: $costValue,
            ));
            $returnItem->update(['inventory_movement_id' => $movement->id]);
        }

        $item->update(['returned_quantity' => $returnedAfter]);

        return [$historical, $costValue];
    }

    /** Parcela acumulada: devoluções sucessivas nunca somam centavos a mais ou a menos que o valor original. */
    private function share(string $total, string $quantity, string $returnedBefore, string $returnedAfter): string
    {
        $before = BigDecimal::of($returnedBefore)->isZero() ? '0.00' : CommercialMath::cumulativeShare($total, $quantity, '0', $returnedBefore, '0');

        return CommercialMath::cumulativeShare($total, $quantity, $returnedBefore, $returnedAfter, $before);
    }

    private function existing(int $companyId, string $key, string $hash): ?SaleReturn
    {
        $existing = SaleReturn::query()->where('company_id', $companyId)->where('idempotency_key', $key)->first();

        if ($existing !== null && ! hash_equals($existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta requisição já foi usada em outra devolução. Recarregue a página.']);
        }

        return $existing;
    }
}
