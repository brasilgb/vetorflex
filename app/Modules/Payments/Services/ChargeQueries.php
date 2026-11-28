<?php

namespace App\Modules\Payments\Services;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Enums\WebhookStatus;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Consultas e indicadores de Pagamentos, somente com dados reais. "Recebido" soma pagamentos confirmados pelo
 * provedor no período (pela data do pagamento), líquido de devoluções confirmadas; taxas e valor líquido aparecem
 * apenas quando o provedor os informa.
 *
 * @phpstan-type Filters array{from?: string|null, until?: string|null, status?: string|null, provider?: string|null, origin?: string|null, search?: string|null}
 */
class ChargeQueries
{
    /**
     * @param  Filters  $filters
     * @return Builder<PaymentCharge>
     */
    public function charges(Company $company, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        [$from, $until] = $this->range($company, $filters);

        return PaymentCharge::query()->where('company_id', $company->id)
            ->when($filters['from'] ?? null, fn (Builder $query) => $query->whereBetween('created_at', [$from, $until]))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['provider'] ?? null, fn (Builder $query, string $provider) => $query->where('provider', $provider))
            ->when($filters['origin'] ?? null, fn (Builder $query, string $origin) => $query->where('origin', $origin))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('description', 'like', "%{$search}%")->orWhere('payer_name', 'like', "%{$search}%")->orWhere('reference', $search)
                ->orWhere('number', ctype_digit($search) ? (int) $search : -1)->orWhere('provider_charge_id', Str::upper($search))));
    }

    /**
     * @param  Filters  $filters
     * @return array<string, mixed>
     */
    public function dashboard(Company $company, array $filters): array
    {
        $charges = $this->charges($company, $filters);
        [$from, $until] = $this->range($company, $filters);
        $transactions = PaymentTransaction::query()->where('payment_transactions.company_id', $company->id)->whereBetween('payment_transactions.paid_at', [$from, $until])
            ->join('payment_charges', 'payment_charges.id', '=', 'payment_transactions.payment_charge_id')
            ->when($filters['provider'] ?? null, fn ($query, string $provider) => $query->where('payment_charges.provider', $provider))
            ->when($filters['origin'] ?? null, fn ($query, string $origin) => $query->where('payment_charges.origin', $origin));
        $gross = BigDecimal::of((string) ((clone $transactions)->sum('payment_transactions.amount') ?: '0'));
        $refunded = BigDecimal::of((string) ((clone $transactions)->sum('payment_transactions.refunded_amount') ?: '0'));
        $fees = (clone $transactions)->whereNotNull('payment_transactions.fee_amount')->sum('payment_transactions.fee_amount');
        $issued = (clone $charges)->whereNotIn('status', [ChargeStatus::Draft->value])->count();
        $paid = (clone $charges)->whereIn('status', [ChargeStatus::Paid->value, ChargeStatus::Refunded->value])->count();
        $open = PaymentCharge::query()->where('company_id', $company->id)->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Processing->value, ChargeStatus::PartiallyPaid->value]);
        $overdue = PaymentCharge::query()->where('company_id', $company->id)->where('status', ChargeStatus::Overdue->value);

        return [
            'received' => CommercialMath::money($gross->minus($refunded)),
            'gross' => CommercialMath::money($gross),
            'refunded' => CommercialMath::money($refunded),
            'fees' => $fees ? CommercialMath::money((string) $fees) : null,
            'net' => $fees ? CommercialMath::money($gross->minus($refunded)->minus((string) $fees)) : null,
            'pending' => $this->openAmount($open),
            'overdue' => $this->openAmount($overdue),
            'issued' => $issued,
            'paid' => $paid,
            'collection_rate' => $issued === 0 ? null : (string) BigDecimal::of($paid)->multipliedBy(100)->dividedBy($issued, 1, RoundingMode::HalfUp),
            'by_method' => $this->groupedReceived(clone $transactions, 'payment_charges.method'),
            'by_provider' => $this->groupedReceived(clone $transactions, 'payment_charges.provider'),
            'by_origin' => $this->groupedReceived(clone $transactions, 'payment_charges.origin'),
            'failures' => PaymentWebhookEvent::query()->where('company_id', $company->id)->where('status', WebhookStatus::Failed)->count()
                + PaymentCharge::query()->where('company_id', $company->id)->where('status', ChargeStatus::Failed)->whereBetween('created_at', [$from, $until])->count(),
        ];
    }

    /** @param Builder<PaymentCharge> $query */
    private function openAmount(Builder $query): string
    {
        $amount = (string) ((clone $query)->sum('amount') ?: '0');
        $paid = (string) ($query->sum('paid_amount') ?: '0');

        return CommercialMath::money(BigDecimal::of($amount)->minus($paid));
    }

    /** @return list<array{key: string, total: string, count: int}> */
    private function groupedReceived(mixed $query, string $column): array
    {
        return array_values($query->groupBy($column)->selectRaw("{$column} as group_key, sum(payment_transactions.amount) - sum(payment_transactions.refunded_amount) as total, count(*) as total_count")
            ->toBase()->get()->map(fn (object $row): array => ['key' => (string) (data_get($row, 'group_key') ?? '—'), 'total' => CommercialMath::money((string) data_get($row, 'total', '0')), 'count' => (int) data_get($row, 'total_count', 0)])->all());
    }

    /**
     * @param  Filters  $filters
     * @return array{string, string}
     */
    private function range(Company $company, array $filters): array
    {
        $now = now($company->timezone);

        return [
            now($company->timezone)->parse($filters['from'] ?? $now->copy()->startOfMonth()->format('Y-m-d'), $company->timezone)->startOfDay()->utc()->format('Y-m-d H:i:s'),
            now($company->timezone)->parse($filters['until'] ?? $now->format('Y-m-d'), $company->timezone)->endOfDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }
}
