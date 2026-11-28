<?php

namespace App\Http\Requests\Sales;

use App\Modules\Core\Commerce\PaymentCondition;
use App\Modules\Core\Commerce\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Regras comuns a orçamentos e pedidos. Totais não são aceitos: o servidor os calcula.
 */
abstract class SaleDocumentRequest extends FormRequest
{
    public const MAXIMUM_ITEMS = 200;

    protected const MONEY = ['decimal:0,2', 'min:0', 'max:9999999999999'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    protected function commonRules(): array
    {
        return [
            'customer_party_id' => ['nullable', 'integer'],
            'customer_contact' => ['nullable', 'string', 'max:255'],
            'seller_id' => ['nullable', 'integer'],
            'commercial_terms' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'discount' => ['nullable', ...self::MONEY],
            'freight' => ['nullable', ...self::MONEY],
            'other_costs' => ['nullable', ...self::MONEY],
            'payment_condition' => ['nullable', Rule::enum(PaymentCondition::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'payment_notes' => ['nullable', 'string', 'max:500'],
            'installments' => ['sometimes', 'array', 'list', 'max:60'],
            'installments.*.due_date' => ['required', 'date_format:Y-m-d'],
            'installments.*.amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'installments.*.method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAXIMUM_ITEMS],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'distinct'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.unit_code' => ['nullable', 'string', 'max:10'],
            'items.*.allows_fraction' => ['sometimes', 'boolean'],
            'items.*.is_service' => ['sometimes', 'boolean'],
            'items.*.quantity' => ['required', 'decimal:0,4', 'gt:0', 'max:99999999999'],
            'items.*.unit_price' => ['nullable', ...self::MONEY],
            'items.*.discount_percent' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
            'items.*.discount' => ['nullable', ...self::MONEY],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => [$this->isMethod('post') ? 'required' : 'nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'Inclua ao menos um item.',
            'items.min' => 'Inclua ao menos um item.',
            'items.max' => 'O documento aceita até '.self::MAXIMUM_ITEMS.' itens.',
            'items.*.product_variant_id.distinct' => 'O item foi incluído mais de uma vez.',
            'items.*.quantity.required' => 'Informe a quantidade.',
            'items.*.quantity.gt' => 'A quantidade deve ser maior que zero.',
            'items.*.quantity.decimal' => 'Informe uma quantidade com até 4 casas decimais.',
            'items.*.discount_percent.max' => 'O desconto não pode superar 100%.',
            'installments.*.amount.gt' => 'O valor do vencimento deve ser maior que zero.',
            '*.decimal' => 'Informe um valor válido.',
        ];
    }
}
