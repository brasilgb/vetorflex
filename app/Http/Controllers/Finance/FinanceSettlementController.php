<?php

namespace App\Http\Controllers\Finance;

use App\Http\Requests\Finance\FinanceReasonRequest;
use App\Http\Requests\Finance\SettleFinanceTitleRequest;
use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Services\FinanceSettlementService;
use Illuminate\Http\RedirectResponse;

/**
 * Recebimentos, pagamentos e estornos. A permissão de baixa depende do tipo do título.
 */
class FinanceSettlementController extends FinanceController
{
    public function __construct(private FinanceSettlementService $settlements) {}

    public function store(SettleFinanceTitleRequest $request, FinanceTitle $title): RedirectResponse
    {
        $this->authorizeFinance($request, FinancePermission::settleFor($title->kind));
        $this->settlements->settle($title, $request->user(), $request->validated());
        $this->toast(($title->kind === TitleKind::Receivable ? 'Recebimento' : 'Pagamento').' registrado.');

        return back();
    }

    public function reverse(FinanceReasonRequest $request, FinanceSettlement $settlement): RedirectResponse
    {
        $this->authorizeFinance($request, FinancePermission::ReverseSettlements);
        $this->settlements->reverse($settlement, $request->user(), $request->validated('reason'));
        $this->toast('Baixa estornada. O histórico original foi preservado.');

        return back();
    }
}
