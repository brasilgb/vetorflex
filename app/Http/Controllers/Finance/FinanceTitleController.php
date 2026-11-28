<?php

namespace App\Http\Controllers\Finance;

use App\Http\Requests\Finance\FinanceReasonRequest;
use App\Http\Requests\Finance\SaveFinanceTitleRequest;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Enums\TitleStatus;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Services\FinanceTitleService;
use App\Modules\Finance\Support\FinanceCsv;
use App\Modules\Finance\Support\FinancePresenter;
use App\Modules\Fiscal\Services\FiscalDirectory;
use App\Modules\Payments\Services\ChargeDirectory;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contas a receber e a pagar. A permissão depende do tipo do título, por isso é verificada aqui.
 */
class FinanceTitleController extends FinanceController
{
    private const KINDS = ['receivables' => TitleKind::Receivable, 'payables' => TitleKind::Payable];

    public function __construct(private FinanceTitleService $titles, private FinancePresenter $presenter) {}

    public function index(Request $request, string $kind): Response
    {
        $titleKind = self::KINDS[$kind];
        $this->authorizeFinance($request, FinancePermission::viewFor($titleKind));
        $company = $this->company($request);
        $today = $this->today($request);
        $filters = $this->filters($request);
        $query = $this->search($company, $titleKind, $filters, $today);
        $openQuery = (clone $query)->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial]);

        return Inertia::render('finance/titles/index', [
            'kind' => $titleKind->value,
            'titles' => (clone $query)->with('category.parent')->orderBy('due_date')->orderBy('id')->paginate(20)->withQueryString()
                ->through(fn (FinanceTitle $title): array => $this->presenter->titleSummary($title, $today)),
            'totals' => [
                'open' => CommercialMath::money((string) ($openQuery->sum(DB::raw('amount - adjusted_amount - settled_principal - cancelled_amount')) ?: '0')),
                'overdue' => CommercialMath::money((string) ((clone $openQuery)->where('due_date', '<', $today)->sum(DB::raw('amount - adjusted_amount - settled_principal - cancelled_amount')) ?: '0')),
            ],
            'filters' => $filters,
            'categories' => array_values(array_filter($this->categories($company), fn (array $category): bool => $category['kind'] === $titleKind->categoryKind()->value)),
            'accounts' => $this->activeAccounts($company),
            'paymentMethods' => $this->paymentMethods(),
            'today' => $today,
        ]);
    }

    public function store(SaveFinanceTitleRequest $request): RedirectResponse
    {
        $kind = TitleKind::from($request->validated('kind'));
        $this->authorizeFinance($request, FinancePermission::manageFor($kind));
        $titles = $this->titles->create($this->company($request), $request->user(), $request->validated());
        $this->toast($titles->count() > 1 ? "{$titles->count()} parcelas lançadas." : "Título #{$titles->first()?->number} lançado.");

        return $titles->count() === 1 ? to_route('finance.titles.show', $titles->first()) : back();
    }

    public function show(Request $request, FinanceTitle $title): Response
    {
        $this->authorizeFinance($request, FinancePermission::viewFor($title->kind));
        $company = $this->company($request);
        $title->load(['category.parent', 'settlements.account', 'events', 'attachments']);

        return Inertia::render('finance/titles/show', [
            'title' => $this->presenter->title($title, $this->today($request)),
            'related' => $title->group_key === null ? [] : FinanceTitle::query()->where('company_id', $company->id)->where('group_key', $title->group_key)
                ->whereKeyNot($title->id)->orderBy('installment_number')->get()
                ->map(fn (FinanceTitle $related): array => $this->presenter->titleSummary($related, $this->today($request)))->values()->all(),
            'categories' => array_values(array_filter($this->categories($company), fn (array $category): bool => $category['kind'] === $title->kind->categoryKind()->value)),
            'accounts' => $this->activeAccounts($company),
            'paymentMethods' => $this->paymentMethods(),
            'paymentCharges' => $title->kind->value === 'receivable' ? app(ChargeDirectory::class)->forOrigin($company, 'finance_title', $title->id, $this->allows($request, 'payments.view'), $this->allows($request, 'payments.create')) : null,
            'fiscalDocuments' => $title->kind->value === 'receivable' ? app(FiscalDirectory::class)->forOrigin($company, 'finance_title', $title->id, $this->allows($request, 'fiscal.view'), $this->allows($request, 'fiscal.create_draft')) : null,
            'today' => $this->today($request),
            'attachmentRules' => ['mimes' => config('finance.attachments.mimes'), 'maximum_kilobytes' => config('finance.attachments.maximum_kilobytes')],
        ]);
    }

    public function update(SaveFinanceTitleRequest $request, FinanceTitle $title): RedirectResponse
    {
        $this->authorizeFinance($request, FinancePermission::manageFor($title->kind));
        $this->titles->update($title, $request->user(), $request->validated());
        $this->toast("Título #{$title->number} atualizado.");

        return back();
    }

    public function cancel(FinanceReasonRequest $request, FinanceTitle $title): RedirectResponse
    {
        $this->authorizeFinance($request, FinancePermission::CancelTitles);
        $this->titles->cancel($title, $request->user(), $request->validated('reason'));
        $this->toast("Saldo do título #{$title->number} cancelado.");

        return back();
    }

    public function renegotiate(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, FinancePermission::CancelTitles);
        $validated = $request->validate([
            'titles' => ['required', 'array', 'min:1', 'max:50'],
            'titles.*' => ['integer'],
            'schedule' => ['required', 'array', 'min:1', 'max:120'],
            'schedule.*.due_date' => ['required', 'date_format:Y-m-d'],
            'schedule.*.amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:400'],
        ], ['reason.required' => 'Informe o motivo da renegociação.', 'schedule.required' => 'Informe as novas parcelas.']);
        $kinds = FinanceTitle::query()->where('company_id', $this->company($request)->id)->whereKey($validated['titles'])->distinct()->pluck('kind');

        foreach ($kinds as $kind) {
            $this->authorizeFinance($request, FinancePermission::manageFor($kind));
        }

        $created = $this->titles->renegotiate($this->company($request), $request->user(), array_values(array_map('intval', $validated['titles'])), $validated['schedule'], $validated['reason']);
        $this->toast("Renegociação registrada em {$created->count()} parcela(s).");

        return to_route('finance.titles.show', $created->first());
    }

    public function export(Request $request, string $kind, FinanceCsv $csv, AuditService $audit): StreamedResponse
    {
        $titleKind = self::KINDS[$kind];
        $this->authorizeFinance($request, FinancePermission::viewFor($titleKind));
        $company = $this->company($request);
        $today = $this->today($request);
        $query = $this->search($company, $titleKind, $this->filters($request), $today)->with('category.parent')->orderBy('id');
        $audit->record('finance.titles_exported', $company, $request->user(), null, ['kind' => $titleKind->value]);

        return response()->streamDownload(function () use ($query, $csv, $today): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, FinanceCsv::BOM);
            $csv->write($output, ['numero', 'tipo', 'descricao', 'pessoa', 'categoria', 'origem', 'competencia', 'vencimento', 'situacao', 'valor', 'liquidado', 'em_aberto']);

            foreach ($query->lazyById(200) as $title) {
                $row = $this->presenter->titleSummary($title, $today);
                $csv->write($output, [
                    $title->number, $title->kind->label(), $title->description, $title->party_name, $row['category'], $title->origin->label(),
                    $title->competence_date->format('d/m/Y'), $title->due_date->format('d/m/Y'), $row['is_overdue'] ? 'Vencido' : $title->status->label(),
                    FinanceCsv::formatDecimal($row['amount']), FinanceCsv::formatDecimal($row['settled']), FinanceCsv::formatDecimal($row['open']),
                ]);
            }

            fclose($output);
        }, ($titleKind === TitleKind::Receivable ? 'contas-a-receber' : 'contas-a-pagar').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{status: string, from: string|null, until: string|null, term: string|null, category: int|null} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:open,overdue,settled,cancelled,all'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
            'term' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
        ]);

        return [
            'status' => $validated['status'] ?? 'open',
            'from' => $validated['from'] ?? null,
            'until' => $validated['until'] ?? null,
            'term' => $validated['term'] ?? null,
            'category' => isset($validated['category']) ? (int) $validated['category'] : null,
        ];
    }

    /**
     * @param  array{status: string, from: string|null, until: string|null, term: string|null, category: int|null}  $filters
     * @return Builder<FinanceTitle>
     */
    private function search(Company $company, TitleKind $kind, array $filters, string $today): Builder
    {
        return FinanceTitle::query()->where('company_id', $company->id)->where('kind', $kind)
            ->when($filters['status'] === 'open', fn (Builder $query) => $query->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial]))
            ->when($filters['status'] === 'overdue', fn (Builder $query) => $query->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial])->where('due_date', '<', $today))
            ->when($filters['status'] === 'settled', fn (Builder $query) => $query->where('status', TitleStatus::Settled))
            ->when($filters['status'] === 'cancelled', fn (Builder $query) => $query->whereIn('status', [TitleStatus::Cancelled, TitleStatus::Renegotiated]))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('due_date', '>=', $from))
            ->when($filters['until'], fn (Builder $query, string $until) => $query->where('due_date', '<=', $until))
            ->when($filters['category'], fn (Builder $query, int $category) => $query->where(fn (Builder $inner) => $inner->where('finance_category_id', $category)
                ->orWhereIn('finance_category_id', fn ($children) => $children->select('id')->from('finance_categories')->where('parent_id', $category))))
            ->when($filters['term'], fn (Builder $query, string $term) => $query->where(fn (Builder $inner) => $inner->where('description', 'like', "%{$term}%")
                ->orWhere('party_name', 'like', "%{$term}%")->when(ctype_digit(ltrim($term, '#')), fn (Builder $number) => $number->orWhere('number', (int) ltrim($term, '#')))));
    }
}
