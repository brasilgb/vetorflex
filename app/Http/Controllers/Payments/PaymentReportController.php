<?php

namespace App\Http\Controllers\Payments;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Support\CsvFormat;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\ChargeQueries;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatórios de recebimentos confirmados pelo provedor: por dia, forma, provedor e origem, com exportação CSV das
 * transações. Valores brutos, devolvidos, taxas e líquidos aparecem somente quando registrados.
 */
class PaymentReportController extends PaymentsController
{
    public function index(Request $request, ChargeQueries $queries): Response
    {
        $company = $this->company($request);
        $filters = $this->filters($request);
        $days = $this->transactions($request)->get(['payment_transactions.paid_at', 'payment_transactions.amount', 'payment_transactions.refunded_amount'])
            ->groupBy(fn (PaymentTransaction $transaction): string => $transaction->paid_at->setTimezone($company->timezone)->format('Y-m-d'))
            ->map(fn ($rows, string $day): array => [
                'day' => $day,
                'count' => $rows->count(),
                'gross' => CommercialMath::money($rows->reduce(fn (BigDecimal $carry, PaymentTransaction $row): BigDecimal => $carry->plus($row->amount), BigDecimal::zero())),
                'refunded' => CommercialMath::money($rows->reduce(fn (BigDecimal $carry, PaymentTransaction $row): BigDecimal => $carry->plus($row->refunded_amount), BigDecimal::zero())),
            ])->sortKeys()->values()->all();

        return Inertia::render('payments/reports', [
            'filters' => $filters,
            'summary' => $queries->dashboard($company, [...$filters, 'provider' => null, 'origin' => null]),
            'days' => $days,
        ]);
    }

    public function csv(Request $request, AuditService $audit): StreamedResponse
    {
        $company = $this->company($request);
        $query = $this->transactions($request)->with('charge')->orderBy('payment_transactions.paid_at');
        $audit->record('payments.transactions_exported', $company, $request->user());

        return response()->streamDownload(function () use ($query, $company): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            $csv = new class extends CsvFormat {};
            fwrite($output, CsvFormat::BOM);
            $csv->write($output, ['pago_em', 'cobranca', 'descricao', 'pagador', 'forma', 'provedor', 'origem', 'identificador', 'valor_bruto', 'devolvido', 'taxa', 'valor_liquido']);
            $query->chunk(500, function ($transactions) use ($output, $csv, $company): void {
                foreach ($transactions as $transaction) {
                    $csv->write($output, [
                        $transaction->paid_at->setTimezone($company->timezone)->format('Y-m-d H:i'), $transaction->charge->number, $transaction->charge->description, $transaction->payer_name ?? $transaction->charge->payer_name,
                        $transaction->charge->method, $transaction->charge->provider, $transaction->charge->origin->label(), $transaction->provider_transaction_id,
                        CsvFormat::formatDecimal($transaction->amount), CsvFormat::formatDecimal($transaction->refunded_amount), CsvFormat::formatDecimal($transaction->fee_amount), CsvFormat::formatDecimal($transaction->net_amount),
                    ]);
                }
            });
            fclose($output);
        }, 'recebimentos.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Builder<PaymentTransaction> */
    private function transactions(Request $request): Builder
    {
        $company = $this->company($request);
        $filters = $this->filters($request);

        return PaymentTransaction::query()->select('payment_transactions.*')->where('payment_transactions.company_id', $company->id)
            ->whereBetween('payment_transactions.paid_at', [
                now($company->timezone)->parse($filters['from'], $company->timezone)->startOfDay()->utc(),
                now($company->timezone)->parse($filters['until'], $company->timezone)->endOfDay()->utc(),
            ]);
    }

    /** @return array{from: string, until: string} */
    private function filters(Request $request): array
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $validated = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return ['from' => $validated['from'] ?? $now->copy()->startOfMonth()->format('Y-m-d'), 'until' => $validated['until'] ?? $now->format('Y-m-d')];
    }
}
