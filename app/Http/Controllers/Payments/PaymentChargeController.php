<?php

namespace App\Http\Controllers\Payments;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Support\CsvFormat;
use App\Modules\Payments\Enums\ChargeOrigin;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Enums\PaymentPermission;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentChargeEvent;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Providers\ProviderException;
use App\Modules\Payments\Providers\ProviderRegistry;
use App\Modules\Payments\Services\ChargeOrigins;
use App\Modules\Payments\Services\ChargeQueries;
use App\Modules\Payments\Services\ChargeService;
use App\Modules\Payments\Services\PaymentsModule;
use App\Modules\Payments\Support\PaymentPresenter;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cobranças: listagem com filtros, criação (avulsa, de venda ou de conta a receber), detalhe com link, QR Code e
 * histórico, emissão, consulta ao provedor, cancelamento, devolução e exportação CSV.
 */
class PaymentChargeController extends PaymentsController
{
    public function __construct(private ChargeService $charges, private PaymentPresenter $presenter) {}

    public function index(Request $request, ChargeQueries $queries, ProviderRegistry $providers): Response
    {
        $company = $this->company($request);
        $filters = $this->filters($request);

        return Inertia::render('payments/charges/index', [
            'charges' => $queries->charges($company, $filters)->latest('id')->paginate(25)->withQueryString()
                ->through(fn (PaymentCharge $charge): array => $this->presenter->charge($charge)),
            'filters' => $filters,
            'statuses' => ChargeStatus::options(),
            'origins' => ChargeOrigin::options(),
            'providers' => array_values(array_map(fn ($provider): array => ['value' => $provider->identifier(), 'label' => $provider->name()], $providers->all())),
            'canExport' => $this->can($request, PaymentPermission::Export),
            'canCreate' => $this->can($request, PaymentPermission::Create),
        ]);
    }

    public function create(Request $request, PaymentsModule $module, ChargeOrigins $origins): Response
    {
        $company = $this->company($request);
        $validated = $request->validate(['origin' => ['nullable', Rule::in(['sale', 'finance'])], 'origin_id' => ['nullable', 'integer']]);
        $prefill = null;

        if (isset($validated['origin'], $validated['origin_id'])) {
            $resolved = $origins->resolve($company, ChargeOrigin::from($validated['origin']), (int) $validated['origin_id'], null);
            $prefill = ['origin' => $validated['origin'], 'origin_id' => (int) $validated['origin_id'], 'label' => $resolved['label'], 'amount' => $resolved['balance'], 'description' => $resolved['description'], 'due_date' => $resolved['due_date'], 'payer_name' => $resolved['payer_name'], 'has_payer' => $resolved['party_id'] !== null];
        }

        $account = $module->activeAccount($company);

        return Inertia::render('payments/charges/create', [
            'prefill' => $prefill,
            'account' => $account === null ? null : ['provider' => $account->provider, 'environment' => $account->environment, 'name' => $account->name],
            'today' => now($company->timezone)->format('Y-m-d'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $validated = $request->validate([
            'description' => [$request->filled('origin_id') ? 'nullable' : 'required', 'string', 'max:200'],
            'amount' => [$request->filled('origin_id') ? 'nullable' : 'required', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:60'],
            'origin' => ['nullable', Rule::in(['manual', 'sale', 'finance'])],
            'origin_id' => ['nullable', 'integer', 'required_if:origin,sale,finance'],
            'payer_party_id' => ['nullable', 'integer'],
            'payer' => ['nullable', 'array'],
            'payer.name' => ['nullable', 'string', 'max:160'],
            'payer.document' => ['nullable', 'string', 'max:20'],
            'payer.email' => ['nullable', 'email', 'max:160'],
            'payer.phone' => ['nullable', 'string', 'max:30'],
            'issue' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required', 'uuid'],
        ], ['description.required' => 'Descreva a cobrança.', 'amount.required' => 'Informe o valor.', 'amount.gt' => 'Informe um valor maior que zero.', 'due_date.required' => 'Informe o vencimento.']);
        $charge = $this->charges->create($company, $request->user(), $validated);
        $this->toast($charge->status === ChargeStatus::Failed ? "Cobrança #{$charge->number} criada, mas o provedor recusou a emissão: {$charge->failure_reason}" : "Cobrança #{$charge->number} criada.", $charge->status === ChargeStatus::Failed ? 'error' : 'success');

        return to_route('payments.charges.show', $charge);
    }

    public function show(Request $request, PaymentCharge $charge, ProviderRegistry $providers): Response
    {
        $capabilities = $charge->provider === null ? null : $providers->get($charge->provider)->capabilities();

        return Inertia::render('payments/charges/show', [
            'charge' => $this->presenter->charge($charge),
            'pix' => $this->presenter->pix($charge),
            'transactions' => PaymentTransaction::query()->where('payment_charge_id', $charge->id)->orderBy('paid_at')->get()->map(fn (PaymentTransaction $transaction): array => $this->presenter->transaction($transaction))->values()->all(),
            'refunds' => PaymentRefund::query()->where('payment_charge_id', $charge->id)->latest('id')->get()->map(fn (PaymentRefund $refund): array => $this->presenter->refund($refund))->values()->all(),
            'events' => PaymentChargeEvent::query()->where('payment_charge_id', $charge->id)->latest('id')->limit(100)->get()->map(fn (PaymentChargeEvent $event): array => $this->presenter->event($event))->values()->all(),
            'abilities' => [
                'issue' => in_array($charge->status, [ChargeStatus::Draft, ChargeStatus::Failed], true) && $this->can($request, PaymentPermission::Create),
                'sync' => $charge->provider_charge_id !== null && $capabilities?->query === true,
                'cancel' => $charge->status->isOpen() && BigDecimal::of($charge->paid_amount)->isZero() && $this->can($request, PaymentPermission::Cancel) && ($charge->provider_charge_id === null || $capabilities?->cancel === true),
                'refund' => $capabilities?->refund === true && $this->can($request, PaymentPermission::Refund),
            ],
        ]);
    }

    public function issue(Request $request, PaymentCharge $charge): RedirectResponse
    {
        $charge = $this->charges->issue($charge, $request->user());
        $charge->status === ChargeStatus::Failed
            ? $this->toast("O provedor recusou a emissão: {$charge->failure_reason}", 'error')
            : $this->toast('Cobrança emitida.');

        return back();
    }

    public function sync(Request $request, PaymentCharge $charge): RedirectResponse
    {
        try {
            $charge = $this->charges->sync($charge, $request->user());
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages(['charge' => $exception->getMessage()]);
        }

        $this->toast("Situação atualizada: {$charge->status->label()}.");

        return back();
    }

    public function cancel(Request $request, PaymentCharge $charge): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:400']], ['reason.required' => 'Informe o motivo do cancelamento.']);
        $this->charges->cancel($charge, $request->user(), $validated['reason']);
        $this->toast('Cobrança cancelada.');

        return back();
    }

    public function refund(Request $request, PaymentCharge $charge): RedirectResponse
    {
        $validated = $request->validate([
            'payment_transaction_id' => ['required', 'integer'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'reason' => ['required', 'string', 'max:400'],
            'idempotency_key' => ['required', 'uuid'],
        ], ['reason.required' => 'Informe o motivo da devolução.']);
        $transaction = PaymentTransaction::query()->where('payment_charge_id', $charge->id)->findOrFail((int) $validated['payment_transaction_id']);
        $refund = $this->charges->refund($charge, $transaction, $request->user(), (string) $validated['amount'], $validated['reason'], $validated['idempotency_key']);
        $this->toast("Devolução {$refund->status->label()}.");

        return back();
    }

    public function export(Request $request, ChargeQueries $queries, AuditService $audit): StreamedResponse
    {
        $company = $this->company($request);
        $query = $queries->charges($company, $this->filters($request))->orderBy('id');
        $audit->record('payments.charges_exported', $company, $request->user());

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            $csv = new class extends CsvFormat {};
            fwrite($output, CsvFormat::BOM);
            $csv->write($output, ['numero', 'criada_em', 'descricao', 'pagador', 'origem', 'situacao', 'provedor', 'valor', 'recebido', 'devolvido', 'taxas', 'vencimento', 'pago_em', 'referencia']);
            $query->chunk(500, function ($charges) use ($output, $csv): void {
                foreach ($charges as $charge) {
                    $csv->write($output, [
                        $charge->number, $charge->created_at?->format('Y-m-d H:i'), $charge->description, $charge->payer_name, $charge->origin->label(), $charge->status->label(), $charge->provider,
                        CsvFormat::formatDecimal($charge->amount), CsvFormat::formatDecimal($charge->paid_amount), CsvFormat::formatDecimal($charge->refunded_amount), CsvFormat::formatDecimal($charge->fee_amount),
                        $charge->due_date->format('Y-m-d'), $charge->paid_at?->format('Y-m-d H:i'), $charge->reference,
                    ]);
                }
            });
            fclose($output);
        }, 'cobrancas.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{from: string|null, until: string|null, status: string|null, provider: string|null, origin: string|null, search: string|null} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::enum(ChargeStatus::class)],
            'provider' => ['nullable', 'string', 'max:30'],
            'origin' => ['nullable', Rule::enum(ChargeOrigin::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return ['from' => $validated['from'] ?? null, 'until' => $validated['until'] ?? null, 'status' => $validated['status'] ?? null, 'provider' => $validated['provider'] ?? null, 'origin' => $validated['origin'] ?? null, 'search' => $validated['search'] ?? null];
    }
}
