<?php

namespace App\Http\Controllers\Fiscal;

use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Enums\FiscalPermission;
use App\Modules\Fiscal\Enums\FiscalStatus;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalWebhookEvent;
use App\Modules\Fiscal\Services\FiscalDocumentService;
use App\Modules\Fiscal\Services\FiscalModule;
use App\Modules\Fiscal\Services\FiscalQueries;
use App\Modules\Fiscal\Services\FiscalSettingsService;
use App\Modules\Fiscal\Services\FiscalWebhookService;
use App\Modules\Fiscal\Support\FiscalPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel fiscal: indicadores do mês, pendências de configuração por tipo, documentos que pedem atenção e
 * notificações com falha. Também dispara a conciliação e o reprocessamento autorizados.
 */
class FiscalDashboardController extends FiscalController
{
    public function index(Request $request, FiscalModule $module, FiscalQueries $queries, FiscalSettingsService $settings, FiscalPresenter $presenter): Response
    {
        $company = $this->company($request);
        $setting = $module->settings($company);
        $now = CarbonImmutable::now($company->timezone);

        return Inertia::render('fiscal/dashboard', [
            'indicators' => $queries->indicators($company, $now->startOfMonth(), $now->endOfMonth()),
            'statuses' => FiscalStatus::options(),
            'readiness' => array_map(fn (DocumentType $type): array => ['type' => $type->value, 'label' => $type->label(), 'enabled' => $setting->enables($type), 'blockers' => $settings->blockers($setting, $type)], DocumentType::cases()),
            'environment' => $setting->environment,
            'attention' => FiscalDocument::query()->where('company_id', $company->id)
                ->whereIn('status', [FiscalStatus::Rejected, FiscalStatus::Denied, FiscalStatus::CommunicationFailure, FiscalStatus::Processing, FiscalStatus::Queued, FiscalStatus::CancelRequested])
                ->latest('updated_at')->limit(10)->get()->map(fn (FiscalDocument $document): array => $presenter->document($document))->values()->all(),
            'failedEvents' => $this->can($request, FiscalPermission::Reprocess) ? FiscalWebhookEvent::query()->where('company_id', $company->id)->where('status', FiscalWebhookService::FAILED)->latest('id')->limit(20)->get()
                ->map(fn (FiscalWebhookEvent $event): array => ['id' => $event->id, 'event' => $event->event, 'attempts' => $event->attempts, 'error' => $event->error, 'created_at' => $event->created_at?->toIso8601String()])->values()->all() : [],
            'abilities' => [
                'create' => $this->can($request, FiscalPermission::CreateDraft),
                'settings' => $this->can($request, FiscalPermission::ManageSettings),
                'reprocess' => $this->can($request, FiscalPermission::Reprocess),
                'reports' => $this->can($request, FiscalPermission::ViewReports),
            ],
        ]);
    }

    public function reconcile(Request $request, FiscalDocumentService $documents): RedirectResponse
    {
        $company = $this->company($request);
        $changed = 0;

        foreach (FiscalDocument::query()->where('company_id', $company->id)->whereIn('status', [FiscalStatus::Processing, FiscalStatus::CancelRequested, FiscalStatus::CommunicationFailure])->whereNotNull('submitted_at')->limit(50)->get() as $document) {
            $before = $document->status;
            $changed += $documents->refresh($document)->status !== $before ? 1 : 0;
        }

        $this->toast("Conciliação concluída: {$changed} documento(s) atualizado(s).");

        return back();
    }

    public function reprocess(Request $request, FiscalWebhookEvent $event, FiscalWebhookService $webhooks): RedirectResponse
    {
        abort_if($event->company_id !== $this->company($request)->id, 404);
        $webhooks->reprocess($event);
        $this->toast('Notificação enviada para reprocessamento.');

        return back();
    }
}
