<?php

namespace App\Http\Controllers\Payments;

use App\Modules\Core\Services\AuditService;
use App\Modules\Payments\Enums\ChargeOrigin;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Enums\WebhookStatus;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Services\WebhookService;
use App\Modules\Payments\Support\PaymentPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Conciliação: eventos de webhook (sem o conteúdo bruto), cobranças em aberto, pagamentos de PDV sem venda
 * concluída e reprocessamento autorizado.
 */
class PaymentReconciliationController extends PaymentsController
{
    public function __construct(private WebhookService $webhooks, private PaymentPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);

        return Inertia::render('payments/reconciliation', [
            'events' => PaymentWebhookEvent::query()->where('company_id', $company->id)->latest('id')->paginate(25)
                ->through(fn (PaymentWebhookEvent $event): array => $this->presenter->webhook($event)),
            'open' => PaymentCharge::query()->where('company_id', $company->id)->whereIn('status', [ChargeStatus::Pending, ChargeStatus::Processing, ChargeStatus::PartiallyPaid, ChargeStatus::Overdue])
                ->orderBy('last_synced_at')->limit(30)->get()->map(fn (PaymentCharge $charge): array => $this->presenter->charge($charge))->values()->all(),
            'unmatched' => PaymentCharge::query()->where('company_id', $company->id)->where('origin', ChargeOrigin::Pos)->where('paid_amount', '>', 0)->whereNull('origin_label')
                ->latest('id')->limit(30)->get()->map(fn (PaymentCharge $charge): array => $this->presenter->charge($charge))->values()->all(),
            'failedCount' => PaymentWebhookEvent::query()->where('company_id', $company->id)->where('status', WebhookStatus::Failed)->count(),
        ]);
    }

    public function reconcile(Request $request, AuditService $audit): RedirectResponse
    {
        $company = $this->company($request);
        $result = $this->webhooks->reconcile($company->id, 100);
        $audit->record('payments.reconciled', $company, $request->user(), null, $result);
        $this->toast("Conciliação concluída: {$result['synced']} consultada(s), {$result['failed']} com falha, {$result['overdue']} marcada(s) como vencida(s).", $result['failed'] > 0 ? 'error' : 'success');

        return back();
    }

    public function reprocess(Request $request, PaymentWebhookEvent $event, AuditService $audit): RedirectResponse
    {
        abort_unless($event->company_id === $this->company($request)->id, 404);
        $this->webhooks->reprocess($event);
        $audit->record('payments.webhook_reprocessed', $this->company($request), $request->user(), $event);
        $this->toast('Evento enviado para reprocessamento.');

        return back();
    }
}
