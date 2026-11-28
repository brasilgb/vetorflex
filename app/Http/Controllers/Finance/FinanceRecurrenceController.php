<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Finance\Enums\Frequency;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\FinanceRecurrence;
use App\Modules\Finance\Services\FinanceRecurrenceService;
use App\Modules\Finance\Support\FinancePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Receitas e despesas recorrentes. Os títulos são gerados por comando agendado, sem baixa automática.
 */
class FinanceRecurrenceController extends FinanceController
{
    public function __construct(private FinanceRecurrenceService $recurrences, private FinancePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);

        return Inertia::render('finance/recurrences', [
            'recurrences' => FinanceRecurrence::query()->where('company_id', $company->id)->with('category.parent')->withCount('titles')
                ->orderByRaw('ended_at is not null')->orderBy('description')->get()
                ->map(fn (FinanceRecurrence $recurrence): array => $this->presenter->recurrence($recurrence))->values()->all(),
            'frequencies' => Frequency::options(),
            'categories' => $this->categories($company),
            'today' => $this->today($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(TitleKind::class)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'frequency' => ['required', Rule::enum(Frequency::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'party_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('company_id', $company->id)],
            'finance_category_id' => ['nullable', 'integer'],
        ], ['description.required' => 'Informe a descrição.', 'amount.required' => 'Informe o valor.', 'amount.regex' => 'Informe um valor válido.', 'end_date.after_or_equal' => 'A data final deve ser igual ou posterior à inicial.']);
        $this->authorizeFinance($request, FinancePermission::manageFor(TitleKind::from($validated['kind'])));

        if (! empty($validated['finance_category_id'])) {
            $category = FinanceCategory::query()->where('company_id', $company->id)->find((int) $validated['finance_category_id']);

            if ($category === null || $category->kind !== TitleKind::from($validated['kind'])->categoryKind()) {
                throw ValidationException::withMessages(['finance_category_id' => 'Selecione uma categoria do mesmo tipo desta empresa.']);
            }
        }

        $this->recurrences->create($company, $request->user(), $validated);
        $this->toast('Recorrência cadastrada. Os próximos títulos já foram gerados.');

        return back();
    }

    public function end(Request $request, FinanceRecurrence $recurrence): RedirectResponse
    {
        $this->recurrences->end($recurrence, $request->user());
        $this->toast('Recorrência encerrada. Títulos já gerados continuam em aberto.');

        return back();
    }
}
