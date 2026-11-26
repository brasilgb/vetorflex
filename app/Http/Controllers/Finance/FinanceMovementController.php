<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Core\Services\AuditService;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Support\FinanceCsv;
use App\Modules\Finance\Support\FinancePresenter;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Livro de movimentações, somente leitura. Correções são feitas por estorno, nunca por edição ou exclusão.
 */
class FinanceMovementController extends FinanceController
{
    public function __construct(private FinancePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $filters = $this->filters($request);

        return Inertia::render('finance/movements', [
            'movements' => $this->search($company, $filters)->with(['account', 'category.parent', 'settlement'])->orderByDesc('occurred_at')->orderByDesc('id')
                ->paginate(30)->withQueryString()->through(fn (FinanceMovement $movement): array => $this->presenter->movement($movement)),
            'filters' => $filters,
            'accounts' => FinanceAccount::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name'])->map(fn (FinanceAccount $account): array => ['id' => $account->id, 'name' => $account->name])->values()->all(),
            'types' => MovementType::options(),
        ]);
    }

    public function export(Request $request, FinanceCsv $csv, AuditService $audit): StreamedResponse
    {
        $company = $this->company($request);
        $query = $this->search($company, $this->filters($request))->with(['account', 'category.parent'])->orderBy('id');
        $audit->record('finance.movements_exported', $company, $request->user());

        return response()->streamDownload(function () use ($query, $csv): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, FinanceCsv::BOM);
            $csv->write($output, ['data', 'conta', 'tipo', 'descricao', 'categoria', 'valor', 'resultado', 'responsavel', 'conciliado_em']);

            foreach ($query->lazyById(500) as $movement) {
                $csv->write($output, [
                    $movement->occurred_at->format('d/m/Y'), $movement->account->name, $movement->type->label(), $movement->description, $movement->category?->fullName(),
                    FinanceCsv::formatDecimal($movement->amount), $movement->is_operational ? 'Sim' : 'Não', $movement->user_name, $movement->reconciled_at?->format('d/m/Y H:i'),
                ]);
            }

            fclose($output);
        }, 'movimentacoes-financeiras.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{account: int|null, type: string|null, from: string|null, until: string|null, term: string|null} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'account' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(MovementType::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
            'term' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'account' => isset($validated['account']) ? (int) $validated['account'] : null,
            'type' => $validated['type'] ?? null,
            'from' => $validated['from'] ?? null,
            'until' => $validated['until'] ?? null,
            'term' => $validated['term'] ?? null,
        ];
    }

    /**
     * @param  array{account: int|null, type: string|null, from: string|null, until: string|null, term: string|null}  $filters
     * @return Builder<FinanceMovement>
     */
    private function search(Company $company, array $filters): Builder
    {
        return FinanceMovement::query()->where('company_id', $company->id)
            ->when($filters['account'], fn (Builder $query, int $account) => $query->where('finance_account_id', $account))
            ->when($filters['type'], fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('occurred_at', '>=', $from))
            ->when($filters['until'], fn (Builder $query, string $until) => $query->where('occurred_at', '<=', $until))
            ->when($filters['term'], fn (Builder $query, string $term) => $query->where('description', 'like', "%{$term}%"));
    }
}
