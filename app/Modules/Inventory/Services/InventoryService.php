<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único ponto de escrita de saldo. A linha da unidade comercializável é o mutex de cada operação;
 * por isso ela é bloqueada antes de qualquer outra leitura. Operações com vários itens devem
 * processá-los em ordem crescente de unidade comercializável para evitar deadlocks.
 */
class InventoryService
{
    public function __construct(private AverageCostCalculator $costs) {}

    private const SCALE = 4;

    private const TRANSACTION_ATTEMPTS = 3;

    private const MAXIMUM_QUANTITY = '99999999999';

    public function balanceOf(int $companyId, int $variantId): string
    {
        return $this->balancesOf($companyId, [$variantId])[$variantId];
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, string>
     */
    public function balancesOf(int $companyId, array $variantIds): array
    {
        $balances = InventoryBalance::query()
            ->where('company_id', $companyId)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->keyBy('product_variant_id');

        $result = [];

        foreach ($variantIds as $variantId) {
            $result[$variantId] = $balances->get($variantId)->quantity ?? '0.0000';
        }

        return $result;
    }

    public function averageCostOf(int $companyId, int $variantId): ?string
    {
        return InventoryBalance::query()->where('company_id', $companyId)->where('product_variant_id', $variantId)->first()?->average_cost;
    }

    public function recordInitial(StockOperation $operation): InventoryMovement
    {
        return $this->record(MovementType::Initial, $operation);
    }

    public function recordEntry(StockOperation $operation): InventoryMovement
    {
        return $this->record(MovementType::Entry, $operation);
    }

    public function recordExit(StockOperation $operation): InventoryMovement
    {
        return $this->record(MovementType::Exit, $operation);
    }

    /**
     * Ajusta o saldo para a quantidade contada; a diferença é calculada sob bloqueio.
     */
    public function adjustTo(StockOperation $operation): InventoryMovement
    {
        return $this->record(MovementType::Adjustment, $operation);
    }

    public function reverse(InventoryMovement $movement, ?User $user, string $reason, ?string $idempotencyKey = null): InventoryMovement
    {
        if ($movement->type === MovementType::Reversal) {
            throw ValidationException::withMessages(['movement' => 'Um estorno não pode ser estornado.']);
        }

        return $this->record(MovementType::Reversal, new StockOperation(
            companyId: $movement->company_id,
            variantId: $movement->product_variant_id,
            quantity: (string) BigDecimal::of($movement->quantity)->abs(),
            origin: $movement->origin,
            user: $user,
            reason: $reason,
            referenceType: $movement->reference_type,
            referenceId: $movement->reference_id,
            idempotencyKey: $idempotencyKey,
            unitCost: $movement->unit_cost,
        ), $movement);
    }

    private function record(MovementType $type, StockOperation $operation, ?InventoryMovement $reversed = null): InventoryMovement
    {
        $quantity = $this->parseQuantity($type, $operation->quantity);
        $requestHash = $this->requestHash($type, $operation, $quantity, $reversed);

        try {
            return DB::transaction(
                fn (): InventoryMovement => $this->recordLocked($type, $operation, $quantity, $requestHash, $reversed),
                self::TRANSACTION_ATTEMPTS,
            );
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->existingMovement($operation, $requestHash, lock: true);

            if ($existing !== null) {
                return $existing;
            }

            if ($reversed !== null) {
                throw $this->alreadyReversed();
            }

            throw $exception;
        }
    }

    private function recordLocked(MovementType $type, StockOperation $operation, BigDecimal $quantity, string $requestHash, ?InventoryMovement $reversed): InventoryMovement
    {
        $variant = ProductVariant::query()
            ->where('company_id', $operation->companyId)
            ->lockForUpdate()
            ->findOrFail($operation->variantId);

        $existing = $this->existingMovement($operation, $requestHash);

        if ($existing !== null) {
            return $existing;
        }

        $this->ensureMovable($type, $variant, $quantity);

        if ($reversed !== null && InventoryMovement::query()->where('reversal_of_id', $reversed->id)->exists()) {
            throw $this->alreadyReversed();
        }

        if ($type === MovementType::Initial && InventoryMovement::query()->where('product_variant_id', $variant->id)->exists()) {
            throw ValidationException::withMessages(['type' => 'O estoque inicial só pode ser informado antes da primeira movimentação.']);
        }

        InventoryBalance::query()->insertOrIgnore([
            'company_id' => $operation->companyId,
            'product_variant_id' => $variant->id,
            'quantity' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $balance = InventoryBalance::query()->where('product_variant_id', $variant->id)->lockForUpdate()->firstOrFail();

        $before = BigDecimal::of($balance->quantity);
        $delta = match ($type) {
            MovementType::Initial, MovementType::Entry => $quantity,
            MovementType::Exit => $quantity->negated(),
            MovementType::Adjustment => $quantity->minus($before),
            MovementType::Reversal => BigDecimal::of($reversed->quantity ?? '0')->negated(),
        };

        if ($delta->isZero()) {
            throw ValidationException::withMessages(['quantity' => 'A quantidade informada não altera o saldo.']);
        }

        $after = $before->plus($delta);

        if ($after->isNegative()) {
            throw ValidationException::withMessages(['quantity' => "Estoque insuficiente. Saldo disponível: {$this->format($before)}."]);
        }

        if ($after->isGreaterThan(self::MAXIMUM_QUANTITY)) {
            throw ValidationException::withMessages(['quantity' => 'O saldo resultante excede o limite permitido.']);
        }

        $movement = InventoryMovement::create([
            'company_id' => $operation->companyId,
            'product_variant_id' => $variant->id,
            'type' => $type,
            'quantity' => (string) $delta->toScale(self::SCALE),
            'balance_before' => (string) $before->toScale(self::SCALE),
            'balance_after' => (string) $after->toScale(self::SCALE),
            'unit_cost' => $operation->unitCost,
            'origin' => $operation->origin,
            'reference_type' => $operation->referenceType,
            'reference_id' => $operation->referenceId,
            'reversal_of_id' => $reversed?->id,
            'user_id' => $operation->user?->id,
            'user_name' => $operation->user?->name,
            'reason' => $operation->reason,
            'idempotency_key' => $operation->idempotencyKey,
            'request_hash' => $requestHash,
            'occurred_at' => now(),
        ]);

        $balance->forceFill([
            'quantity' => (string) $after->toScale(self::SCALE),
            'average_cost' => $this->costs->apply($variant, $movement, $balance->average_cost, $operation->entryValue, $reversed),
        ])->save();

        return $movement;
    }

    private function existingMovement(StockOperation $operation, string $requestHash, bool $lock = false): ?InventoryMovement
    {
        if ($operation->idempotencyKey === null) {
            return null;
        }

        $existing = InventoryMovement::query()
            ->where('company_id', $operation->companyId)
            ->where('idempotency_key', $operation->idempotencyKey)
            ->when($lock, fn ($query) => $query->sharedLock())
            ->first();

        if ($existing !== null && ! hash_equals($existing->request_hash, $requestHash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta chave já foi usada em outra movimentação.']);
        }

        return $existing;
    }

    private function ensureMovable(MovementType $type, ProductVariant $variant, BigDecimal $quantity): void
    {
        $product = $variant->product;

        if (! $product->controlsStock()) {
            throw ValidationException::withMessages(['product_variant_id' => 'Este item não controla estoque.']);
        }

        if ($type === MovementType::Reversal) {
            return;
        }

        if (! $product->is_active || ! $variant->is_active) {
            throw ValidationException::withMessages(['product_variant_id' => 'Itens inativos não podem ser movimentados.']);
        }

        if (! $product->unit->allows_fraction && $quantity->strippedOfTrailingZeros()->getScale() > 0) {
            throw ValidationException::withMessages(['quantity' => "A unidade {$product->unit->code} não aceita quantidade fracionária."]);
        }
    }

    private function parseQuantity(MovementType $type, string $value): BigDecimal
    {
        try {
            $quantity = BigDecimal::of($value)->toScale(self::SCALE);
        } catch (MathException) {
            throw ValidationException::withMessages(['quantity' => 'Informe uma quantidade válida, com até 4 casas decimais.']);
        }

        $isAllowed = $type === MovementType::Adjustment ? ! $quantity->isNegative() : $quantity->isPositive();

        if (! $isAllowed || $quantity->isGreaterThan(self::MAXIMUM_QUANTITY)) {
            throw ValidationException::withMessages(['quantity' => 'Informe uma quantidade válida e maior que zero.']);
        }

        return $quantity;
    }

    private function requestHash(MovementType $type, StockOperation $operation, BigDecimal $quantity, ?InventoryMovement $reversed): string
    {
        return hash('sha256', (string) json_encode([
            $type->value,
            $operation->variantId,
            (string) $quantity,
            $operation->origin->value,
            $operation->referenceType,
            $operation->referenceId,
            $reversed?->id,
            ...($operation->entryValue === null ? [] : [$operation->entryValue]),
        ]));
    }

    private function alreadyReversed(): ValidationException
    {
        return ValidationException::withMessages(['movement' => 'Esta movimentação já foi estornada.']);
    }

    private function format(BigDecimal $value): string
    {
        return str_replace('.', ',', (string) $value->strippedOfTrailingZeros());
    }
}
