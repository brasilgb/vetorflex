<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Commerce\PaymentCondition;
use App\Modules\Core\Commerce\PaymentSchedule;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductCatalog;
use App\Modules\Sales\Data\SaleAbilities;
use App\Modules\Sales\Enums\DeliveryMethod;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleOrderItem;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Sales\Models\SaleQuoteItem;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMembership;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Montagem comum de orçamentos e pedidos: cliente, responsável, itens, preços, descontos, totais e previsão de pagamento.
 * Todos os valores são calculados aqui; totais enviados pela interface são ignorados.
 *
 * Sem permissão para alterar preços, o preço é o do catálogo (ou o já gravado no item). Sem permissão para descontos,
 * os descontos enviados são descartados e os já gravados são preservados.
 */
class SaleDocumentBuilder
{
    public function __construct(
        private ProductCatalog $catalog,
        private SaleCustomers $customers,
    ) {}

    /** @param array<string, mixed> $data */
    public function fill(Company $company, SaleQuote|SaleOrder $document, User $user, array $data, SaleAbilities $abilities): void
    {
        $isOrder = $document instanceof SaleOrder;
        $this->fillCustomer($company, $document, $data, $isOrder);
        $this->fillSeller($company, $document, $user, $data);
        $date = $isOrder ? 'order_date' : 'issue_date';

        $document->fill([
            ...Arr::only($data, $isOrder
                ? ['order_date', 'expected_date', 'external_reference', 'commercial_terms', 'notes', 'internal_notes', 'payment_notes']
                : ['issue_date', 'valid_until', 'commercial_terms', 'notes', 'payment_notes']),
            'freight' => CommercialMath::money($data['freight'] ?? '0'),
            'other_costs' => CommercialMath::money($data['other_costs'] ?? '0'),
            'payment_condition' => $data['payment_condition'] ?? PaymentCondition::Undefined->value,
            'payment_method' => $data['payment_method'] ?? null,
        ]);

        if ($abilities->canApplyDiscounts) {
            $document->discount = CommercialMath::money($data['discount'] ?? '0');
        }

        $document->save();
        $items = $this->replaceItems($company, $document, $data['items'], $abilities);
        $itemsTotal = array_reduce($items, fn (BigDecimal $carry, SaleQuoteItem|SaleOrderItem $item): BigDecimal => $carry->plus($item->total), BigDecimal::zero());

        if ($itemsTotal->isLessThan($document->discount)) {
            throw ValidationException::withMessages(['discount' => 'O desconto geral não pode superar o total dos itens.']);
        }

        $total = $itemsTotal->minus($document->discount)->plus($document->freight)->plus($document->other_costs);
        $document->update(['items_total' => CommercialMath::money($itemsTotal), 'total' => CommercialMath::money($total)]);

        $schedule = PaymentSchedule::build($document->payment_condition, $document->payment_method?->value, $data['installments'] ?? [], $document->total, (string) $data[$date]);
        $document->paymentSchedules()->delete();
        $document->paymentSchedules()->createMany(array_map(fn (array $row): array => [...$row, 'company_id' => $company->id], $schedule));
    }

    /**
     * Responsáveis possíveis: usuários com vínculo ativo na empresa.
     *
     * @return Builder<User>
     */
    public function sellers(Company $company): Builder
    {
        return User::query()->whereIn('id', CompanyMembership::query()->where('company_id', $company->id)->where('is_active', true)->select('user_id'))->orderBy('name')->orderBy('id');
    }

    public function allowsAnonymous(Company $company): bool
    {
        return (bool) ($company->settings['sales']['allow_anonymous_customer'] ?? config('sales.allow_anonymous_customer'));
    }

    /** @param array<string, mixed> $data */
    private function fillCustomer(Company $company, SaleQuote|SaleOrder $document, array $data, bool $isOrder): void
    {
        $partyId = isset($data['customer_party_id']) ? (int) $data['customer_party_id'] : null;
        $method = $isOrder ? DeliveryMethod::from($data['delivery_method'] ?? DeliveryMethod::Pickup->value) : null;

        if ($partyId === null) {
            if (! $this->allowsAnonymous($company)) {
                throw ValidationException::withMessages(['customer_party_id' => 'Selecione o cliente.']);
            }

            $document->fill(['customer_party_id' => null, 'customer_snapshot' => null]);
        } else {
            $party = $this->customers->resolve($company, $partyId, $document->customer_party_id);
            $document->fill(['customer_party_id' => $party->id, 'customer_snapshot' => $this->customers->snapshot($party, $data['customer_contact'] ?? null)]);
        }

        if (! $isOrder) {
            return;
        }

        $address = null;

        if (isset($data['delivery_address_id']) && isset($party)) {
            $selected = $party->addresses->firstWhere('id', (int) $data['delivery_address_id']);

            if ($selected === null) {
                throw ValidationException::withMessages(['delivery_address_id' => 'O endereço não pertence ao cliente.']);
            }

            $address = ['party_address_id' => $selected->id, 'text' => SaleCustomers::addressText($selected)];
        } elseif (filled($data['delivery_address_text'] ?? null)) {
            $address = ['party_address_id' => null, 'text' => trim((string) $data['delivery_address_text'])];
        }

        if ($method === DeliveryMethod::Delivery && $address === null) {
            throw ValidationException::withMessages(['delivery_address_id' => 'Informe o endereço de entrega.']);
        }

        $document->fill(['delivery_method' => $method, 'delivery_address' => $address]);
    }

    /** @param array<string, mixed> $data */
    private function fillSeller(Company $company, SaleQuote|SaleOrder $document, User $user, array $data): void
    {
        $sellerId = isset($data['seller_id']) ? (int) $data['seller_id'] : ($document->seller_id ?? $user->id);
        $seller = $sellerId === $user->id ? $user : $this->sellers($company)->whereKey($sellerId)->first();

        if ($seller === null) {
            throw ValidationException::withMessages(['seller_id' => 'O responsável precisa ser um usuário ativo da empresa.']);
        }

        $document->fill(['seller_id' => $seller->id, 'seller_name' => $seller->name]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<SaleQuoteItem|SaleOrderItem>
     */
    private function replaceItems(Company $company, SaleQuote|SaleOrder $document, array $rows, SaleAbilities $abilities): array
    {
        $previous = $document->items()->get()->keyBy('id');
        $document->items()->delete();
        $variantIds = array_values(array_filter(array_map(fn (array $row): ?int => isset($row['product_variant_id']) ? (int) $row['product_variant_id'] : null, $rows)));
        $variants = $this->catalog->sellableUnits($company)->whereKey($variantIds)->get()->keyBy('id');
        $items = [];

        foreach ($rows as $position => $row) {
            /** @var SaleQuoteItem|SaleOrderItem|null $kept */
            $kept = $previous->get((int) ($row['id'] ?? 0));
            $line = isset($row['product_variant_id'])
                ? $this->catalogLine($variants->get((int) $row['product_variant_id']), $kept, $row, $position, $abilities)
                : $this->customLine($row, $position, $abilities);

            if (! $line['allows_fraction'] && CommercialMath::hasFraction((string) $row['quantity'])) {
                throw ValidationException::withMessages(["items.{$position}.quantity" => "A unidade {$line['unit_code']} não aceita quantidade fracionária."]);
            }

            $quantity = (string) BigDecimal::of($row['quantity'])->toScale(4);
            $discount = $this->discount($row, $kept, $line['product_variant_id'], $quantity, $line['unit_price'], $position, $abilities);

            $items[] = $document->items()->create([
                ...$line,
                ...$discount,
                'company_id' => $company->id,
                'position' => $position + 1,
                'quantity' => $quantity,
                'notes' => $row['notes'] ?? null,
                'total' => CommercialMath::itemTotal($quantity, $line['unit_price'], $discount['discount'], $position),
            ]);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function catalogLine(?ProductVariant $variant, SaleQuoteItem|SaleOrderItem|null $kept, array $row, int $position, SaleAbilities $abilities): array
    {
        if ($variant === null) {
            throw ValidationException::withMessages(["items.{$position}.product_variant_id" => 'Item inativo ou inexistente.']);
        }

        $catalogPrice = $this->catalog->pricesOf($variant)['effective'];
        $isKept = $kept !== null && $kept->product_variant_id === $variant->id;
        $price = match (true) {
            $abilities->canChangePrices && filled($row['unit_price'] ?? null) => (string) $row['unit_price'],
            ! $abilities->canChangePrices && $isKept => $kept->unit_price,
            default => $catalogPrice,
        };

        if ($price === null) {
            throw ValidationException::withMessages(["items.{$position}.unit_price" => "\"{$variant->displayName()}\" não tem preço de venda cadastrado."]);
        }

        $product = $variant->product;

        return [
            'product_variant_id' => $variant->id,
            'description' => $variant->displayName(),
            'sku' => $variant->sku,
            'unit_code' => $product->unit->code,
            'allows_fraction' => $product->unit->allows_fraction,
            'is_service' => $product->type === ProductType::Service,
            'is_custom' => false,
            'controls_stock' => $product->controlsStock(),
            'catalog_price' => $catalogPrice,
            'unit_price' => CommercialMath::money($price),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function customLine(array $row, int $position, SaleAbilities $abilities): array
    {
        if (! $abilities->canUseCustomItems) {
            throw ValidationException::withMessages(["items.{$position}.product_variant_id" => 'Você não pode incluir itens avulsos. Selecione um item do catálogo.']);
        }

        if (blank($row['description'] ?? null) || blank($row['unit_price'] ?? null)) {
            throw ValidationException::withMessages(["items.{$position}.description" => 'Informe a descrição e o preço do item avulso.']);
        }

        return [
            'product_variant_id' => null,
            'description' => trim((string) $row['description']),
            'sku' => null,
            'unit_code' => mb_strtoupper(trim((string) ($row['unit_code'] ?? '')) ?: 'UN'),
            'allows_fraction' => (bool) ($row['allows_fraction'] ?? false),
            'is_service' => (bool) ($row['is_service'] ?? false),
            'is_custom' => true,
            'controls_stock' => false,
            'catalog_price' => null,
            'unit_price' => CommercialMath::money((string) $row['unit_price']),
        ];
    }

    /**
     * Desconto percentual ou monetário. O percentual é convertido em valor sobre o bruto do item.
     *
     * @param  array<string, mixed>  $row
     * @return array{discount_percent: string|null, discount: string}
     */
    private function discount(array $row, SaleQuoteItem|SaleOrderItem|null $kept, ?int $variantId, string $quantity, string $unitPrice, int $position, SaleAbilities $abilities): array
    {
        if (! $abilities->canApplyDiscounts) {
            if ($kept === null || $kept->product_variant_id !== $variantId) {
                return ['discount_percent' => null, 'discount' => '0.00'];
            }

            $percent = $kept->discount_percent;
            $value = $kept->discount;
        } else {
            $percent = filled($row['discount_percent'] ?? null) ? (string) $row['discount_percent'] : null;
            $value = (string) ($row['discount'] ?? '0');
        }

        if ($percent !== null && BigDecimal::of($percent)->isGreaterThan(100)) {
            throw ValidationException::withMessages(["items.{$position}.discount_percent" => 'O desconto não pode superar 100%.']);
        }

        $gross = BigDecimal::of($quantity)->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
        $discount = $percent === null || BigDecimal::of($percent)->isZero()
            ? CommercialMath::money($value)
            : CommercialMath::money($gross->multipliedBy($percent)->dividedBy(100, 2, RoundingMode::HalfUp));

        return ['discount_percent' => $percent === null || BigDecimal::of($percent)->isZero() ? null : CommercialMath::money($percent), 'discount' => $discount];
    }
}
