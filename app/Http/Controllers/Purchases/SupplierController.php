<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Requests\Purchases\SaveSupplierRequest;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Support\CsvFormat;
use App\Modules\Parties\Enums\AddressType;
use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Enums\ReceiptStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseReceiptItem;
use App\Modules\Purchases\Models\SupplierProduct;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Purchases\Services\SupplierService;
use App\Modules\Purchases\Support\PurchasePresenter;
use App\Modules\Purchases\Support\SupplierCsv;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierController extends PurchaseController
{
    public const FILTERS = ['search', 'active', 'type'];

    private const RECENT_RECORDS = 30;

    public function __construct(private SupplierService $suppliers, private PurchasePresenter $presenter) {}

    public function index(Request $request): Response
    {
        return Inertia::render('purchases/suppliers/index', [
            'suppliers' => $this->query($this->company($request), $request)
                ->paginate(15)->withQueryString()
                ->through(fn (SupplierProfile $supplier): array => $this->presenter->supplierSummary($supplier)),
            'filters' => $request->only(self::FILTERS),
        ]);
    }

    /** Pesquisa para seleção de fornecedor nos pedidos. Somente ativos, salvo pedido explícito. */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['term' => ['required', 'string', 'max:100']]);
        $request->merge(['search' => $validated['term'], 'active' => '1']);

        return response()->json($this->query($this->company($request), $request)->limit(10)->get()
            ->map(fn (SupplierProfile $supplier): array => $this->presenter->supplierSummary($supplier))->values());
    }

    public function create(): Response
    {
        return Inertia::render('purchases/suppliers/form', ['supplier' => null, 'addressTypes' => AddressType::options()]);
    }

    public function store(SaveSupplierRequest $request): RedirectResponse
    {
        $supplier = $this->suppliers->create($this->company($request), $request->user(), $request->validated());
        $this->toast('Fornecedor cadastrado com sucesso.');

        return to_route('purchases.suppliers.show', $supplier);
    }

    public function show(Request $request, SupplierProfile $supplier): Response
    {
        $canViewCost = $this->canViewCost($request);
        $supplier->load(['party.addresses', 'party.contacts', 'party.customerProfile']);
        $today = $this->today($request);

        return Inertia::render('purchases/suppliers/show', [
            'supplier' => [
                ...$this->presenter->supplierSummary($supplier),
                ...$supplier->party->only(['mobile', 'whatsapp', 'state_registration', 'municipal_registration']),
                'phone' => $supplier->party->phone,
                'raw_document' => $supplier->party->document,
                ...$supplier->only(['lead_time_days', 'payment_terms', 'minimum_order']),
                'notes' => $supplier->notes,
                'addresses' => $supplier->party->addresses,
                'contacts' => $supplier->party->contacts,
            ],
            'orders' => $supplier->orders()->latest('order_date')->latest('id')->limit(self::RECENT_RECORDS)->get()
                ->map(fn (PurchaseOrder $order): array => $this->presenter->orderSummary($order, $canViewCost, $today)),
            'totals' => [
                'orders' => $supplier->orders()->whereIn('status', PurchaseOrderStatus::effective())->count(),
                ...($canViewCost ? ['value' => (string) $supplier->orders()->whereIn('status', PurchaseOrderStatus::effective())->sum('total')] : []),
            ],
            'products' => $supplier->products()->with('variant.product')->get()
                ->sortBy(fn (SupplierProduct $link): string => $link->variant->displayName())
                ->map(fn (SupplierProduct $link): array => [
                    ...$link->only(['id', 'product_variant_id', 'supplier_code', 'reference', 'lead_time_days', 'minimum_quantity', 'is_preferred', 'notes']),
                    'name' => $link->variant->displayName(),
                    'sku' => $link->variant->sku,
                    'last_purchased_at' => $link->last_purchased_at?->format('Y-m-d'),
                    ...($canViewCost ? ['last_cost' => $link->last_cost] : []),
                ])->values(),
            'priceHistory' => ! $canViewCost ? [] : PurchaseReceiptItem::query()
                ->where('company_id', $supplier->company_id)
                ->whereHas('receipt', fn (Builder $receipt) => $receipt->where('status', ReceiptStatus::Confirmed)->whereHas('order', fn (Builder $order) => $order->where('supplier_profile_id', $supplier->id)))
                ->with(['receipt.order', 'orderItem'])->latest('id')->limit(self::RECENT_RECORDS)->get()
                ->map(fn (PurchaseReceiptItem $item): array => [
                    'id' => $item->id,
                    'received_at' => $item->receipt->received_at->format('Y-m-d'),
                    'order_id' => $item->receipt->purchase_order_id,
                    'order_number' => $item->receipt->order->number,
                    'description' => $item->orderItem->description,
                    'unit_code' => $item->orderItem->unit_code,
                    'quantity' => $item->quantity,
                    'purchase_unit_cost' => $item->purchase_unit_cost,
                    'unit_cost' => $item->unit_cost,
                ]),
        ]);
    }

    public function edit(SupplierProfile $supplier): Response
    {
        $supplier->load(['party.addresses', 'party.contacts']);

        return Inertia::render('purchases/suppliers/form', [
            'supplier' => [
                ...$supplier->party->only(['type', 'name', 'trade_name', 'email', 'phone', 'mobile', 'whatsapp', 'state_registration', 'municipal_registration']),
                'id' => $supplier->id,
                'document' => BrazilianDocument::format($supplier->party->document),
                ...$supplier->only(['internal_code', 'lead_time_days', 'payment_terms', 'minimum_order']),
                'notes' => $supplier->notes,
                'addresses' => $supplier->party->addresses,
                'contacts' => $supplier->party->contacts,
            ],
            'addressTypes' => AddressType::options(),
        ]);
    }

    public function update(SaveSupplierRequest $request, SupplierProfile $supplier): RedirectResponse
    {
        $this->suppliers->update($supplier, $request->user(), $request->validated());
        $this->toast('Fornecedor atualizado com sucesso.');

        return to_route('purchases.suppliers.show', $supplier);
    }

    public function deactivate(Request $request, SupplierProfile $supplier): RedirectResponse
    {
        $this->suppliers->setActive($supplier, $request->user(), false);
        $this->toast('Fornecedor inativado. O histórico de compras foi preservado.');

        return back();
    }

    public function reactivate(Request $request, SupplierProfile $supplier): RedirectResponse
    {
        $this->suppliers->setActive($supplier, $request->user(), true);
        $this->toast('Fornecedor reativado.');

        return back();
    }

    public function export(Request $request, SupplierCsv $csv, AuditService $audit): StreamedResponse
    {
        $company = $this->company($request);
        $query = $this->query($company, $request)->with('party.customerProfile');
        $audit->record('supplier.exported', $company, $request->user());

        return response()->streamDownload(function () use ($query, $csv): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, CsvFormat::BOM);
            $csv->write($output, [...SupplierCsv::COLUMNS, ...SupplierCsv::READ_ONLY_COLUMNS]);

            foreach ($query->lazyById(200, 'supplier_profiles.id', 'id') as $supplier) {
                $party = $supplier->party;
                $csv->write($output, [
                    $party->name, $party->type === 'company' ? 'juridica' : 'fisica', $party->trade_name, BrazilianDocument::format($party->document),
                    $party->state_registration, $party->municipal_registration, $party->email, $party->phone, $party->mobile, $party->whatsapp,
                    $supplier->internal_code, $supplier->lead_time_days, $supplier->payment_terms, CsvFormat::formatDecimal($supplier->minimum_order), $supplier->notes,
                    $supplier->is_active ? 'ativo' : 'inativo', $party->customerProfile !== null ? 'sim' : 'nao',
                ]);
            }

            fclose($output);
        }, 'fornecedores-'.$this->today($request).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Builder<SupplierProfile> */
    private function query(Company $company, Request $request): Builder
    {
        $search = $request->string('search')->trim()->value();
        $document = BrazilianDocument::normalize($search);

        return SupplierProfile::query()->where('supplier_profiles.company_id', $company->id)
            ->join('parties', 'parties.id', '=', 'supplier_profiles.party_id')
            ->select('supplier_profiles.*')
            ->with(['party.customerProfile'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('parties.name', 'like', "%{$search}%")
                ->orWhere('parties.trade_name', 'like', "%{$search}%")
                ->orWhere('parties.email', 'like', "%{$search}%")
                ->orWhere('supplier_profiles.internal_code', $search)
                ->when($document !== null && strlen($document) >= 4, fn (Builder $query) => $query->orWhere('parties.document', 'like', "%{$document}%"))))
            ->when($request->input('active') !== null && $request->input('active') !== '', fn (Builder $query) => $query->where('supplier_profiles.is_active', $request->boolean('active')))
            ->when(in_array($request->input('type'), ['individual', 'company'], true), fn (Builder $query) => $query->where('parties.type', $request->input('type')))
            ->orderBy('parties.name')->orderBy('supplier_profiles.id');
    }
}
