<?php

namespace App\Http\Requests\Purchases;

use App\Modules\Core\Commerce\PaymentCondition;
use App\Modules\Core\Commerce\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro e edição do pedido. A compra rápida usa as mesmas regras, com recebimento imediato.
 */
class SavePurchaseOrderRequest extends FormRequest
{
    public const MAXIMUM_ITEMS = 200;

    private const MONEY = ['decimal:0,2', 'min:0', 'max:9999999999999'];

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
        $company = $this->attributes->get('company');

        return [
            'supplier_profile_id' => ['required', 'integer', Rule::exists('supplier_profiles', 'id')->where('company_id', $company->id)],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'discount' => ['nullable', ...self::MONEY],
            'freight' => ['nullable', ...self::MONEY],
            'insurance' => ['nullable', ...self::MONEY],
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
            'items.*.product_variant_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'decimal:0,4', 'gt:0', 'max:99999999999'],
            'items.*.unit_cost' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'items.*.discount' => ['nullable', ...self::MONEY],
            'items.*.is_free' => ['sometimes', 'boolean'],
            'idempotency_key' => [$this->isMethod('post') ? 'required' : 'nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'supplier_profile_id.required' => 'Selecione o fornecedor.',
            'supplier_profile_id.exists' => 'O fornecedor não pertence a esta empresa.',
            'order_date.required' => 'Informe a data do pedido.',
            'expected_date.after_or_equal' => 'A previsão de entrega não pode ser anterior ao pedido.',
            'items.required' => 'Inclua ao menos um item.',
            'items.min' => 'Inclua ao menos um item.',
            'items.max' => 'O pedido aceita até '.self::MAXIMUM_ITEMS.' itens.',
            'items.*.product_variant_id.distinct' => 'O item foi incluído mais de uma vez.',
            'items.*.quantity.required' => 'Informe a quantidade.',
            'items.*.quantity.gt' => 'A quantidade deve ser maior que zero.',
            'items.*.quantity.decimal' => 'Informe uma quantidade com até 4 casas decimais.',
            'items.*.unit_cost.decimal' => 'Informe um custo com até 4 casas decimais.',
            'installments.*.amount.gt' => 'O valor do vencimento deve ser maior que zero.',
            '*.decimal' => 'Informe um valor válido.',
        ];
    }
}
