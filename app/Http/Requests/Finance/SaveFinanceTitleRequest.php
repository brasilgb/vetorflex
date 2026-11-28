<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lançamento manual ou edição de título. Valores chegam como texto decimal e são calculados no servidor.
 * Parcelas: quantidade (mensais a partir do vencimento) ou lista explícita de vencimentos e valores.
 */
class SaveFinanceTitleRequest extends FormRequest
{
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
        $isCreating = $this->isMethod('post');
        $money = ['regex:/^\d{1,13}(\.\d{1,2})?$/'];

        return [
            'kind' => [$isCreating ? 'required' : 'prohibited', 'in:receivable,payable'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => [$isCreating ? 'required_without:schedule' : 'sometimes', 'nullable', ...$money],
            'competence_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => [$isCreating ? 'required_without:schedule' : 'sometimes', 'nullable', 'date_format:Y-m-d'],
            'party_id' => ['nullable', 'integer'],
            'finance_category_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'installments' => [$isCreating ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:120'],
            'schedule' => [$isCreating ? 'nullable' : 'prohibited', 'array', 'max:120'],
            'schedule.*.due_date' => ['required', 'date_format:Y-m-d'],
            'schedule.*.amount' => ['required', ...$money],
            'idempotency_key' => [$isCreating ? 'required' : 'prohibited', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'description.required' => 'Informe a descrição.',
            'amount.required_without' => 'Informe o valor.',
            'amount.regex' => 'Informe um valor válido, com até duas casas decimais.',
            'schedule.*.amount.regex' => 'Informe um valor válido, com até duas casas decimais.',
            'due_date.required_without' => 'Informe o vencimento.',
            'competence_date.required' => 'Informe a data de competência.',
        ];
    }
}
