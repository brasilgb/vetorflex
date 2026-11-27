<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\ImportCustomersRequest;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Modules\Core\Support\CsvCell;
use App\Modules\Customers\Models\CustomerImport;
use App\Modules\Customers\Models\CustomerProfile;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Parties\Services\PartyService;
use App\Modules\Parties\Support\BrazilianDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function __construct(private PartyService $parties) {}

    public function index(Request $request): Response
    {
        $company = $request->attributes->get('company');

        return Inertia::render('customers/index', [
            'customers' => Party::query()->customersOf($company)
                ->select(['parties.id', 'parties.name', 'parties.trade_name', 'parties.type', 'parties.document', 'parties.email', 'parties.is_active'])
                ->when($request->string('search')->trim()->value(), function (Builder $query, string $search): void {
                    $query->where(function (Builder $query) use ($search): void {
                        $query->where('parties.name', 'like', "%{$search}%")
                            ->orWhere('parties.trade_name', 'like', "%{$search}%")
                            ->orWhere('parties.document', 'like', '%'.BrazilianDocument::normalize($search).'%')
                            ->orWhere('parties.email', 'like', "%{$search}%");
                    });
                })
                ->when($request->input('type') !== null, fn (Builder $query) => $query->where('parties.type', $request->input('type')))
                ->when($request->input('active') !== null, fn (Builder $query) => $query->where('parties.is_active', $request->boolean('active')))
                ->orderBy('parties.name')->paginate(15)->withQueryString(),
            'filters' => $request->only(['search', 'type', 'active']),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = $this->saveCustomer($request, new Party);

        return to_route('customers.show', $customer)->with('success', 'Cliente cadastrado com sucesso.');
    }

    public function show(Request $request, Party $customer): Response
    {
        return Inertia::render('customers/show', ['customer' => $this->customerForCompany($request, $customer)->load(['addresses', 'contacts', 'customerProfile'])]);
    }

    public function update(UpdateCustomerRequest $request, Party $customer): RedirectResponse
    {
        $customer = $this->customerForCompany($request, $customer);
        $this->saveCustomer($request, $customer);

        return to_route('customers.show', $customer)->with('success', 'Cliente atualizado com sucesso.');
    }

    public function deactivate(Request $request, Party $customer): RedirectResponse
    {
        $this->customerForCompany($request, $customer)->update(['is_active' => false]);

        return to_route('customers.show', $customer)->with('success', 'Cliente inativado.');
    }

    public function reactivate(Request $request, Party $customer): RedirectResponse
    {
        $this->customerForCompany($request, $customer)->update(['is_active' => true]);

        return to_route('customers.show', $customer)->with('success', 'Cliente reativado.');
    }

    public function export(Request $request): StreamedResponse
    {
        $company = $request->attributes->get('company');

        return response()->streamDownload(function () use ($company): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, ['tipo', 'nome', 'nome_fantasia', 'documento', 'email', 'telefone', 'celular']);
            Party::query()->customersOf($company)->orderBy('name')->each(function (Party $customer) use ($output): void {
                fputcsv($output, CsvCell::escapeRow([$customer->type, $customer->name, $customer->trade_name, $customer->document, $customer->email, $customer->phone, $customer->mobile]));
            });
            fclose($output);
        }, 'clientes-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(ImportCustomersRequest $request): RedirectResponse
    {
        $company = $request->attributes->get('company');
        $file = $request->file('file');
        $rows = array_map('str_getcsv', file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $headers = array_map(
            fn (?string $header): string => Str::snake(Str::ascii(trim(str_replace("\xEF\xBB\xBF", '', (string) $header)))),
            array_shift($rows) ?? [],
        );
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $headers, $company, $request, $file, &$created, &$skipped): void {
            foreach ($rows as $row) {
                if ($headers === []) {
                    break;
                }

                $data = array_map(CsvCell::unescape(...), array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), null)));
                $document = BrazilianDocument::normalize($data['documento'] ?? $data['document'] ?? null);
                $name = trim((string) ($data['nome'] ?? $data['name'] ?? ''));
                $type = $data['tipo'] ?? $data['type'] ?? 'individual';

                if (! in_array($type, ['individual', 'company'], true)) {
                    $type = 'individual';
                }

                if ($name === '' || ($document !== null && ! $this->isValidDocument($type, $document)) || ($document !== null && Party::query()->where('company_id', $company->id)->where('document', $document)->exists())) {
                    $skipped++;

                    continue;
                }

                $party = $this->parties->save($company, new Party, PartyRole::CUSTOMER, ['type' => $type, 'name' => $name, 'trade_name' => $data['nome_fantasia'] ?? $data['trade_name'] ?? null, 'document' => $document, 'email' => $data['email'] ?? null, 'phone' => $data['telefone'] ?? $data['phone'] ?? null, 'mobile' => $data['celular'] ?? $data['mobile'] ?? null]);
                CustomerProfile::create(['company_id' => $company->id, 'party_id' => $party->id, 'registered_by' => $request->user()->id]);
                $created++;
            }
            CustomerImport::create(['company_id' => $company->id, 'user_id' => $request->user()->id, 'file_name' => $file->getClientOriginalName(), 'status' => CustomerImport::COMPLETED, 'report' => compact('created', 'skipped'), 'completed_at' => now()]);
        });

        return to_route('customers.index')->with('success', "Importação concluída: {$created} cliente(s) criado(s), {$skipped} linha(s) ignorada(s).");
    }

    private function customerForCompany(Request $request, Party $customer): Party
    {
        abort_unless(Party::query()->customersOf($request->attributes->get('company'))->whereKey($customer)->exists(), 404);

        return $customer;
    }

    private function saveCustomer(StoreCustomerRequest $request, Party $customer): Party
    {
        $company = $request->attributes->get('company');
        $validated = $request->validated();
        $partyAttributes = collect($validated)->only(['type', 'name', 'trade_name', 'document', 'email', 'phone', 'mobile', 'whatsapp', 'state_registration', 'municipal_registration', 'birth_date', 'notes'])->all();
        $profileAttributes = collect($validated)->only(['credit_limit', 'payment_terms', 'classification', 'commercial_notes'])->all();

        // A pessoa é gravada pelo domínio compartilhado (PartyService); Clientes grava apenas o próprio perfil.
        return DB::transaction(function () use ($company, $request, $customer, $validated, $partyAttributes, $profileAttributes): Party {
            $isNew = ! $customer->exists;
            $customer = $this->parties->save($company, $customer, PartyRole::CUSTOMER, [
                ...$partyAttributes,
                'document' => $partyAttributes['document'] ?? null,
                'addresses' => array_map(fn (array $address): array => Arr::except($address, 'id'), $validated['addresses'] ?? []),
                'contacts' => array_map(fn (array $contact): array => Arr::except($contact, 'id'), $validated['contacts'] ?? []),
            ]);
            $customer->customerProfile()->updateOrCreate([], [...$profileAttributes, 'company_id' => $company->id, 'registered_by' => $isNew ? $request->user()->id : $customer->customerProfile?->registered_by]);

            return $customer;
        });
    }

    private function isValidDocument(string $type, string $document): bool
    {
        return $type === 'company'
            ? BrazilianDocument::isCnpj($document)
            : BrazilianDocument::isCpf($document);
    }
}
