<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Fiscal\Jobs\ProcessFiscalWebhook;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalSetting;
use App\Modules\Fiscal\Models\FiscalWebhookEvent;
use App\Modules\Fiscal\Providers\FiscalProviderRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Notificações dos provedores fiscais. A origem é autenticada pela assinatura (Standard Webhooks na Spedy); cada
 * evento é gravado uma vez por `event_id` e processado na fila. A situação aplicada sempre vem de nova consulta ao
 * provedor com a chave da empresa dona do documento, e o CNPJ informado precisa coincidir com o da empresa.
 */
class FiscalWebhookService
{
    public const RECEIVED = 'received';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public const FAILED = 'failed';

    public function __construct(private FiscalProviderRegistry $providers, private FiscalDocumentService $documents) {}

    /** Nulo: assinatura inválida (a rota responde 401). */
    public function receive(string $provider, Request $request): ?FiscalWebhookEvent
    {
        if (! $this->providers->isAvailable($provider)) {
            return null;
        }

        $parsed = $this->providers->get($provider)->parseWebhook($request);

        if ($parsed === null || $parsed['event_id'] === '') {
            return null;
        }

        $remote = $parsed['document'];
        $document = $remote->reference === null && $parsed['integration_id'] === null ? null : FiscalDocument::query()->where('provider', $provider)
            ->where(fn ($query) => $query->when($remote->reference !== null, fn ($inner) => $inner->orWhere('provider_reference', $remote->reference))
                ->when($parsed['integration_id'] !== null, fn ($inner) => $inner->orWhere('integration_id', $parsed['integration_id'])))
            ->first();
        $status = self::RECEIVED;
        $error = null;
        $documentId = null;

        if ($document === null) {
            [$status, $error] = [self::IGNORED, 'Documento não encontrado.'];
        } elseif ($remote->issuerTaxNumber !== null && $remote->issuerTaxNumber !== FiscalSetting::query()->where('company_id', $document->company_id)->value('cnpj')) {
            [$status, $error] = [self::IGNORED, 'CNPJ do emitente diferente do cadastro da empresa.'];
        } else {
            $documentId = $document->id;
        }

        try {
            $event = FiscalWebhookEvent::create([
                'provider' => $provider,
                'event_id' => Str::limit($parsed['event_id'], 64, ''),
                'event' => Str::limit($parsed['event'], 60, ''),
                'company_id' => $document?->company_id,
                'fiscal_document_id' => $documentId,
                'data' => ['reference' => $remote->reference, 'status' => $remote->providerStatus],
                'status' => $status,
                'error' => $error,
                'processed_at' => $status === self::IGNORED ? now() : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Reentrega (mesmo webhook-id): já gravado.
            return FiscalWebhookEvent::query()->where('provider', $provider)->where('event_id', $parsed['event_id'])->firstOrFail();
        }

        if ($status === self::RECEIVED) {
            ProcessFiscalWebhook::dispatch($event->id);
        }

        return $event;
    }

    public function process(FiscalWebhookEvent $event): void
    {
        $document = $event->fiscal_document_id === null ? null : FiscalDocument::query()->find($event->fiscal_document_id);

        if ($document === null) {
            $event->update(['status' => self::IGNORED, 'error' => 'Documento não encontrado.', 'processed_at' => now()]);

            return;
        }

        $event->increment('attempts');

        try {
            $this->documents->refresh($document);
        } catch (Throwable $exception) {
            $event->update(['status' => self::FAILED, 'error' => Str::limit($exception->getMessage(), 490)]);

            throw $exception;
        }

        $event->update(['status' => self::PROCESSED, 'error' => null, 'processed_at' => now()]);
    }

    public function reprocess(FiscalWebhookEvent $event): void
    {
        $event->update(['status' => self::RECEIVED, 'error' => null]);
        ProcessFiscalWebhook::dispatch($event->id);
    }
}
