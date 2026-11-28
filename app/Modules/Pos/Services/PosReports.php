<?php

namespace App\Modules\Pos\Services;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Core\Documents\DocumentFormat;
use App\Modules\Pos\Enums\PosSaleStatus;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores e relatórios do caixa a partir de dados reais. Todos devolvem a mesma estrutura de tabela, usada pela
 * tela e pelo CSV. Valores de venda são os da venda comercial; nenhum relatório expõe custo ou margem.
 *
 * @phpstan-type Table array{type: string, title: string, columns: list<array{key: string, label: string, align?: 'right'}>, rows: list<array<string, string|null>>}
 * @phpstan-type Filters array{from: string, until: string, terminal: int|null, operator: int|null}
 */
class PosReports
{
    public const TYPES = [
        'by_operator' => 'Vendas por operador',
        'by_terminal' => 'Vendas por terminal',
        'by_period' => 'Vendas por dia',
        'payment_methods' => 'Formas de pagamento',
        'products' => 'Produtos vendidos',
        'cancellations' => 'Cancelamentos',
        'returns' => 'Devoluções',
        'divergences' => 'Divergências de caixa',
        'sessions' => 'Histórico de sessões',
    ];

    /**
     * @param  Filters  $filters
     * @return array{sales_count: int, total: string, average_ticket: string, received: string, term: string, cancelled_count: int, refunded: string, divergence: string}
     */
    public function summary(Company $company, array $filters): array
    {
        $completed = $this->sales($company, $filters)->where('pos_sales.status', PosSaleStatus::Completed->value);
        $count = (clone $completed)->count();
        $total = (string) ((clone $completed)->sum('pos_sales.total') ?: '0');

        return [
            'sales_count' => $count,
            'total' => CommercialMath::money($total),
            'average_ticket' => $count === 0 ? '0.00' : CommercialMath::money(BigDecimal::of($total)->dividedBy($count, 2, RoundingMode::HalfUp)),
            'received' => CommercialMath::money((string) ((clone $completed)->sum('pos_sales.received_total') ?: '0')),
            'term' => CommercialMath::money((string) ((clone $completed)->sum('pos_sales.term_total') ?: '0')),
            'cancelled_count' => $this->cancelled($company, $filters)->count(),
            'refunded' => CommercialMath::money((string) ($this->refunds($company, $filters)->sum('pos_refunds.amount') ?: '0')),
            'divergence' => CommercialMath::money((string) ($this->sessions($company, $filters)->whereNotNull('closed_at')->sum('divergence') ?: '0')),
        ];
    }

    /**
     * @param  Filters  $filters
     * @return Table
     */
    public function build(Company $company, string $type, array $filters): array
    {
        $completed = fn (): Builder => $this->sales($company, $filters)->where('pos_sales.status', PosSaleStatus::Completed->value);
        $money = fn (mixed $value): string => CommercialMath::money((string) ($value ?? '0'));
        $grouped = fn (string $key, string $label, Builder $query, string $column, string $title): array => [
            'type' => $type,
            'title' => $title,
            'columns' => [['key' => $key, 'label' => $label], ['key' => 'count', 'label' => 'Vendas', 'align' => 'right'], ['key' => 'total', 'label' => 'Total', 'align' => 'right'], ['key' => 'ticket', 'label' => 'Ticket médio', 'align' => 'right']],
            'rows' => $query->groupBy($column)->selectRaw('count(*) as total_count, sum(pos_sales.total) as total_value')->addSelect("{$column} as label")->orderByDesc('total_value')->get()
                ->map(fn (object $row): array => [$key => (string) $row->label, 'count' => (string) $row->total_count, 'total' => $money($row->total_value), 'ticket' => CommercialMath::money(BigDecimal::of((string) $row->total_value)->dividedBy(max(1, (int) $row->total_count), 2, RoundingMode::HalfUp))])->values()->all(),
        ];

        $table = match ($type) {
            'by_operator' => $grouped('operator', 'Operador', $completed(), 'pos_sales.user_name', self::TYPES[$type]),
            'by_terminal' => $grouped('terminal', 'Terminal', $completed()->join('pos_terminals', 'pos_terminals.id', '=', 'pos_sales.pos_terminal_id'), 'pos_terminals.name', self::TYPES[$type]),
            'by_period' => $this->byDay($company, $completed(), $type),
            'payment_methods' => [
                'type' => $type,
                'title' => self::TYPES[$type],
                'columns' => [['key' => 'method', 'label' => 'Forma'], ['key' => 'received', 'label' => 'Recebido (informado)', 'align' => 'right'], ['key' => 'term', 'label' => 'A prazo', 'align' => 'right'], ['key' => 'count', 'label' => 'Lançamentos', 'align' => 'right']],
                'rows' => $completed()->join('pos_payments', 'pos_payments.pos_sale_id', '=', 'pos_sales.id')->groupBy('pos_payments.method')
                    ->selectRaw('pos_payments.method, sum(case when pos_payments.is_received = 1 then pos_payments.amount else 0 end) as received, sum(case when pos_payments.is_received = 0 then pos_payments.amount else 0 end) as term, count(*) as total_count')
                    ->orderBy('pos_payments.method')->get()
                    ->map(fn (object $row): array => ['method' => PaymentMethod::tryFrom((string) $row->method)?->label() ?? (string) $row->method, 'received' => $money($row->received), 'term' => $money($row->term), 'count' => (string) $row->total_count])->values()->all(),
            ],
            'products' => [
                'type' => $type,
                'title' => self::TYPES[$type],
                'columns' => [['key' => 'description', 'label' => 'Item'], ['key' => 'quantity', 'label' => 'Quantidade', 'align' => 'right'], ['key' => 'total', 'label' => 'Valor', 'align' => 'right']],
                'rows' => $completed()->join('sale_order_items', 'sale_order_items.sale_order_id', '=', 'pos_sales.sale_order_id')->groupBy('sale_order_items.description', 'sale_order_items.unit_code')
                    ->selectRaw('sale_order_items.description, sale_order_items.unit_code, sum(sale_order_items.quantity) as quantity, sum(sale_order_items.total) as total_value')
                    ->orderByDesc('total_value')->limit(200)->get()
                    ->map(fn (object $row): array => ['description' => (string) $row->description, 'quantity' => DocumentFormat::quantity((string) $row->quantity).' '.$row->unit_code, 'total' => $money($row->total_value)])->values()->all(),
            ],
            'cancellations' => [
                'type' => $type,
                'title' => self::TYPES[$type],
                'columns' => [['key' => 'date', 'label' => 'Data'], ['key' => 'operator', 'label' => 'Operador'], ['key' => 'reason', 'label' => 'Motivo'], ['key' => 'authorized', 'label' => 'Autorizado por']],
                'rows' => $this->cancelled($company, $filters)->orderBy('pos_sales.cancelled_at')->get(['pos_sales.cancelled_at', 'pos_sales.user_name', 'pos_sales.cancellation_reason', 'pos_sales.authorized_by_name'])
                    ->map(fn (object $row): array => ['date' => $this->dateTime($company, (string) $row->cancelled_at), 'operator' => (string) $row->user_name, 'reason' => $row->cancellation_reason, 'authorized' => $row->authorized_by_name])->values()->all(),
            ],
            'returns' => [
                'type' => $type,
                'title' => self::TYPES[$type],
                'columns' => [['key' => 'date', 'label' => 'Data'], ['key' => 'order', 'label' => 'Venda'], ['key' => 'reason', 'label' => 'Motivo'], ['key' => 'value', 'label' => 'Valor devolvido', 'align' => 'right'], ['key' => 'refund', 'label' => 'Restituído', 'align' => 'right']],
                'rows' => $this->refunds($company, $filters)->join('sale_returns', 'sale_returns.id', '=', 'pos_refunds.sale_return_id')->join('sale_orders', 'sale_orders.id', '=', 'sale_returns.sale_order_id')
                    ->orderBy('pos_refunds.created_at')->get(['pos_refunds.created_at', 'sale_orders.number', 'sale_returns.reason', 'sale_returns.total_value', 'pos_refunds.amount'])
                    ->map(fn (object $row): array => ['date' => $this->dateTime($company, (string) $row->created_at), 'order' => "#{$row->number}", 'reason' => (string) $row->reason, 'value' => $money($row->total_value), 'refund' => $money($row->amount)])->values()->all(),
            ],
            'divergences', 'sessions' => [
                'type' => $type,
                'title' => self::TYPES[$type],
                'columns' => [['key' => 'number', 'label' => 'Sessão'], ['key' => 'terminal', 'label' => 'Terminal'], ['key' => 'operator', 'label' => 'Operador'], ['key' => 'opened', 'label' => 'Abertura'], ['key' => 'closed', 'label' => 'Fechamento'], ['key' => 'expected', 'label' => 'Esperado', 'align' => 'right'], ['key' => 'counted', 'label' => 'Contado', 'align' => 'right'], ['key' => 'divergence', 'label' => 'Divergência', 'align' => 'right'], ['key' => 'notes', 'label' => 'Justificativa']],
                'rows' => $this->sessions($company, $filters)->when($type === 'divergences', fn (Builder $query) => $query->whereNotNull('pos_sessions.divergence')->where('pos_sessions.divergence', '!=', 0))
                    ->join('pos_terminals', 'pos_terminals.id', '=', 'pos_sessions.pos_terminal_id')->orderBy('pos_sessions.opened_at')
                    ->get(['pos_sessions.number', 'pos_terminals.name', 'pos_sessions.user_name', 'pos_sessions.opened_at', 'pos_sessions.closed_at', 'pos_sessions.expected_cash', 'pos_sessions.counted_cash', 'pos_sessions.divergence', 'pos_sessions.closing_notes'])
                    ->map(fn (object $row): array => ['number' => (string) $row->number, 'terminal' => (string) $row->name, 'operator' => (string) $row->user_name, 'opened' => $this->dateTime($company, (string) $row->opened_at), 'closed' => $row->closed_at === null ? 'Aberta' : $this->dateTime($company, (string) $row->closed_at), 'expected' => $row->expected_cash === null ? null : $money($row->expected_cash), 'counted' => $row->counted_cash === null ? null : $money($row->counted_cash), 'divergence' => $row->divergence === null ? null : $money($row->divergence), 'notes' => $row->closing_notes])->values()->all(),
            ],
            default => $this->build($company, 'by_operator', $filters),
        };

        return [...$table, 'rows' => $this->cells($table['rows'])];
    }

    /**
     * Linhas da tabela como texto (ou vazio), no formato da tela e do CSV.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, string|null>>
     */
    private function cells(array $rows): array
    {
        return array_values(array_map(fn (array $row): array => array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $row), $rows));
    }

    /** @return Table */
    private function byDay(Company $company, Builder $query, string $type): array
    {
        $rows = $query->get(['pos_sales.completed_at', 'pos_sales.total'])
            ->groupBy(fn (object $row): string => $this->localDate($company, (string) $row->completed_at))
            ->map(fn ($rows, string $day): array => ['day' => DocumentFormat::date($day), 'count' => (string) $rows->count(), 'total' => CommercialMath::money($rows->reduce(fn (BigDecimal $carry, object $row): BigDecimal => $carry->plus((string) $row->total), BigDecimal::zero())), 'sort' => $day])
            ->sortBy('sort')->values()
            ->map(fn (array $row): array => ['day' => $row['day'], 'count' => $row['count'], 'total' => $row['total'], 'ticket' => CommercialMath::money(BigDecimal::of($row['total'])->dividedBy(max(1, (int) $row['count']), 2, RoundingMode::HalfUp))])->all();

        return ['type' => $type, 'title' => self::TYPES[$type], 'columns' => [['key' => 'day', 'label' => 'Dia'], ['key' => 'count', 'label' => 'Vendas', 'align' => 'right'], ['key' => 'total', 'label' => 'Total', 'align' => 'right'], ['key' => 'ticket', 'label' => 'Ticket médio', 'align' => 'right']], 'rows' => array_values($rows)];
    }

    /** @param Filters $filters */
    private function sales(Company $company, array $filters): Builder
    {
        [$from, $until] = $this->range($company, $filters);

        return DB::table('pos_sales')->where('pos_sales.company_id', $company->id)->whereBetween('pos_sales.completed_at', [$from, $until])
            ->when($filters['terminal'], fn (Builder $query, int $terminal) => $query->where('pos_sales.pos_terminal_id', $terminal))
            ->when($filters['operator'], fn (Builder $query, int $operator) => $query->where('pos_sales.user_id', $operator));
    }

    /** @param Filters $filters */
    private function cancelled(Company $company, array $filters): Builder
    {
        [$from, $until] = $this->range($company, $filters);

        return DB::table('pos_sales')->where('pos_sales.company_id', $company->id)->where('pos_sales.status', PosSaleStatus::Cancelled->value)->whereBetween('pos_sales.cancelled_at', [$from, $until])
            ->when($filters['terminal'], fn (Builder $query, int $terminal) => $query->where('pos_sales.pos_terminal_id', $terminal))
            ->when($filters['operator'], fn (Builder $query, int $operator) => $query->where('pos_sales.user_id', $operator));
    }

    /** @param Filters $filters */
    private function refunds(Company $company, array $filters): Builder
    {
        [$from, $until] = $this->range($company, $filters);

        return DB::table('pos_refunds')->join('pos_sales', 'pos_sales.id', '=', 'pos_refunds.pos_sale_id')->where('pos_refunds.company_id', $company->id)->whereBetween('pos_refunds.created_at', [$from, $until])
            ->when($filters['terminal'], fn (Builder $query, int $terminal) => $query->where('pos_sales.pos_terminal_id', $terminal))
            ->when($filters['operator'], fn (Builder $query, int $operator) => $query->where('pos_refunds.user_id', $operator));
    }

    /** @param Filters $filters */
    private function sessions(Company $company, array $filters): Builder
    {
        [$from, $until] = $this->range($company, $filters);

        return DB::table('pos_sessions')->where('pos_sessions.company_id', $company->id)->whereBetween('pos_sessions.opened_at', [$from, $until])
            ->when($filters['terminal'], fn (Builder $query, int $terminal) => $query->where('pos_sessions.pos_terminal_id', $terminal))
            ->when($filters['operator'], fn (Builder $query, int $operator) => $query->where('pos_sessions.user_id', $operator));
    }

    /**
     * Datas do filtro no fuso da empresa, convertidas para UTC (como os registros são gravados).
     *
     * @param  Filters  $filters
     * @return array{string, string}
     */
    private function range(Company $company, array $filters): array
    {
        return [
            now($company->timezone)->parse($filters['from'], $company->timezone)->startOfDay()->utc()->format('Y-m-d H:i:s'),
            now($company->timezone)->parse($filters['until'], $company->timezone)->endOfDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    private function dateTime(Company $company, string $value): string
    {
        return now()->parse($value, 'UTC')->setTimezone($company->timezone)->format('d/m/Y H:i');
    }

    private function localDate(Company $company, string $value): string
    {
        return now()->parse($value, 'UTC')->setTimezone($company->timezone)->format('Y-m-d');
    }
}
