<?php

namespace App\Modules\Finance\Reports;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Documents\DocumentFormat;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Enums\TitleStatus;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Queries\CashFlow;
use App\Modules\Finance\Services\FinanceLedger;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Relatórios gerenciais a partir de dados reais. Todos devolvem a mesma estrutura de tabela, usada pela tela,
 * pelo CSV e pelo PDF. O resultado por categoria é gerencial, não uma demonstração contábil oficial.
 *
 * @phpstan-type Column array{key: string, label: string, align?: 'right'}
 * @phpstan-type Table array{type: string, title: string, subtitle: string, columns: list<Column>, rows: list<array<string, string|null>>, totals: array<string, string>}
 */
class FinanceReports
{
    public const TYPES = [
        'receivables' => 'Contas a receber',
        'payables' => 'Contas a pagar',
        'overdue' => 'Títulos vencidos',
        'settled' => 'Títulos liquidados',
        'delinquency' => 'Inadimplência',
        'income_expense' => 'Receitas e despesas por categoria',
        'statement' => 'Extrato financeiro',
        'cash_flow' => 'Fluxo de caixa',
    ];

    public function __construct(private FinanceLedger $ledger, private CashFlow $cashFlow) {}

    /**
     * @param  array{from: string, until: string, regime?: string, account_id?: int|null, granularity?: string}  $filters
     * @return Table
     */
    public function build(Company $company, string $type, array $filters): array
    {
        $today = now($company->timezone)->format('Y-m-d');
        $period = DocumentFormat::date($filters['from']).' a '.DocumentFormat::date($filters['until']);

        return match ($type) {
            'receivables', 'payables' => $this->titles($company, $type, $filters, $today, $period),
            'overdue' => $this->titles($company, $type, $filters, $today, "Vencidos até {$this->format($today)}"),
            'settled' => $this->titles($company, $type, $filters, $today, $period),
            'delinquency' => $this->delinquency($company, $today),
            'income_expense' => $this->incomeExpense($company, $filters, $period),
            'statement' => $this->statement($company, $filters, $period),
            default => $this->cashFlowTable($company, $filters, $period),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Table
     */
    private function titles(Company $company, string $type, array $filters, string $today, string $subtitle): array
    {
        $query = FinanceTitle::query()->where('company_id', $company->id)->with('category.parent');
        match ($type) {
            'receivables', 'payables' => $query->where('kind', $type === 'receivables' ? TitleKind::Receivable : TitleKind::Payable)
                ->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial])->whereBetween('due_date', [$filters['from'], $filters['until']]),
            'overdue' => $query->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial])->where('due_date', '<', $today),
            default => $query->whereHas('settlements', fn (Builder $settlements) => $settlements->where('status', 'confirmed')->whereBetween('settled_at', [$filters['from'], $filters['until']])),
        };
        $titles = $query->orderBy('due_date')->orderBy('id')->get();
        $totals = ['amount' => BigDecimal::zero(), 'settled' => BigDecimal::zero(), 'open' => BigDecimal::zero()];
        $rows = [];

        foreach ($titles as $title) {
            $totals['amount'] = $totals['amount']->plus($title->effectiveAmount());
            $totals['settled'] = $totals['settled']->plus($title->settled_principal);
            $totals['open'] = $totals['open']->plus($title->status->isOpen() ? $title->openBalance() : BigDecimal::zero());
            $rows[] = [
                'number' => "#{$title->number}",
                'kind' => $title->kind->label(),
                'description' => $title->description,
                'party' => $title->party_name,
                'category' => $title->category?->fullName(),
                'due_date' => $this->format($title->due_date->format('Y-m-d')),
                'status' => $title->isOverdueOn($today) ? 'Vencido' : $title->status->label(),
                'amount' => CommercialMath::money($title->effectiveAmount()),
                'settled' => $title->settled_principal,
                'open' => CommercialMath::money($title->status->isOpen() ? $title->openBalance() : BigDecimal::zero()),
            ];
        }

        return [
            'type' => $type,
            'title' => self::TYPES[$type],
            'subtitle' => $subtitle,
            'columns' => [
                ['key' => 'number', 'label' => 'Título'],
                ['key' => 'kind', 'label' => 'Tipo'],
                ['key' => 'description', 'label' => 'Descrição'],
                ['key' => 'party', 'label' => 'Pessoa'],
                ['key' => 'category', 'label' => 'Categoria'],
                ['key' => 'due_date', 'label' => 'Vencimento'],
                ['key' => 'status', 'label' => 'Situação'],
                ['key' => 'amount', 'label' => 'Valor', 'align' => 'right'],
                ['key' => 'settled', 'label' => 'Liquidado', 'align' => 'right'],
                ['key' => 'open', 'label' => 'Em aberto', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => array_map(fn (BigDecimal $value): string => CommercialMath::money($value), $totals),
        ];
    }

    /** @return Table */
    private function delinquency(Company $company, string $today): array
    {
        $rows = [];
        $total = BigDecimal::zero();
        $groups = FinanceTitle::query()->where('company_id', $company->id)->where('kind', TitleKind::Receivable)->overdue($today)->orderBy('due_date')->get()
            ->groupBy(fn (FinanceTitle $title): string => (string) ($title->party_id ?? 'none:'.($title->party_name ?? '')));

        foreach ($groups as $titles) {
            $open = $titles->reduce(fn (BigDecimal $carry, FinanceTitle $title): BigDecimal => $carry->plus($title->openBalance()), BigDecimal::zero());
            $oldest = $titles->min(fn (FinanceTitle $title): string => $title->due_date->format('Y-m-d'));
            $total = $total->plus($open);
            $rows[] = [
                'party' => $titles->first()->party_name ?? 'Sem pessoa vinculada',
                'titles' => (string) $titles->count(),
                'oldest' => $this->format($oldest),
                'days' => (string) CarbonImmutable::parse($oldest)->diffInDays(CarbonImmutable::parse($today)),
                'open' => CommercialMath::money($open),
            ];
        }

        usort($rows, fn (array $first, array $second): int => BigDecimal::of($second['open'])->compareTo($first['open']));

        return [
            'type' => 'delinquency',
            'title' => self::TYPES['delinquency'],
            'subtitle' => "Contas a receber vencidas até {$this->format($today)}",
            'columns' => [
                ['key' => 'party', 'label' => 'Pessoa'],
                ['key' => 'titles', 'label' => 'Títulos', 'align' => 'right'],
                ['key' => 'oldest', 'label' => 'Vencimento mais antigo'],
                ['key' => 'days', 'label' => 'Dias de atraso', 'align' => 'right'],
                ['key' => 'open', 'label' => 'Em aberto', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => ['open' => CommercialMath::money($total)],
        ];
    }

    /**
     * Regime de caixa: baixas efetivas no período, pela categoria do título. Regime de competência: valor devido
     * dos títulos pela data de competência (descontados ajustes e cancelamentos).
     *
     * @param  array<string, mixed>  $filters
     * @return Table
     */
    private function incomeExpense(Company $company, array $filters, string $period): array
    {
        $isCash = ($filters['regime'] ?? 'cash') === 'cash';
        $rows = $isCash
            ? DB::table('finance_movements')->join('finance_settlements', 'finance_settlements.id', '=', 'finance_movements.finance_settlement_id')
                ->join('finance_titles', 'finance_titles.id', '=', 'finance_settlements.finance_title_id')
                ->leftJoin('finance_categories', 'finance_categories.id', '=', 'finance_titles.finance_category_id')
                ->where('finance_movements.company_id', $company->id)->where('finance_movements.is_operational', true)
                ->whereBetween('finance_movements.occurred_at', [$filters['from'], $filters['until']])
                ->groupBy('finance_titles.kind', 'finance_categories.name')
                ->selectRaw('finance_titles.kind, finance_categories.name as category, sum(finance_movements.amount) as total')->get()
            : DB::table('finance_titles')->leftJoin('finance_categories', 'finance_categories.id', '=', 'finance_titles.finance_category_id')
                ->where('finance_titles.company_id', $company->id)->whereBetween('finance_titles.competence_date', [$filters['from'], $filters['until']])
                ->groupBy('finance_titles.kind', 'finance_categories.name')
                ->selectRaw('finance_titles.kind, finance_categories.name as category, sum(finance_titles.amount - finance_titles.adjusted_amount - finance_titles.cancelled_amount) as total')->get();
        $income = BigDecimal::zero();
        $expense = BigDecimal::zero();
        $lines = [];

        foreach ($rows->sortBy([['kind', 'desc'], ['category', 'asc']]) as $row) {
            $value = BigDecimal::of((string) $row->total)->abs();

            if ($value->isZero()) {
                continue;
            }

            $isIncome = $row->kind === TitleKind::Receivable->value;
            $isIncome ? $income = $income->plus($value) : $expense = $expense->plus($value);
            $lines[] = ['kind' => $isIncome ? 'Receita' : 'Despesa', 'category' => $row->category ?? 'Sem categoria', 'total' => CommercialMath::money($value)];
        }

        return [
            'type' => 'income_expense',
            'title' => self::TYPES['income_expense'],
            'subtitle' => $period.' · '.($isCash ? 'Regime de caixa (baixas efetivas)' : 'Regime de competência (valor devido)').' · Resultado gerencial, sem valor contábil',
            'columns' => [['key' => 'kind', 'label' => 'Tipo'], ['key' => 'category', 'label' => 'Categoria'], ['key' => 'total', 'label' => 'Valor', 'align' => 'right']],
            'rows' => $lines,
            'totals' => ['income' => CommercialMath::money($income), 'expense' => CommercialMath::money($expense), 'result' => CommercialMath::money($income->minus($expense))],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Table
     */
    private function statement(Company $company, array $filters, string $period): array
    {
        $account = FinanceAccount::query()->where('company_id', $company->id)->find((int) ($filters['account_id'] ?? 0))
            ?? FinanceAccount::query()->where('company_id', $company->id)->orderBy('name')->first();

        if ($account === null) {
            return ['type' => 'statement', 'title' => self::TYPES['statement'], 'subtitle' => 'Cadastre uma conta financeira.', 'columns' => [], 'rows' => [], 'totals' => []];
        }

        $before = CarbonImmutable::parse($filters['from'])->subDay()->format('Y-m-d');
        $balance = BigDecimal::of($account->opening_date->format('Y-m-d') <= $before ? $this->ledger->balanceOf($account, $before) : '0');
        $rows = [];

        if ($account->opening_date->format('Y-m-d') >= $filters['from'] && $account->opening_date->format('Y-m-d') <= $filters['until']) {
            $balance = $balance->plus($account->opening_balance);
            $rows[] = ['date' => $this->format($account->opening_date->format('Y-m-d')), 'description' => 'Saldo inicial da conta', 'type' => '—', 'amount' => $account->opening_balance, 'balance' => CommercialMath::money($balance), 'reconciled' => '—'];
        }

        $opening = $rows === [] ? $balance : $balance->minus($account->opening_balance);

        foreach (FinanceMovement::query()->where('finance_account_id', $account->id)->whereBetween('occurred_at', [$filters['from'], $filters['until']])->orderBy('occurred_at')->orderBy('id')->get() as $movement) {
            $balance = $balance->plus($movement->amount);
            $rows[] = [
                'date' => $this->format($movement->occurred_at->format('Y-m-d')),
                'description' => $movement->description,
                'type' => $movement->type->label(),
                'amount' => $movement->amount,
                'balance' => CommercialMath::money($balance),
                'reconciled' => $movement->reconciled_at === null ? 'Não' : 'Sim',
            ];
        }

        return [
            'type' => 'statement',
            'title' => self::TYPES['statement']." — {$account->name}",
            'subtitle' => $period,
            'columns' => [['key' => 'date', 'label' => 'Data'], ['key' => 'description', 'label' => 'Descrição'], ['key' => 'type', 'label' => 'Tipo'], ['key' => 'amount', 'label' => 'Valor', 'align' => 'right'], ['key' => 'balance', 'label' => 'Saldo', 'align' => 'right'], ['key' => 'reconciled', 'label' => 'Conciliado']],
            'rows' => $rows,
            'totals' => ['opening' => CommercialMath::money($opening), 'closing' => CommercialMath::money($balance)],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Table
     */
    private function cashFlowTable(Company $company, array $filters, string $period): array
    {
        $flow = $this->cashFlow->for($company, $filters['from'], $filters['until'], $filters['granularity'] ?? 'month', $filters['account_id'] ?? null);

        return [
            'type' => 'cash_flow',
            'title' => self::TYPES['cash_flow'],
            'subtitle' => "{$period} · Saldo inicial ".DocumentFormat::money($flow['opening']),
            'columns' => [['key' => 'label', 'label' => 'Período'], ['key' => 'realized_in', 'label' => 'Entradas realizadas', 'align' => 'right'], ['key' => 'realized_out', 'label' => 'Saídas realizadas', 'align' => 'right'], ['key' => 'forecast_in', 'label' => 'Entradas previstas', 'align' => 'right'], ['key' => 'forecast_out', 'label' => 'Saídas previstas', 'align' => 'right'], ['key' => 'balance', 'label' => 'Saldo', 'align' => 'right']],
            'rows' => array_map(fn (array $row): array => array_diff_key($row, ['period' => true]), $flow['rows']),
            'totals' => [...$flow['totals'], 'opening' => $flow['opening'], 'closing' => $flow['closing']],
        ];
    }

    private function format(string $date): string
    {
        return DocumentFormat::date($date);
    }
}
