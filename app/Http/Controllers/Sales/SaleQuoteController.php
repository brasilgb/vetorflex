<?php

namespace App\Http\Controllers\Sales;

use App\Http\Requests\Sales\SaleReasonRequest;
use App\Http\Requests\Sales\SaveSaleQuoteRequest;
use App\Modules\Sales\Enums\SaleQuoteStatus;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Sales\Services\SaleQuoteService;
use App\Modules\Sales\Services\SaleSearch;
use App\Modules\Sales\Support\SalePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleQuoteController extends SalesController
{
    public function __construct(
        private SaleQuoteService $quotes,
        private SaleSearch $search,
        private SalePresenter $presenter,
        private SaleFormOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $today = $this->today($request);

        return Inertia::render('sales/quotes/index', [
            'quotes' => $this->search->quotes($company, $request)->orderByDesc('issue_date')->orderByDesc('id')
                ->paginate(15)->withQueryString()
                ->through(fn (SaleQuote $quote): array => $this->presenter->quoteSummary($quote, $today)),
            'filters' => $request->only(SaleSearch::QUOTE_FILTERS),
            'statuses' => SaleQuoteStatus::options(),
            'sellers' => $this->sellers($company),
            'selectedCustomer' => $this->options->selectedCustomer($company, $request->integer('customer')),
            'selectedProduct' => $this->options->selectedProduct($company, $request->integer('product')),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('sales/quotes/form', [...$this->options->for($request), 'quote' => null]);
    }

    public function store(SaveSaleQuoteRequest $request): RedirectResponse
    {
        $quote = $this->quotes->create($this->company($request), $request->user(), $request->validated(), $this->abilities($request));
        $this->toast("Orçamento #{$quote->number} salvo como rascunho.");

        return to_route('sales.quotes.show', $quote);
    }

    public function show(Request $request, SaleQuote $quote): Response
    {
        $quote->load(['items', 'paymentSchedules', 'statusHistory', 'order']);

        return Inertia::render('sales/quotes/show', [
            'quote' => $this->presenter->quote($quote, $this->today($request)),
            'today' => $this->today($request),
        ]);
    }

    public function edit(Request $request, SaleQuote $quote): Response|RedirectResponse
    {
        if ($quote->status !== SaleQuoteStatus::Draft) {
            return to_route('sales.quotes.show', $quote);
        }

        $quote->load(['items', 'paymentSchedules', 'statusHistory', 'order', 'customer.addresses']);

        return Inertia::render('sales/quotes/form', [
            ...$this->options->for($request, $quote->customer),
            'quote' => $this->presenter->quote($quote, $this->today($request)),
        ]);
    }

    public function update(SaveSaleQuoteRequest $request, SaleQuote $quote): RedirectResponse
    {
        $this->quotes->update($quote, $request->user(), $request->validated(), $this->abilities($request));
        $this->toast("Orçamento #{$quote->number} atualizado.");

        return to_route('sales.quotes.show', $quote);
    }

    public function issue(Request $request, SaleQuote $quote): RedirectResponse
    {
        $this->quotes->issue($quote, $request->user(), $this->today($request));
        $this->toast("Orçamento #{$quote->number} emitido.");

        return back();
    }

    public function approve(Request $request, SaleQuote $quote): RedirectResponse
    {
        $this->quotes->approve($quote, $request->user(), $this->today($request), $request->string('reason')->trim()->value() ?: null);
        $this->toast("Orçamento #{$quote->number} aprovado.");

        return back();
    }

    public function reject(SaleReasonRequest $request, SaleQuote $quote): RedirectResponse
    {
        $this->quotes->reject($quote, $request->user(), $this->today($request), $request->validated('reason'));
        $this->toast("Orçamento #{$quote->number} recusado.");

        return back();
    }

    public function cancel(SaleReasonRequest $request, SaleQuote $quote): RedirectResponse
    {
        $this->quotes->cancel($quote, $request->user(), $request->validated('reason'));
        $this->toast("Orçamento #{$quote->number} cancelado.");

        return back();
    }

    public function convert(Request $request, SaleQuote $quote): RedirectResponse
    {
        $order = $this->quotes->convert($quote, $request->user());
        $this->toast("Venda #{$order->number} criada a partir do orçamento #{$quote->number}.");

        return to_route('sales.orders.show', $order);
    }
}
