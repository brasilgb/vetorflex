<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Requests\Purchases\StoreSupplierImportRequest;
use App\Modules\Core\Support\CsvFormat;
use App\Modules\Purchases\Models\SupplierImport;
use App\Modules\Purchases\Services\SupplierImportService;
use App\Modules\Purchases\Support\SupplierCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierImportController extends PurchaseController
{
    public function __construct(private SupplierImportService $imports, private SupplierCsv $csv) {}

    public function create(): Response
    {
        return Inertia::render('purchases/suppliers/import', ['limits' => $this->limits(), 'import' => null]);
    }

    public function template(): StreamedResponse
    {
        return $this->download('modelo-fornecedores.csv', [
            SupplierCsv::COLUMNS,
            ['Distribuidora Exemplo Ltda', 'juridica', 'Distribuidora Exemplo', '', '', '', 'compras@exemplo.com', '(11) 3000-0000', '', '', 'F-001', '7', '30 dias', '500,00', ''],
        ]);
    }

    public function store(StoreSupplierImportRequest $request): RedirectResponse
    {
        return to_route('purchases.suppliers.imports.show', $this->imports->preview($this->company($request), $request->user(), $request->file('file')));
    }

    public function show(Request $request, SupplierImport $import): Response
    {
        $this->ensureAuthor($request, $import);

        return Inertia::render('purchases/suppliers/import', [
            'limits' => $this->limits(),
            'import' => [
                ...$import->only(['id', 'file_name', 'status', 'report']),
                'rows' => array_map(
                    fn (array $row): array => [...Arr::only($row, ['line', 'name', 'status', 'party_name', 'errors']), 'document' => $row['attributes']['document']],
                    $this->imports->rows($import, $this->company($request)),
                ),
            ],
        ]);
    }

    public function confirm(Request $request, SupplierImport $import): RedirectResponse
    {
        $this->ensureAuthor($request, $import);
        $validated = $request->validate(['link_existing' => ['sometimes', 'boolean']]);
        $this->imports->confirm($import, $this->company($request), $request->user(), (bool) ($validated['link_existing'] ?? false));
        $this->toast('Importação concluída.');

        return to_route('purchases.suppliers.imports.show', $import);
    }

    public function errors(Request $request, SupplierImport $import): StreamedResponse
    {
        $this->ensureAuthor($request, $import);
        $errors = $import->report['errors'] ?? array_map(
            fn (array $row): array => ['line' => $row['line'], 'name' => $row['name'], 'messages' => $row['errors']],
            array_filter($this->imports->rows($import, $this->company($request)), fn (array $row): bool => $row['errors'] !== []),
        );

        return $this->download("erros-importacao-fornecedores-{$import->id}.csv", [
            ['linha', 'nome', 'motivo'],
            ...array_map(fn (array $row): array => [$row['line'], $row['name'], implode(' ', $row['messages'])], array_values($errors)),
        ]);
    }

    private function ensureAuthor(Request $request, SupplierImport $import): void
    {
        abort_unless($import->user_id === $request->user()?->id, 404);
    }

    /** @return array{rows: int, kilobytes: int} */
    private function limits(): array
    {
        return ['rows' => (int) config('purchases.import.maximum_rows'), 'kilobytes' => (int) config('purchases.import.maximum_kilobytes')];
    }

    /** @param list<array<int, mixed>> $rows */
    private function download(string $name, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, CsvFormat::BOM);

            foreach ($rows as $row) {
                $this->csv->write($output, $row);
            }

            fclose($output);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
