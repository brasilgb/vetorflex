<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Fiscal\Enums\FiscalStatus;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consultas da central de documentos: filtros, indicadores e relatório por período. Sempre da empresa ativa.
 *
 * @phpstan-type Filters array{from: string|null, until: string|null, status: string|null, type: string|null, origin: string|null, search: string|null}
 */
class FiscalQueries
{
    /**
     * @param  Filters  $filters
     * @return Builder<FiscalDocument>
     */
    public function documents(Company $company, array $filters): Builder
    {
        $search = trim((string) $filters['search']);

        return FiscalDocument::query()->where('company_id', $company->id)
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['type'], fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['origin'], fn (Builder $query, string $origin) => $query->where('origin', $origin))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('created_at', '>=', CarbonImmutable::parse($from, $company->timezone)->startOfDay()->utc()))
            ->when($filters['until'], fn (Builder $query, string $until) => $query->where('created_at', '<=', CarbonImmutable::parse($until, $company->timezone)->endOfDay()->utc()))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $digits = preg_replace('/\D+/', '', $search);
                $inner->where('origin_label', 'like', "%{$search}%")->orWhere('receiver->name', 'like', "%{$search}%");

                if ($digits !== '') {
                    $inner->orWhere('number', $digits)->orWhere('local_number', (int) $digits)->orWhere('access_key', 'like', "%{$digits}%")->orWhere('receiver->document', 'like', "%{$digits}%");
                }
            }));
    }

    /** @return array{by_status: array<string, int>, authorized_total: string, authorized_count: int, pending: int, rejected: int} */
    public function indicators(Company $company, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $base = FiscalDocument::query()->where('company_id', $company->id);
        $byStatus = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn (mixed $count): int => (int) $count)->all();
        $authorized = (clone $base)->where('status', FiscalStatus::Authorized)->whereBetween('issued_at', [$from->utc(), $until->utc()]);

        return [
            'by_status' => $byStatus,
            'authorized_total' => CommercialMath::money((string) ((clone $authorized)->sum('total') ?: '0')),
            'authorized_count' => (clone $authorized)->count(),
            'pending' => (clone $base)->whereIn('status', [FiscalStatus::Queued, FiscalStatus::Processing, FiscalStatus::CancelRequested, FiscalStatus::CommunicationFailure])->count(),
            'rejected' => (clone $base)->whereIn('status', [FiscalStatus::Rejected, FiscalStatus::Denied])->count(),
        ];
    }

    /**
     * Totais por tipo e situação no período (data de emissão para autorizados e cancelados).
     *
     * @return list<array{type: string, status: string, count: int, total: string}>
     */
    public function report(Company $company, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return array_values(FiscalDocument::query()->where('company_id', $company->id)
            ->whereBetween('created_at', [$from->utc(), $until->utc()])
            ->selectRaw('type, status, count(*) as documents, sum(total) as amount')->groupBy('type', 'status')->orderBy('type')->orderBy('status')->get()
            ->map(fn ($row): array => ['type' => (string) $row->getAttribute('type')->value, 'status' => (string) $row->getAttribute('status')->value, 'count' => (int) $row->getAttribute('documents'), 'total' => CommercialMath::money((string) ($row->getAttribute('amount') ?? '0'))])
            ->all());
    }
}
