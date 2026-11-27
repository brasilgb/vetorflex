<?php

namespace App\Http\Requests\Pos;

use App\Modules\Core\Commerce\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Carrinho do caixa, para finalizar ou suspender. Totais e troco enviados pela tela são ignorados: o servidor recalcula.
 */
class PosCartRequest extends FormRequest
{
    private const MONEY = ['decimal:0,2', 'min:0', 'max:9999999999999'];

    public const MAXIMUM_ITEMS = 300;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isFinalizing = $this->routeIs('pos.sales.finalize');

        return [
            'customer_party_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'discount' => ['nullable', ...self::MONEY],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAXIMUM_ITEMS],
            'items.*.product_variant_id' => ['nullable', 'integer', 'distinct'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.unit_code' => ['nullable', 'string', 'max:10'],
            'items.*.allows_fraction' => ['nullable', 'boolean'],
            'items.*.is_service' => ['nullable', 'boolean'],
            'items.*.quantity' => ['required', 'decimal:0,4', 'gt:0', 'max:99999999999'],
            'items.*.unit_price' => ['nullable', ...self::MONEY],
            'items.*.discount_percent' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
            'items.*.discount' => ['nullable', ...self::MONEY],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'payments' => [$isFinalizing ? 'present' : 'nullable', 'array', 'list', 'max:10'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'payments.*.is_term' => ['nullable', 'boolean'],
            'payments.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'payments.*.payment_charge_id' => ['nullable', 'integer'],
            'suspended_sale_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:120'],
            'estimated_total' => ['nullable', ...self::MONEY],
            'authorization' => ['nullable', 'array'],
            'authorization.email' => ['nullable', 'string', 'max:255'],
            'authorization.password' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'Inclua ao menos um item.',
            'items.min' => 'Inclua ao menos um item.',
            'items.*.quantity.gt' => 'A quantidade deve ser maior que zero.',
            'items.*.product_variant_id.distinct' => 'O item já está na venda; altere a quantidade.',
            'payments.present' => 'Informe o pagamento.',
            'payments.*.amount.gt' => 'Informe um valor maior que zero.',
            'payments.max' => 'Use no máximo 10 formas de pagamento.',
        ];
    }

    /**
     * Itens no formato do serviço comercial.
     *
     * @return list<array<string, mixed>>
     */
    public function saleItems(): array
    {
        return array_values(array_map(fn (array $item): array => array_filter([
            'product_variant_id' => $item['product_variant_id'] ?? null,
            'description' => $item['description'] ?? null,
            'unit_code' => $item['unit_code'] ?? null,
            'allows_fraction' => $item['allows_fraction'] ?? null,
            'is_service' => $item['is_service'] ?? null,
            'quantity' => (string) $item['quantity'],
            'unit_price' => $item['unit_price'] ?? null,
            'discount_percent' => $item['discount_percent'] ?? null,
            'discount' => $item['discount'] ?? null,
        ], fn (mixed $value): bool => $value !== null), $this->validated('items')));
    }
}
