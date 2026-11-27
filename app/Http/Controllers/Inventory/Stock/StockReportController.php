<?php

namespace App\Http\Controllers\Inventory\Stock;

use App\Modules\Core\Services\AuditService;
use App\Modules\Inventory\Enums\InventoryPermission;
use App\Modules\Inventory\Models\InventoryWarehouse;
use App\Modules\Inventory\Services\StockReports;
use App\Modules\Inventory\Support\StockCsv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatórios do Estoque com filtros e exportação CSV. O valor estimado exige `inventory.view_cost`.
 */
class StockReportController extends StockController
{
    public function __construct(private StockReports $reports) {}

    public function index(Request $request): Response
    {
        [$type, $filters] = $this->filters($request);
        $company = $this->company($request);
        $canViewCost = $this->can($request, InventoryPermission::ViewCost);

        return Inertia::render('inventory/reports', [
            'types' => collect(StockReports::TYPES)->except($canViewCost ? [] : ['valuation'])->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values()->all(),
            'report' => $this->reports->build($company, $type, $filters, $canViewCost),
            'filters' => ['type' => $type, ...$filters],
            'warehouses' => $this->warehouseOptions($company),
        ]);
    }

    public function csv(Request $request, StockCsv $csv, AuditService $audit): StreamedResponse
    {
        [$type, $filters] = $this->filters($request);
        $company = $this->company($request);
        $report = $this->reports->build($company, $type, $filters, $this->can($request, InventoryPermission::ViewCost));
        $audit->record('inventory.report_exported', $company, $request->user(), null, ['type' => $report['type']]);

        return response()->streamDownload(function () use ($report, $csv): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, StockCsv::BOM);
            $csv->write($output, array_column($report['columns'], 'label'));

            foreach ($report['rows'] as $row) {
                $csv->write($output, array_map(fn (array $column): ?string => ($column['align'] ?? null) === 'right' ? StockCsv::formatDecimal($row[$column['key']] ?? null) : ($row[$column['key']] ?? null), $report['columns']));
            }

            fclose($output);
        }, "estoque-{$report['type']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{string, array{from: string, until: string, warehouse: int|null, days: int}} */
    private function filters(Request $request): array
    {
        $company = $this->company($request);
        $now = now($company->timezone);
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(StockReports::TYPES))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'warehouse' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $warehouse = isset($validated['warehouse']) && InventoryWarehouse::query()->where('company_id', $company->id)->whereKey($validated['warehouse'])->exists() ? (int) $validated['warehouse'] : null;

        return [$validated['type'] ?? 'balances', [
            'from' => $validated['from'] ?? $now->copy()->startOfMonth()->format('Y-m-d'),
            'until' => $validated['until'] ?? $now->format('Y-m-d'),
            'warehouse' => $warehouse,
            'days' => (int) ($validated['days'] ?? config('inventory.idle_days')),
        ]];
    }
}
