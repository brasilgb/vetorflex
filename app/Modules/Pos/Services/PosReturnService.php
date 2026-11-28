<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Core\Services\AuditService;
use App\Modules\Pos\Enums\CashMovementType;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Enums\PosSaleStatus;
use App\Modules\Pos\Events\PosRefundIssued;
use App\Modules\Pos\Models\PosCashMovement;
use App\Modules\Pos\Models\PosRefund;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Sales\Enums\FulfillmentStatus;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleFulfillmentItem;
use App\Modules\Sales\Services\SaleReturnService;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Devolução total ou parcial de uma venda do caixa, pelo mecanismo de Vendas (`SaleReturnService`): mesmo documento,
 * custo histórico e reposição opcional ao estoque. A restituição ao cliente é o que já foi recebido acima do novo
 * valor devido (valores a prazo em aberto são reduzidos primeiro); em dinheiro, sai da gaveta do caixa aberto.
 */
class PosReturnService
{
    public function __construct(
        private SaleReturnService $returns,
        private PosSessionService $sessions,
        private PosAuthorizer $authorizer,
        private PosModule $module,
        private AuditService $audit,
    ) {}

    /**
     * Dados: `items` (`sale_order_item_id`, `quantity`), `restock`, `reason`, `refund_method`, `idempotency_key`
     * e `authorization`. Sem itens informados, devolve tudo o que ainda não foi devolvido.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(PosSale $sale, User $operator, ?PosSession $session, array $data): PosRefund
    {
        $company = Company::query()->findOrFail($sale->company_id);

        if ($sale->status !== PosSaleStatus::Completed || $sale->order === null) {
            throw ValidationException::withMessages(['items' => 'Somente vendas concluídas aceitam devolução.']);
        }

        $authorizer = $this->authorizer->authorize($company, $operator, PosPermission::Return, $data['authorization'] ?? null, 'Registrar devolução');
        $method = PaymentMethod::from((string) ($data['refund_method'] ?? PaymentMethod::Cash->value));

        if ($method === PaymentMethod::Cash && $session === null) {
            throw ValidationException::withMessages(['refund_method' => 'Abra um caixa para restituir em dinheiro.']);
        }

        if ($session !== null) {
            $this->sessions->assertOperable($session, $operator);
        }

        return DB::transaction(function () use ($company, $sale, $operator, $session, $data, $authorizer, $method): PosRefund {
            $sale = PosSale::query()->lockForUpdate()->findOrFail($sale->id);
            $order = $sale->order()->firstOrFail();
            $fulfillment = SaleFulfillment::query()->where('sale_order_id', $order->id)->where('status', FulfillmentStatus::Confirmed)->with('items')->firstOrFail();
            $items = $this->items($fulfillment, $data['items'] ?? []);
            $return = $this->returns->register($fulfillment, $operator, [
                'returned_at' => now($company->timezone)->format('Y-m-d'),
                'reason' => $data['reason'],
                'restock' => (bool) ($data['restock'] ?? true),
                'idempotency_key' => "pos:{$data['idempotency_key']}",
                'items' => $items,
            ]);
            $existing = PosRefund::query()->where('sale_return_id', $return->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $returned = BigDecimal::of((string) ($order->returns()->sum('total_value') ?: '0'));
            $dueAfter = BigDecimal::max(BigDecimal::zero(), BigDecimal::of($order->total)->minus($returned));
            $refundable = BigDecimal::of($sale->received_total)->minus($sale->refunded_total)->minus($dueAfter);
            $amount = BigDecimal::max(BigDecimal::zero(), BigDecimal::min($refundable, $return->total_value));
            $refund = PosRefund::create([
                'company_id' => $company->id,
                'pos_sale_id' => $sale->id,
                'sale_return_id' => $return->id,
                'pos_session_id' => $session?->id,
                'method' => $method,
                'amount' => CommercialMath::money($amount),
                'user_id' => $operator->id,
                'user_name' => $operator->name,
                'authorized_by_name' => $authorizer->is($operator) ? null : $authorizer->name,
            ]);
            $sale->update(['refunded_total' => CommercialMath::money(BigDecimal::of($sale->refunded_total)->plus($amount))]);

            if ($amount->isPositive() && $method === PaymentMethod::Cash) {
                PosCashMovement::create([
                    'company_id' => $company->id,
                    'pos_session_id' => $session->id,
                    'type' => CashMovementType::Refund,
                    'amount' => CommercialMath::money($amount),
                    'reason' => "Devolução #{$return->number} da venda #{$order->number}: {$data['reason']}",
                    'user_id' => $operator->id,
                    'user_name' => $operator->name,
                    'authorized_by' => $authorizer->id,
                    'authorized_by_name' => $authorizer->name,
                    'pos_sale_id' => $sale->id,
                    'occurred_at' => now(),
                    'idempotency_key' => "pos-refund:{$refund->id}",
                ]);
            }

            $this->audit->record('pos.refund_issued', $company, $operator, $refund, ['order' => $order->number, 'return' => $return->number, 'amount' => $refund->amount, 'method' => $method->value, 'authorized_by' => $authorizer->name]);

            if ($amount->isPositive()) {
                PosRefundIssued::dispatch($company->id, $refund->id, $order->id, $method->value, $refund->amount, now($company->timezone)->format('Y-m-d'), $operator->id, $this->module->settings($company)->financeAccounts[$method->value] ?? null);
            }

            return $refund;
        });
    }

    /**
     * @param  list<array{sale_order_item_id: int|string, quantity: string}>  $rows
     * @return list<array{sale_fulfillment_item_id: int, quantity: string}>
     */
    private function items(SaleFulfillment $fulfillment, array $rows): array
    {
        $byOrderItem = $fulfillment->items->keyBy('sale_order_item_id');

        if ($rows === []) {
            $items = $fulfillment->items->map(fn (SaleFulfillmentItem $item): array => ['sale_fulfillment_item_id' => $item->id, 'quantity' => (string) BigDecimal::of($item->quantity)->minus($item->returned_quantity)])
                ->filter(fn (array $row): bool => BigDecimal::of($row['quantity'])->isPositive())->values()->all();
            $items = array_values($items);

            return $items !== [] ? $items : throw ValidationException::withMessages(['items' => 'Todos os itens desta venda já foram devolvidos.']);
        }

        return array_map(function (array $row) use ($byOrderItem): array {
            $item = $byOrderItem->get((int) $row['sale_order_item_id']) ?? throw ValidationException::withMessages(['items' => 'Item não pertence a esta venda.']);

            return ['sale_fulfillment_item_id' => $item->id, 'quantity' => (string) $row['quantity']];
        }, $rows);
    }
}
