<?php

namespace App\Modules\Finance\Queries;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Enums\TitleStatus;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Fluxo de caixa. Realizado = movimentações efetivas até hoje. Previsto = saldo em aberto dos títulos a partir de
 * hoje; títulos vencidos entram no dia de hoje. Títulos parcialmente liquidados contam só o que falta, e a parte
 * liquidada já está no realizado, sem dupla contagem.
 *
 * No consolidado, transferências entre contas são excluídas de entradas e saídas (somam zero). Filtrado por
 * conta, elas aparecem, e o previsto não é exibido, pois títulos ainda não têm conta definida.
 */
class CashFlow
{
    public const GRANULARITIES = ['day', 'week', 'month'];

    /**
     * @return array{opening: string, closing: string, granularity: string, account_id: int|null, rows: list<array{period: string, label: string, realized_in: string, realized_out: string, forecast_in: string, forecast_out: string, balance: string}>, totals: array<string, string>}
     */
    public function for(Company $company, string $from, string $until, string $granularity, ?int $accountId = null): array
    {
        $today = now($company->timezone)->format('Y-m-d');
        $accounts = FinanceAccount::query()->where('company_id', $company->id)->when($accountId, fn ($query, int $id) => $query->whereKey($id))->get();
        $opening = $accounts->reduce(fn (BigDecimal $carry, FinanceAccount $account): BigDecimal => $carry->plus($account->opening_date->format('Y-m-d') < $from ? $account->opening_balance : '0'), BigDecimal::zero());
        $opening = $opening->plus((string) (FinanceMovement::query()->where('company_id', $company->id)->whereIn('finance_account_id', $accounts->modelKeys())->where('occurred_at', '<', $from)->sum('amount') ?: '0'));
        /** @var array<string, string> $labels */
        $labels = [];
        /** @var array<string, array{realized_in: BigDecimal, realized_out: BigDecimal, forecast_in: BigDecimal, forecast_out: BigDecimal}> $buckets */
        $buckets = [];
        $add = function (string $date, string $field, BigDecimal $value) use (&$buckets, &$labels, $granularity): void {
            [$key, $label] = $this->bucket($date, $granularity);
            $labels[$key] = $label;
            $buckets[$key] ??= ['realized_in' => BigDecimal::zero(), 'realized_out' => BigDecimal::zero(), 'forecast_in' => BigDecimal::zero(), 'forecast_out' => BigDecimal::zero()];
            $buckets[$key][$field] = $buckets[$key][$field]->plus($value);
        };

        foreach ($accounts->filter(fn (FinanceAccount $account): bool => $account->opening_date->format('Y-m-d') >= $from && $account->opening_date->format('Y-m-d') <= $until) as $account) {
            $add($account->opening_date->format('Y-m-d'), BigDecimal::of($account->opening_balance)->isNegative() ? 'realized_out' : 'realized_in', BigDecimal::of($account->opening_balance)->abs());
        }

        $movements = FinanceMovement::query()->where('company_id', $company->id)->whereIn('finance_account_id', $accounts->modelKeys())
            ->whereBetween('occurred_at', [$from, $until])
            ->when($accountId === null, fn ($query) => $query->where('type', '!=', MovementType::Transfer)->whereNull('finance_transfer_id'))
            ->selectRaw('occurred_at, sum(case when amount > 0 then amount else 0 end) as entries, sum(case when amount < 0 then amount else 0 end) as exits')
            ->groupBy('occurred_at')->toBase()->get();

        foreach ($movements as $row) {
            $date = substr((string) $row->occurred_at, 0, 10);
            $add($date, 'realized_in', BigDecimal::of((string) $row->entries));
            $add($date, 'realized_out', BigDecimal::of((string) $row->exits)->negated());
        }

        if ($accountId === null && $until >= $today) {
            $titles = FinanceTitle::query()->where('company_id', $company->id)->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial])
                ->where('due_date', '<=', $until)
                ->selectRaw('kind, due_date, sum(amount - adjusted_amount - settled_principal - cancelled_amount) as open')
                ->groupBy('kind', 'due_date')->toBase()->get();

            foreach ($titles as $row) {
                $due = substr((string) $row->due_date, 0, 10);
                $date = max($due, $today, $from);
                $add($date, $row->kind === TitleKind::Receivable->value ? 'forecast_in' : 'forecast_out', BigDecimal::of((string) $row->open));
            }
        }

        ksort($buckets);
        $balance = $opening;
        $totals = ['realized_in' => BigDecimal::zero(), 'realized_out' => BigDecimal::zero(), 'forecast_in' => BigDecimal::zero(), 'forecast_out' => BigDecimal::zero()];
        $rows = [];

        foreach ($buckets as $period => $bucket) {
            $balance = $balance->plus($bucket['realized_in'])->minus($bucket['realized_out'])->plus($bucket['forecast_in'])->minus($bucket['forecast_out']);

            foreach (array_keys($totals) as $field) {
                $totals[$field] = $totals[$field]->plus($bucket[$field]);
            }

            $rows[] = [
                'period' => (string) $period,
                'label' => $labels[$period],
                'realized_in' => CommercialMath::money($bucket['realized_in']),
                'realized_out' => CommercialMath::money($bucket['realized_out']),
                'forecast_in' => CommercialMath::money($bucket['forecast_in']),
                'forecast_out' => CommercialMath::money($bucket['forecast_out']),
                'balance' => CommercialMath::money($balance),
            ];
        }

        return [
            'opening' => CommercialMath::money($opening),
            'closing' => CommercialMath::money($balance),
            'granularity' => $granularity,
            'account_id' => $accountId,
            'rows' => $rows,
            'totals' => array_map(fn (BigDecimal $value): string => CommercialMath::money($value), $totals),
        ];
    }

    /** @return array{string, string} Chave ordenável e rótulo do período. */
    private function bucket(string $date, string $granularity): array
    {
        $day = CarbonImmutable::parse($date);

        return match ($granularity) {
            'week' => [$day->startOfWeek()->format('Y-m-d'), 'Semana de '.$day->startOfWeek()->format('d/m')],
            'month' => [$day->format('Y-m'), $day->format('m/Y')],
            default => [$day->format('Y-m-d'), $day->format('d/m/Y')],
        };
    }
}
