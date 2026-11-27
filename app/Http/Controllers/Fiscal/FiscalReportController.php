<?php

namespace App\Http\Controllers\Fiscal;

use App\Modules\Core\Support\CsvFormat;
use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Enums\FiscalPermission;
use App\Modules\Fiscal\Enums\FiscalStatus;
use App\Modules\Fiscal\Services\FiscalQueries;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatório fiscal: quantidade e valor por tipo e situação no período. Valores apenas documentados; não substituem
 * a apuração contábil.
 */
class FiscalReportController extends FiscalController
{
    public function index(Request $request, FiscalQueries $queries): Response
    {
        [$from, $until] = $this->period($request);

        return Inertia::render('fiscal/reports', [
            'rows' => $this->rows($queries->report($this->company($request), $from, $until)),
            'filters' => ['from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d')],
            'canExport' => $this->can($request, FiscalPermission::Export),
        ]);
    }

    public function csv(Request $request, FiscalQueries $queries): StreamedResponse
    {
        [$from, $until] = $this->period($request);
        $rows = $this->rows($queries->report($this->company($request), $from, $until));

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            $csv = new class extends CsvFormat {};
            fwrite($output, CsvFormat::BOM);
            $csv->write($output, ['tipo', 'situacao', 'documentos', 'valor']);

            foreach ($rows as $row) {
                $csv->write($output, [$row['type_label'], $row['status_label'], $row['count'], CsvFormat::formatDecimal($row['total'])]);
            }

            fclose($output);
        }, 'relatorio-fiscal.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<array{type: string, status: string, count: int, total: string}>  $rows
     * @return list<array{type: string, status: string, count: int, total: string, type_label: string, status_label: string}>
     */
    private function rows(array $rows): array
    {
        return array_map(fn (array $row): array => [...$row, 'type_label' => DocumentType::from($row['type'])->shortLabel(), 'status_label' => FiscalStatus::from($row['status'])->label()], $rows);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $company = $this->company($request);
        $validated = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $now = CarbonImmutable::now($company->timezone);

        return [
            isset($validated['from']) ? CarbonImmutable::parse($validated['from'], $company->timezone)->startOfDay() : $now->startOfMonth(),
            isset($validated['until']) ? CarbonImmutable::parse($validated['until'], $company->timezone)->endOfDay() : $now->endOfDay(),
        ];
    }
}
