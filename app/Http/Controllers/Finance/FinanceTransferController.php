<?php

namespace App\Http\Controllers\Finance;

use App\Http\Requests\Finance\FinanceReasonRequest;
use App\Modules\Finance\Models\FinanceTransfer;
use App\Modules\Finance\Services\FinanceTransferService;
use App\Modules\Finance\Support\FinancePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Transferências entre contas da empresa. Não entram em receitas ou despesas.
 */
class FinanceTransferController extends FinanceController
{
    public function __construct(private FinanceTransferService $transfers, private FinancePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);

        return Inertia::render('finance/transfers', [
            'transfers' => FinanceTransfer::query()->where('company_id', $company->id)->with(['from', 'to'])->orderByDesc('transferred_at')->orderByDesc('id')
                ->paginate(20)->withQueryString()->through(fn (FinanceTransfer $transfer): array => $this->presenter->transfer($transfer)),
            'accounts' => $this->activeAccounts($company),
            'today' => $this->today($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from_account_id' => ['required', 'integer'],
            'to_account_id' => ['required', 'integer', 'different:from_account_id'],
            'amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'transferred_at' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ], [
            'from_account_id.required' => 'Selecione a conta de origem.',
            'to_account_id.required' => 'Selecione a conta de destino.',
            'to_account_id.different' => 'A conta de destino deve ser diferente da origem.',
            'amount.required' => 'Informe o valor.',
            'amount.regex' => 'Informe um valor válido, com até duas casas decimais.',
        ]);
        $this->transfers->transfer($this->company($request), $request->user(), $validated);
        $this->toast('Transferência registrada.');

        return back();
    }

    public function reverse(FinanceReasonRequest $request, FinanceTransfer $transfer): RedirectResponse
    {
        $this->transfers->reverse($transfer, $request->user(), $request->validated('reason'));
        $this->toast('Transferência estornada.');

        return back();
    }
}
