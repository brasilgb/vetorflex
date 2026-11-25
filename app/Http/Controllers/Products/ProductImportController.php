<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\ConfirmProductImportRequest;
use App\Http\Requests\Products\StoreProductImportRequest;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Services\ProductImportService;
use App\Modules\Products\Support\ProductCsv;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    use InteractsWithCompany;

    public function __construct(private ProductImportService $imports, private ProductCsv $csv) {}

    public function create(): Response
    {
        return Inertia::render('products/import', [
            'limits' => ['rows' => config('products.import.maximum_rows'), 'kilobytes' => config('products.import.maximum_kilobytes')],
            'import' => null,
        ]);
    }

    public function template(): StreamedResponse
    {
        return $this->download('modelo-produtos.csv', [
            ProductCsv::COLUMNS,
            ['Caderno universitário', 'CAD-01', '', '', '', 'Papelaria > Cadernos', 'Marca exemplo', 'UN', '', '', '', '12,50', '24,90', '', '10', 'sim', '50', '', '', '', ''],
        ]);
    }

    public function store(StoreProductImportRequest $request): RedirectResponse
    {
        return to_route('products.imports.show', $this->imports->preview($this->company($request), $request->user(), $request->file('file')));
    }

    public function show(Request $request, ProductImport $import): Response
    {
        $this->ensureAuthor($request, $import);

        return Inertia::render('products/import', [
            'limits' => ['rows' => config('products.import.maximum_rows'), 'kilobytes' => config('products.import.maximum_kilobytes')],
            'import' => [
                ...$import->only(['id', 'file_name', 'status', 'report']),
                'rows' => array_map(
                    fn (array $row): array => Arr::only($row, ['line', 'name', 'status', 'attributes', 'product_id', 'product_name', 'errors', 'warnings']),
                    $this->imports->rows($import, $this->company($request), $this->allowed($request)),
                ),
            ],
        ]);
    }

    public function confirm(ConfirmProductImportRequest $request, ProductImport $import): RedirectResponse
    {
        $this->ensureAuthor($request, $import);
        $this->imports->confirm($import, $this->company($request), $request->user(), $request->updates(), $this->allowed($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Importação concluída.']);

        return to_route('products.imports.show', $import);
    }

    public function errors(Request $request, ProductImport $import): StreamedResponse
    {
        $this->ensureAuthor($request, $import);
        $errors = $import->report['errors'] ?? array_filter(
            $this->imports->rows($import, $this->company($request), $this->allowed($request)),
            fn (array $row): bool => $row['errors'] !== [],
        );

        return $this->download("erros-importacao-{$import->id}.csv", [
            ['linha', 'nome', 'motivo'],
            ...array_map(fn (array $row): array => [$row['line'], $row['name'], implode(' ', $row['messages'] ?? $row['errors'])], array_values($errors)),
        ]);
    }

    private function ensureAuthor(Request $request, ProductImport $import): void
    {
        abort_unless($import->user_id === $request->user()?->id, 404);
    }

    /** @return Closure(ProductPermission): bool */
    private function allowed(Request $request): Closure
    {
        return fn (ProductPermission $permission): bool => $this->allows($request, $permission->value);
    }

    /** @param list<array<int, mixed>> $rows */
    private function download(string $name, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, ProductCsv::BOM);

            foreach ($rows as $row) {
                $this->csv->write($output, $row);
            }

            fclose($output);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
