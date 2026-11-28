<?php

namespace App\Modules\Finance\Queries;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Enums\TitleStatus;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Services\FinanceLedger;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Visão geral com dados reais. Previsto (títulos em aberto), realizado (baixas efetivas) e vencido são mantidos
 * separados: uma venda só é dinheiro recebido depois da baixa.
 */
class FinanceDashboard
{
    private const OPEN = 'amount - adjusted_amount - settled_principal - cancelled_amount';

    public function __construct(private FinanceLedger $ledger, private CashFlow $cashFlow) {}

    /** @return array<string, mixed> */
    public function for(Company $company, string $from, string $until, ?int $accountId): array
    {
        $today = now($company->timezone)->format('Y-m-d');
        $balances = $this->ledger->balances($company->id);
        $accounts = FinanceAccount::query()->where('company_id', $company->id)->orderBy('name')->get();
        $open = fn (TitleKind $kind) => FinanceTitle::query()->where('company_id', $company->id)->where('kind', $kind)->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial]);
        $sum = fn ($query): string => CommercialMath::money((string) ($query->sum(DB::raw(self::OPEN)) ?: '0'));
        $realized = $this->realized($company, $from, $until, $accountId);

        return [
            'period' => ['from' => $from, 'until' => $until],
            'account_id' => $accountId,
            'available_balance' => CommercialMath::money($accounts->filter(fn (FinanceAccount $account): bool => $account->is_active && ($accountId === null || $account->id === $accountId))
                ->reduce(fn (BigDecimal $carry, FinanceAccount $account): BigDecimal => $carry->plus($balances[$account->id] ?? '0'), BigDecimal::zero())),
            'to_receive' => $sum($open(TitleKind::Receivable)),
            'to_pay' => $sum($open(TitleKind::Payable)),
            'overdue_receivable' => $sum($open(TitleKind::Receivable)->where('due_date', '<', $today)),
            'overdue_payable' => $sum($open(TitleKind::Payable)->where('due_date', '<', $today)),
            'due_soon_receivable' => $sum($open(TitleKind::Receivable)->whereBetween('due_date', [$today, CarbonImmutable::parse($today)->addDays(7)->format('Y-m-d')])),
            'due_soon_payable' => $sum($open(TitleKind::Payable)->whereBetween('due_date', [$today, CarbonImmutable::parse($today)->addDays(7)->format('Y-m-d')])),
            'received' => $realized['income'],
            'paid' => $realized['expense'],
            'result' => CommercialMath::money(BigDecimal::of($realized['income'])->minus($realized['expense'])),
            'monthly' => $this->monthly($company, $until, $accountId),
            'projection' => $this->cashFlow->for($company, $today, CarbonImmutable::parse($today)->addDays(30)->format('Y-m-d'), 'week'),
            'next_due' => FinanceTitle::query()->where('company_id', $company->id)->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial])
                ->orderBy('due_date')->orderBy('id')->limit(8)->get()
                ->map(fn (FinanceTitle $title): array => [
                    'id' => $title->id,
                    'number' => $title->number,
                    'kind' => $title->kind->value,
                    'description' => $title->description,
                    'party_name' => $title->party_name,
                    'due_date' => $title->due_date->format('Y-m-d'),
                    'open' => CommercialMath::money($title->openBalance()),
                    'is_overdue' => $title->isOverdueOn($today),
                ])->values()->all(),
            'expenses_by_category' => $this->byCategory($company, $from, $until, $accountId, TitleKind::Payable),
            'accounts' => $accounts->map(fn (FinanceAccount $account): array => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type->label(), 'is_active' => $account->is_active, 'balance' => $balances[$account->id] ?? '0.00'])->values()->all(),
        ];
    }

    /** @return array{income: string, expense: string} Baixas efetivas (descontados estornos) no período. */
    public function realized(Company $company, string $from, string $until, ?int $accountId): array
    {
        $rows = $this->settlementMovements($company, $from, $until, $accountId)
            ->groupBy('finance_titles.kind')->selectRaw('finance_titles.kind, sum(finance_movements.amount) as total')->pluck('total', 'kind');

        return [
            'income' => CommercialMath::money((string) ($rows[TitleKind::Receivable->value] ?? '0')),
            'expense' => CommercialMath::money(BigDecimal::of((string) ($rows[TitleKind::Payable->value] ?? '0'))->negated()),
        ];
    }

    /** @return list<array{category: string, total: string}> */
    private function byCategory(Company $company, string $from, string $until, ?int $accountId, TitleKind $kind): array
    {
        $rows = $this->settlementMovements($company, $from, $until, $accountId)->where('finance_titles.kind', $kind->value)
            ->leftJoin('finance_categories', 'finance_categories.id', '=', 'finance_movements.finance_category_id')
            ->groupBy('finance_categories.name')->selectRaw('finance_categories.name as category, sum(finance_movements.amount) as total')
            ->orderBy('total')->get()
            ->map(fn (object $row): array => ['category' => (string) ($row->category ?? 'Sem categoria'), 'total' => CommercialMath::money(BigDecimal::of((string) $row->total)->abs())])
            ->filter(fn (array $row): bool => $row['total'] !== '0.00')->values()->all();

        return array_values($rows);
    }

    /** @return list<array{month: string, income: string, expense: string}> */
    private function monthly(Company $company, string $until, ?int $accountId): array
    {
        $end = CarbonImmutable::parse($until)->startOfMonth();
        $start = $end->subMonths(11);
        $rows = $this->settlementMovements($company, $start->format('Y-m-d'), $end->endOfMonth()->format('Y-m-d'), $accountId)
            ->groupByRaw('substr(finance_movements.occurred_at, 1, 7), finance_titles.kind')
            ->selectRaw('substr(finance_movements.occurred_at, 1, 7) as month, finance_titles.kind, sum(finance_movements.amount) as total')->get();
        $months = [];

        for ($month = $start; $month <= $end; $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $income = $rows->first(fn (object $row): bool => $row->month === $key && $row->kind === TitleKind::Receivable->value)->total ?? '0';
            $expense = $rows->first(fn (object $row): bool => $row->month === $key && $row->kind === TitleKind::Payable->value)->total ?? '0';
            $months[] = ['month' => $key, 'income' => CommercialMath::money((string) $income), 'expense' => CommercialMath::money(BigDecimal::of((string) $expense)->negated())];
        }

        return $months;
    }

    private function settlementMovements(Company $company, string $from, string $until, ?int $accountId): Builder
    {
        return DB::table('finance_movements')
            ->join('finance_settlements', 'finance_settlements.id', '=', 'finance_movements.finance_settlement_id')
            ->join('finance_titles', 'finance_titles.id', '=', 'finance_settlements.finance_title_id')
            ->where('finance_movements.company_id', $company->id)->where('finance_movements.is_operational', true)
            ->whereBetween('finance_movements.occurred_at', [$from, $until])
            ->when($accountId, fn (Builder $query, int $id) => $query->where('finance_movements.finance_account_id', $id));
    }
}
