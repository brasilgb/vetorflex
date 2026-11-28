<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Core\Services\AuditService;
use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Reports\FinanceReportDocument;
use App\Modules\Finance\Reports\FinanceReports;
use App\Modules\Finance\Support\FinanceCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatórios gerenciais com filtros, totais e exportação em CSV e PDF.
 */
class FinanceReportController extends FinanceController
{
    public function __construct(private FinanceReports $reports, private AuditService $audit) {}

    public function index(Request $request): Response
    {
        [$type, $filters] = $this->filters($request);
        $company = $this->company($request);

        return Inertia::render('finance/reports', [
            'types' => collect(FinanceReports::TYPES)->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values()->all(),
            'report' => $this->reports->build($company, $type, $filters),
            'filters' => ['type' => $type, ...$filters],
            'accounts' => FinanceAccount::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name'])->map(fn (FinanceAccount $account): array => ['id' => $account->id, 'name' => $account->name])->values()->all(),
            'totalLabels' => FinanceReportDocument::TOTAL_LABELS,
            'canExport' => $this->can($request, FinancePermission::Export),
        ]);
    }

    public function csv(Request $request, FinanceCsv $csv): StreamedResponse
    {
        [$type, $filters] = $this->filters($request);
        $company = $this->company($request);
        $report = $this->reports->build($company, $type, $filters);
        $this->audit->record('finance.report_exported', $company, $request->user(), null, ['type' => $type, 'format' => 'csv']);

        return response()->streamDownload(function () use ($report, $csv): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, FinanceCsv::BOM);
            $csv->write($output, array_column($report['columns'], 'label'));

            foreach ($report['rows'] as $row) {
                $csv->write($output, array_map(fn (array $column): ?string => ($column['align'] ?? null) === 'right' ? FinanceCsv::formatDecimal($row[$column['key']] ?? null) : ($row[$column['key']] ?? null), $report['columns']));
            }

            foreach ($report['totals'] as $key => $value) {
                $csv->write($output, [FinanceReportDocument::TOTAL_LABELS[$key] ?? $key, FinanceCsv::formatDecimal($value)]);
            }

            fclose($output);
        }, "relatorio-{$type}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(Request $request, FinanceReportDocument $document): HttpResponse
    {
        [$type, $filters] = $this->filters($request);
        $company = $this->company($request);
        $content = $document->render($company, $this->reports->build($company, $type, $filters), now($company->timezone)->format('d/m/Y H:i'));
        $this->audit->record('finance.report_exported', $company, $request->user(), null, ['type' => $type, 'format' => 'pdf']);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"relatorio-{$type}.pdf\"",
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{string, array{from: string, until: string, regime: string, account_id: int|null, granularity: string}} */
    private function filters(Request $request): array
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(FinanceReports::TYPES))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'regime' => ['nullable', 'in:cash,competence'],
            'account_id' => ['nullable', 'integer'],
            'granularity' => ['nullable', 'in:day,week,month'],
        ]);
        $accountId = isset($validated['account_id']) && FinanceAccount::query()->where('company_id', $company->id)->whereKey($validated['account_id'])->exists() ? (int) $validated['account_id'] : null;

        return [$validated['type'] ?? 'receivables', [
            'from' => $validated['from'] ?? $now->startOfMonth()->format('Y-m-d'),
            'until' => $validated['until'] ?? $now->endOfMonth()->format('Y-m-d'),
            'regime' => $validated['regime'] ?? 'cash',
            'account_id' => $accountId,
            'granularity' => $validated['granularity'] ?? 'month',
        ]];
    }
}
