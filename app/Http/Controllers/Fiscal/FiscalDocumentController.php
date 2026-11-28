<?php

namespace App\Http\Controllers\Fiscal;

use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Support\CsvFormat;
use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Enums\FiscalOrigin;
use App\Modules\Fiscal\Enums\FiscalPermission;
use App\Modules\Fiscal\Enums\FiscalStatus;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalDocumentEvent;
use App\Modules\Fiscal\Providers\FiscalProviderRegistry;
use App\Modules\Fiscal\Services\FiscalDocumentService;
use App\Modules\Fiscal\Services\FiscalFiles;
use App\Modules\Fiscal\Services\FiscalModule;
use App\Modules\Fiscal\Services\FiscalOrigins;
use App\Modules\Fiscal\Services\FiscalQueries;
use App\Modules\Fiscal\Support\FiscalPresenter;
use App\Modules\Parties\Models\Party;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Central de documentos fiscais: listagem com filtros, rascunho (avulso ou de uma origem), edição, pré-visualização,
 * emissão, consulta ao provedor, cancelamento, XML/PDF protegidos e exportação CSV.
 */
class FiscalDocumentController extends FiscalController
{
    public function __construct(private FiscalDocumentService $documents, private FiscalPresenter $presenter) {}

    public function index(Request $request, FiscalQueries $queries): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('fiscal/documents/index', [
            'documents' => $queries->documents($this->company($request), $filters)->latest('id')->paginate(25)->withQueryString()
                ->through(fn (FiscalDocument $document): array => $this->presenter->document($document)),
            'filters' => $filters,
            'statuses' => FiscalStatus::options(),
            'types' => $this->presenter->types(),
            'origins' => FiscalOrigin::options(),
            'canExport' => $this->can($request, FiscalPermission::Export),
            'canCreate' => $this->can($request, FiscalPermission::CreateDraft),
        ]);
    }

    public function create(Request $request, FiscalOrigins $origins, FiscalModule $module): Response
    {
        $company = $this->company($request);
        $validated = $request->validate(['origin' => ['nullable', Rule::in(['sale', 'pos', 'finance'])], 'origin_id' => ['nullable', 'integer', 'required_with:origin']]);
        $prefill = null;

        if (isset($validated['origin'])) {
            $resolved = $origins->resolve($company, FiscalOrigin::from($validated['origin']), (int) $validated['origin_id']);
            $prefill = [...$resolved, 'origin' => $validated['origin'], 'suggested_type' => $resolved['suggested_type']->value];
        }

        return Inertia::render('fiscal/documents/form', [
            'document' => null,
            'prefill' => $prefill,
            ...$this->formOptions($request, $module),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->contentRules(),
            // Com origem, os itens ausentes vêm da venda, do atendimento ou do título.
            'items' => [$request->filled('origin_id') ? 'nullable' : 'required', 'array', 'min:1', 'max:200'],
            'type' => ['required', Rule::enum(DocumentType::class)],
            'origin' => ['nullable', Rule::in(['manual', 'sale', 'pos', 'finance'])],
            'origin_id' => ['nullable', 'integer', 'required_if:origin,sale,pos,finance'],
            'idempotency_key' => ['required', 'uuid'],
        ], $this->messages());
        $document = $this->documents->createDraft($this->company($request), $request->user(), $validated);
        $this->toast("Rascunho de {$document->type->shortLabel()} nº interno {$document->local_number} salvo. A emissão só acontece quando solicitada.");

        return to_route('fiscal.documents.show', $document);
    }

    public function show(Request $request, FiscalDocument $document, FiscalProviderRegistry $providers): Response
    {
        $capabilities = $document->provider === null ? null : $providers->get($document->provider)->capabilities();
        $pending = $document->status->isPending();

        return Inertia::render('fiscal/documents/show', [
            'document' => $this->presenter->detail($document),
            'events' => FiscalDocumentEvent::query()->where('fiscal_document_id', $document->id)->latest('id')->limit(100)->get()->map(fn (FiscalDocumentEvent $event): array => $this->presenter->event($event))->values()->all(),
            'preview' => $document->status->isEditable() || $document->status === FiscalStatus::CommunicationFailure ? $this->documents->preview($document) : null,
            'abilities' => [
                'edit' => $document->status->isEditable() && $this->can($request, FiscalPermission::Edit),
                'delete' => $document->status === FiscalStatus::Draft && $document->submitted_at === null && $this->can($request, FiscalPermission::Edit),
                'issue' => ($document->status->isEditable() || $document->status === FiscalStatus::CommunicationFailure) && $this->can($request, FiscalPermission::Issue),
                'refresh' => $document->submitted_at !== null && $capabilities?->query === true && ($pending || $this->can($request, FiscalPermission::Reprocess)),
                'cancel' => $document->status === FiscalStatus::Authorized && $capabilities?->cancel === true && $this->can($request, FiscalPermission::Cancel),
                'download' => $document->hasFiles() && $this->can($request, FiscalPermission::Download),
                'xml' => $capabilities?->xml === true,
                'pdf' => $capabilities?->pdf === true,
            ],
            'capabilities' => $capabilities?->toArray(),
        ]);
    }

    public function edit(Request $request, FiscalDocument $document, FiscalModule $module): Response|RedirectResponse
    {
        if (! $document->status->isEditable()) {
            return to_route('fiscal.documents.show', $document);
        }

        return Inertia::render('fiscal/documents/form', [
            'document' => $this->presenter->detail($document),
            'prefill' => null,
            ...$this->formOptions($request, $module),
        ]);
    }

    public function update(Request $request, FiscalDocument $document): RedirectResponse
    {
        $validated = $request->validate($this->contentRules(), $this->messages());
        $this->documents->updateDraft($document, $request->user(), $validated);
        $this->toast('Documento atualizado.');

        return to_route('fiscal.documents.show', $document);
    }

    public function destroy(Request $request, FiscalDocument $document): RedirectResponse
    {
        $this->documents->deleteDraft($document, $request->user());
        $this->toast('Rascunho excluído.');

        return to_route('fiscal.documents.index');
    }

    public function issue(Request $request, FiscalDocument $document): RedirectResponse
    {
        $document = $this->documents->issue($document, $request->user());
        $document->refresh();
        $this->toast(match ($document->status) {
            FiscalStatus::Authorized => "Documento autorizado — número {$document->number}.",
            FiscalStatus::Rejected, FiscalStatus::Denied => "{$document->status->label()}: {$document->error_message}",
            FiscalStatus::CommunicationFailure => "Falha de comunicação: {$document->error_message}",
            default => 'Emissão solicitada. A situação é atualizada automaticamente.',
        }, in_array($document->status, [FiscalStatus::Rejected, FiscalStatus::Denied, FiscalStatus::CommunicationFailure], true) ? 'error' : 'success');

        return back();
    }

    public function refresh(Request $request, FiscalDocument $document): RedirectResponse
    {
        $document = $this->documents->refresh($document, $request->user());
        $this->toast("Situação consultada: {$document->status->label()}.");

        return back();
    }

    public function cancel(Request $request, FiscalDocument $document): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:15', 'max:255']], ['reason.required' => 'Informe a justificativa do cancelamento.', 'reason.min' => 'A justificativa deve ter ao menos 15 caracteres.']);
        $document = $this->documents->cancel($document, $request->user(), $validated['reason']);
        $this->toast($document->status === FiscalStatus::Cancelled ? 'Cancelamento homologado.' : 'Cancelamento solicitado. A confirmação é atualizada automaticamente.');

        return back();
    }

    public function download(Request $request, FiscalDocument $document, string $format, FiscalFiles $files, AuditService $audit): HttpResponse
    {
        abort_unless(in_array($format, ['xml', 'pdf'], true), 404);
        $file = $files->read($document, $format);
        $audit->record('fiscal.file_downloaded', $this->company($request), $request->user(), $document, ['format' => $format, 'local_number' => $document->local_number]);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function export(Request $request, FiscalQueries $queries, AuditService $audit): StreamedResponse
    {
        $company = $this->company($request);
        $query = $queries->documents($company, $this->filters($request))->orderBy('id');
        $audit->record('fiscal.documents_exported', $company, $request->user());

        return response()->streamDownload(function () use ($query, $company): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            $csv = new class extends CsvFormat {};
            fwrite($output, CsvFormat::BOM);
            $csv->write($output, ['numero_interno', 'tipo', 'situacao', 'ambiente', 'origem', 'referencia_origem', 'destinatario', 'documento_destinatario', 'numero', 'serie', 'chave_acesso', 'emitido_em', 'valor', 'motivo']);
            $query->chunk(500, function ($documents) use ($output, $csv, $company): void {
                foreach ($documents as $document) {
                    $csv->write($output, [
                        $document->local_number, $document->type->shortLabel(), $document->status->label(), $document->environment, $document->origin->label(), $document->origin_label,
                        $document->receiver['name'] ?? null, $document->receiver['document'] ?? null, $document->number, $document->series, $document->access_key,
                        $document->issued_at?->setTimezone($company->timezone)->format('Y-m-d H:i'), CsvFormat::formatDecimal($document->total), $document->error_message,
                    ]);
                }
            });
            fclose($output);
        }, 'documentos-fiscais.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request, FiscalModule $module): array
    {
        $company = $this->company($request);

        return [
            'types' => $this->presenter->types(),
            'paymentMethods' => PaymentMethod::options(),
            'parties' => Party::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name', 'document'])
                ->map(fn (Party $party): array => ['id' => $party->id, 'name' => $party->name, 'document' => $party->document])->values()->all(),
            'hasProducts' => $module->isEnabled($company, 'products'),
            'environment' => $module->settings($company)->environment,
        ];
    }

    /** @return array<string, list<mixed>> */
    private function contentRules(): array
    {
        return [
            'receiver_party_id' => ['nullable', 'integer'],
            'receiver' => ['nullable', 'array'],
            'receiver.name' => ['nullable', 'string', 'max:160'],
            'receiver.document' => ['nullable', 'string', 'max:20'],
            'receiver.state_registration' => ['nullable', 'string', 'max:20'],
            'receiver.municipal_registration' => ['nullable', 'string', 'max:20'],
            'receiver.email' => ['nullable', 'email', 'max:160'],
            'receiver.phone' => ['nullable', 'string', 'max:30'],
            'receiver.address' => ['nullable', 'array'],
            'receiver.address.postal_code' => ['nullable', 'string', 'max:10'],
            'receiver.address.street' => ['nullable', 'string', 'max:100'],
            'receiver.address.number' => ['nullable', 'string', 'max:10'],
            'receiver.address.complement' => ['nullable', 'string', 'max:150'],
            'receiver.address.district' => ['nullable', 'string', 'max:100'],
            'receiver.address.city' => ['nullable', 'string', 'max:100'],
            'receiver.address.city_code' => ['nullable', 'digits:7'],
            'receiver.address.state' => ['nullable', 'string', 'size:2'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.code' => ['nullable', 'string', 'max:60'],
            'items.*.description' => ['required_with:items', 'string', 'max:500'],
            'items.*.quantity' => ['required_with:items', 'decimal:0,4', 'gt:0', 'max:9999999'],
            'items.*.unit' => ['nullable', 'string', 'max:6'],
            'items.*.unit_price' => ['required_with:items', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.discount' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.gtin' => ['nullable', 'regex:/^(\d{8}|\d{12,14})$/'],
            'items.*.is_service' => ['nullable', 'boolean'],
            'items.*.ncm' => ['nullable', 'digits:8'],
            'items.*.cest' => ['nullable', 'digits:7'],
            'items.*.cfop' => ['nullable', 'regex:/^[5-7]\d{3}$/'],
            'items.*.origin' => ['nullable', 'integer', 'between:0,8'],
            'items.*.icms_situation' => ['nullable', 'regex:/^(\d{2}|\d{3})$/'],
            'items.*.pis_situation' => ['nullable', 'digits:2'],
            'items.*.cofins_situation' => ['nullable', 'digits:2'],
            'items.*.service_code' => ['nullable', 'regex:/^\d{1,2}\.?\d{2}$/'],
            'items.*.tax_unit' => ['nullable', 'string', 'max:6'],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'tax_data' => ['nullable', 'array'],
            'tax_data.service_code' => ['nullable', 'regex:/^\d{1,2}\.?\d{2}$/'],
            'tax_data.iss_rate' => ['nullable', 'decimal:0,4', 'between:0,5'],
            'tax_data.taxation_type' => ['nullable', 'string', 'max:40'],
            'tax_data.city_code' => ['nullable', 'digits:7'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'items.required' => 'Adicione ao menos um item.',
            'items.*.description.required' => 'Descreva o item.',
            'items.*.ncm.digits' => 'O NCM tem 8 dígitos.',
            'items.*.cest.digits' => 'O CEST tem 7 dígitos.',
            'items.*.cfop.regex' => 'CFOP de saída: 4 dígitos iniciados por 5, 6 ou 7.',
            'items.*.gtin.regex' => 'GTIN com 8, 12, 13 ou 14 dígitos.',
            'items.*.service_code.regex' => 'Item da lista de serviços (LC 116) no formato 01.07.',
        ];
    }

    /** @return array{from: string|null, until: string|null, status: string|null, type: string|null, origin: string|null, search: string|null} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::enum(FiscalStatus::class)],
            'type' => ['nullable', Rule::enum(DocumentType::class)],
            'origin' => ['nullable', Rule::enum(FiscalOrigin::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return ['from' => $validated['from'] ?? null, 'until' => $validated['until'] ?? null, 'status' => $validated['status'] ?? null, 'type' => $validated['type'] ?? null, 'origin' => $validated['origin'] ?? null, 'search' => $validated['search'] ?? null];
    }
}
