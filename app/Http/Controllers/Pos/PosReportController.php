<?php

namespace App\Http\Controllers\Pos;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Pos\Services\PosReports;
use App\Modules\Pos\Support\PosCsv;
use App\Modules\Tenancy\Models\CompanyMembership;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Indicadores e relatórios do PDV, com filtros por período, terminal e operador, e exportação CSV.
 */
class PosReportController extends PosController
{
    public function __construct(private PosReports $reports, private AuditService $audit) {}

    public function index(Request $request): Response
    {
        [$type, $filters] = $this->filters($request);
        $company = $this->company($request);

        return Inertia::render('pos/reports', [
            'types' => collect(PosReports::TYPES)->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values()->all(),
            'summary' => $this->reports->summary($company, $filters),
            'report' => $this->reports->build($company, $type, $filters),
            'filters' => ['type' => $type, ...$filters],
            'terminals' => PosTerminal::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name'])->map(fn (PosTerminal $terminal): array => ['id' => $terminal->id, 'name' => $terminal->name])->values()->all(),
            'operators' => User::query()->whereIn('id', CompanyMembership::query()->where('company_id', $company->id)->select('user_id'))->orderBy('name')->get(['id', 'name'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'canExport' => $this->can($request, PosPermission::ViewReports),
        ]);
    }

    public function csv(Request $request, PosCsv $csv): StreamedResponse
    {
        [$type, $filters] = $this->filters($request);
        $company = $this->company($request);
        $report = $this->reports->build($company, $type, $filters);
        $this->audit->record('pos.report_exported', $company, $request->user(), null, ['type' => $type]);

        return response()->streamDownload(function () use ($report, $csv): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, PosCsv::BOM);
            $csv->write($output, array_column($report['columns'], 'label'));

            foreach ($report['rows'] as $row) {
                $csv->write($output, array_map(fn (array $column): ?string => ($column['align'] ?? null) === 'right' ? PosCsv::formatDecimal($row[$column['key']] ?? null) : ($row[$column['key']] ?? null), $report['columns']));
            }

            fclose($output);
        }, "pdv-{$type}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{string, array{from: string, until: string, terminal: int|null, operator: int|null}} */
    private function filters(Request $request): array
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(PosReports::TYPES))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'terminal' => ['nullable', 'integer'],
            'operator' => ['nullable', 'integer'],
        ]);

        return [$validated['type'] ?? 'by_operator', [
            'from' => $validated['from'] ?? $now->startOfMonth()->format('Y-m-d'),
            'until' => $validated['until'] ?? $now->format('Y-m-d'),
            'terminal' => isset($validated['terminal']) ? (int) $validated['terminal'] : null,
            'operator' => isset($validated['operator']) ? (int) $validated['operator'] : null,
        ]];
    }
}
