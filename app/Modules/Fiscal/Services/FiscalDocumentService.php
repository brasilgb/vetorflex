<?php

namespace App\Modules\Fiscal\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Fiscal\Data\ProviderDocument;
use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Enums\FiscalOrigin;
use App\Modules\Fiscal\Enums\FiscalStatus;
use App\Modules\Fiscal\Jobs\StoreFiscalDocumentFiles;
use App\Modules\Fiscal\Jobs\TransmitFiscalDocument;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalDocumentEvent;
use App\Modules\Fiscal\Models\FiscalSetting;
use App\Modules\Fiscal\Providers\FiscalProvider;
use App\Modules\Fiscal\Providers\FiscalProviderException;
use App\Modules\Fiscal\Providers\FiscalProviderRegistry;
use App\Modules\Fiscal\Providers\FiscalValidationException;
use App\Modules\Parties\Models\Party;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida do documento fiscal: rascunho, pré-visualização (validação), transmissão assíncrona, consulta,
 * cancelamento e conciliação.
 *
 * - Salvar nunca transmite: a emissão é sempre uma ação explícita (`issue`).
 * - O `integration_id` é gerado na criação e enviado em toda transmissão: reenvios por falha ou rejeição atualizam o
 *   mesmo documento no provedor. Antes de reenviar após falha de comunicação, o provedor é consultado.
 * - A situação só avança: retornos antigos ou fora de ordem são ignorados (exceto rejeitado → processando, reenvio).
 * - Nenhum efeito em vendas, estoque, caixa ou financeiro. O documento não é uma nova venda.
 */
class FiscalDocumentService
{
    private const ITEM_FIELDS = ['product_variant_id', 'code', 'description', 'quantity', 'unit', 'unit_price', 'discount', 'gtin', 'is_service', 'ncm', 'cest', 'cfop', 'origin', 'icms_situation', 'pis_situation', 'cofins_situation', 'service_code', 'tax_unit'];

    public function __construct(
        private FiscalModule $module,
        private FiscalOrigins $origins,
        private FiscalSettingsService $settings,
        private FiscalProviderRegistry $providers,
        private SequenceService $sequences,
        private AuditService $audit,
    ) {}

    /**
     * Dados: `type`, `origin` e `origin_id` (opcionais), `receiver_party_id` ou `receiver`, `items`, `description`,
     * `notes`, `payment_method`, `tax_data` e `idempotency_key`. Com origem, os dados ausentes vêm da origem.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDraft(Company $company, ?User $user, array $data): FiscalDocument
    {
        $existing = FiscalDocument::query()->where('company_id', $company->id)->where('idempotency_key', (string) $data['idempotency_key'])->first();

        if ($existing !== null) {
            return $existing;
        }

        $origin = FiscalOrigin::from((string) ($data['origin'] ?? FiscalOrigin::Manual->value));
        $resolved = $origin === FiscalOrigin::Manual ? null : $this->origins->resolve($company, $origin, (int) $data['origin_id']);
        $type = DocumentType::from((string) ($data['type'] ?? $resolved['suggested_type']->value ?? DocumentType::Nfse->value));
        $setting = $this->module->settings($company);

        try {
            return DB::transaction(function () use ($company, $user, $data, $origin, $resolved, $type, $setting): FiscalDocument {
                if ($resolved !== null) {
                    $this->guardOrigin($company, $resolved['origin_type'], $resolved['origin_id'], $type);
                }

                $receiverPartyId = $data['receiver_party_id'] ?? $resolved['receiver_party_id'] ?? null;
                $document = new FiscalDocument([
                    'company_id' => $company->id,
                    'local_number' => $this->sequences->next($company, 'fiscal_document'),
                    'type' => $type,
                    'status' => FiscalStatus::Draft,
                    'origin' => $origin,
                    'origin_type' => $resolved['origin_type'] ?? null,
                    'origin_id' => $resolved['origin_id'] ?? null,
                    'origin_label' => $resolved['origin_label'] ?? null,
                    'provider' => $setting->provider ?? 'spedy',
                    'environment' => $setting->environment,
                    'integration_id' => (string) Str::uuid(),
                    'idempotency_key' => (string) $data['idempotency_key'],
                    'created_by' => $user?->id,
                    'created_by_name' => $user?->name,
                ]);
                $this->fillContent($company, $document, [
                    'receiver_party_id' => $receiverPartyId,
                    'receiver' => $data['receiver'] ?? $resolved['receiver'] ?? null,
                    'items' => $data['items'] ?? $resolved['items'] ?? [],
                    'description' => $data['description'] ?? $resolved['description'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'payment_method' => $data['payment_method'] ?? $resolved['payment_method'] ?? null,
                    'tax_data' => $data['tax_data'] ?? null,
                ]);
                $document->save();
                $this->event($document, 'created', "Rascunho de {$type->shortLabel()} criado".($document->origin_label ? " a partir de {$document->origin_label}" : ' (avulso)').'.', null, FiscalStatus::Draft, $user);
                $this->audit->record('fiscal.document_created', $company, $user, $document, ['type' => $type->value, 'origin' => $origin->value, 'local_number' => $document->local_number]);

                return $document;
            });
        } catch (UniqueConstraintViolationException) {
            return FiscalDocument::query()->where('company_id', $company->id)->where('idempotency_key', (string) $data['idempotency_key'])->firstOrFail();
        }
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(FiscalDocument $document, User $user, array $data): FiscalDocument
    {
        return DB::transaction(function () use ($document, $user, $data): FiscalDocument {
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! $document->status->isEditable()) {
                throw ValidationException::withMessages(['document' => "Documento \"{$document->status->label()}\" não pode ser alterado."]);
            }

            $company = Company::query()->findOrFail($document->company_id);
            $this->fillContent($company, $document, $data);
            $document->save();
            $this->event($document, 'updated', 'Dados do documento alterados.', null, null, $user);

            return $document;
        });
    }

    /** Exclui um rascunho nunca transmitido. */
    public function deleteDraft(FiscalDocument $document, User $user): void
    {
        if ($document->status !== FiscalStatus::Draft || $document->submitted_at !== null) {
            throw ValidationException::withMessages(['document' => 'Somente rascunhos nunca transmitidos podem ser excluídos.']);
        }

        $this->audit->record('fiscal.document_deleted', Company::query()->findOrFail($document->company_id), $user, $document, ['local_number' => $document->local_number]);
        $document->delete();
    }

    /**
     * Pré-visualização: pendências de configuração e dados que o provedor recusaria, sem transmitir nada.
     *
     * @return array{blockers: list<string>, problems: list<string>, ready: bool}
     */
    public function preview(FiscalDocument $document): array
    {
        $setting = $this->setting($document);
        $blockers = $this->settings->blockers($setting, $document->type);
        $problems = [];

        try {
            $this->providerFor($document)->payload($document, $setting);
        } catch (FiscalValidationException $exception) {
            $problems = array_values($exception->errors()['fiscal'] ?? []);
        } catch (ValidationException $exception) {
            $problems = array_values(collect($exception->errors())->flatten()->all());
        }

        return ['blockers' => $blockers, 'problems' => $problems, 'ready' => $blockers === [] && $problems === []];
    }

    /** Solicita a emissão: valida, coloca na fila e transmite em segundo plano. */
    public function issue(FiscalDocument $document, User $user): FiscalDocument
    {
        $setting = $this->setting($document);
        $blockers = $this->settings->blockers($setting, $document->type);

        if ($blockers !== []) {
            throw ValidationException::withMessages(['fiscal' => $blockers]);
        }

        // Falha de comunicação: o envio anterior pode ter chegado. Consulta antes de reenviar.
        if ($document->status === FiscalStatus::CommunicationFailure) {
            $document = $this->refresh($document, $user);

            if ($document->status !== FiscalStatus::CommunicationFailure) {
                return $document;
            }
        }

        $this->providerFor($document)->payload($document, $setting);

        return DB::transaction(function () use ($document, $user, $setting): FiscalDocument {
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! $document->status->isEditable() && $document->status !== FiscalStatus::CommunicationFailure) {
                return $document;
            }

            if ($document->origin_type !== null && $document->submitted_at === null) {
                $this->guardOrigin(Company::query()->findOrFail($document->company_id), $document->origin_type, (int) $document->origin_id, $document->type, $document->id);
            }

            $from = $document->status;
            $document->update(['status' => FiscalStatus::Queued, 'provider' => $setting->provider ?? 'spedy', 'environment' => $setting->environment, 'error_message' => null]);
            $this->event($document, 'issue_requested', 'Emissão solicitada.', $from, FiscalStatus::Queued, $user);
            $this->audit->record('fiscal.issue_requested', Company::query()->findOrFail($document->company_id), $user, $document, ['type' => $document->type->value, 'local_number' => $document->local_number]);
            $documentId = $document->id;
            DB::afterCommit(fn () => TransmitFiscalDocument::dispatch($documentId));

            return $document;
        });
    }

    /** Transmissão (fila). Segura para repetir: só age sobre documentos aguardando e reenvia com o mesmo identificador. */
    public function transmit(FiscalDocument $document): FiscalDocument
    {
        $setting = $this->setting($document);
        $provider = $this->providerFor($document);

        /** @var array{0: FiscalDocument, 1: array<string, mixed>|null} $claimed */
        $claimed = DB::transaction(function () use ($document, $setting, $provider): array {
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);

            // Somente quem tira o documento de "Aguardando" transmite: execuções repetidas da fila não reenviam.
            if ($document->status !== FiscalStatus::Queued) {
                return [$document, null];
            }

            try {
                $payload = $provider->payload($document, $setting);
            } catch (FiscalValidationException $exception) {
                $message = implode(' ', array_values($exception->errors()['fiscal'] ?? []));
                $document->update(['status' => FiscalStatus::Rejected, 'error_message' => Str::limit($message, 990)]);
                $this->event($document, 'validation_failed', "Dados insuficientes: {$message}", FiscalStatus::Queued, FiscalStatus::Rejected, null);

                return [$document, null];
            }

            $document->update(['status' => FiscalStatus::Processing, 'submitted_at' => now(), 'request_summary' => $this->summary($payload)]);

            return [$document, $payload];
        });
        [$document, $payload] = $claimed;

        if ($payload === null) {
            return $document;
        }

        try {
            $remote = $provider->transmit($setting, $document, $payload);
        } catch (FiscalProviderException $exception) {
            return DB::transaction(function () use ($document, $exception): FiscalDocument {
                $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);

                if ($document->status !== FiscalStatus::Processing) {
                    return $document;
                }

                // Transitória: pode ter sido recebido; a conciliação consulta pelo identificador. Definitiva: corrigir.
                $status = $exception->isTransient() ? FiscalStatus::CommunicationFailure : FiscalStatus::Rejected;
                $document->update(['status' => $status, 'error_message' => Str::limit($exception->getMessage(), 990)]);
                $this->event($document, $exception->isTransient() ? 'communication_failed' : 'provider_refused', $exception->getMessage(), FiscalStatus::Processing, $status, null);

                return $document;
            });
        }

        return DB::transaction(fn (): FiscalDocument => $this->apply(FiscalDocument::query()->lockForUpdate()->findOrFail($document->id), $remote, null, 'transmissão'));
    }

    /** Consulta o provedor (sem acionar a autoridade fiscal) e aplica a situação. */
    public function refresh(FiscalDocument $document, ?User $user = null): FiscalDocument
    {
        if ($document->status === FiscalStatus::Draft || $document->submitted_at === null) {
            return $document;
        }

        $setting = $this->setting($document);

        try {
            $remote = $this->providerFor($document)->find($setting, $document);
        } catch (FiscalProviderException $exception) {
            if ($user !== null) {
                throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
            }

            return $document;
        }

        return DB::transaction(function () use ($document, $remote, $user): FiscalDocument {
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($remote === null) {
                $limit = (int) config('fiscal.unconfirmed_after_minutes', 30);

                // Não chegou ao provedor dentro do prazo: liberado para reenvio com o mesmo identificador.
                if ($document->status === FiscalStatus::Processing && $document->provider_reference === null && $document->submitted_at?->lt(now()->subMinutes($limit))) {
                    $document->update(['status' => FiscalStatus::CommunicationFailure, 'error_message' => 'O provedor não confirmou o recebimento. Reenvie o documento.']);
                    $this->event($document, 'unconfirmed', 'Envio não confirmado pelo provedor.', FiscalStatus::Processing, FiscalStatus::CommunicationFailure, $user);
                }

                return $document;
            }

            return $this->apply($document, $remote, $user, 'consulta');
        });
    }

    public function cancel(FiscalDocument $document, User $user, string $reason): FiscalDocument
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 15 || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'A justificativa do cancelamento deve ter entre 15 e 255 caracteres.']);
        }

        $provider = $this->providerFor($document);

        if (! $provider->capabilities()->cancel) {
            throw ValidationException::withMessages(['document' => "{$provider->name()} não permite cancelar pela integração."]);
        }

        $document = DB::transaction(function () use ($document, $user, $reason): FiscalDocument {
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($document->status !== FiscalStatus::Authorized || $document->provider_reference === null) {
                throw ValidationException::withMessages(['document' => 'Somente documentos autorizados podem ser cancelados.']);
            }

            $document->update(['status' => FiscalStatus::CancelRequested, 'cancel_reason' => $reason]);
            $this->event($document, 'cancel_requested', "Cancelamento solicitado: {$reason}", FiscalStatus::Authorized, FiscalStatus::CancelRequested, $user);
            $this->audit->record('fiscal.cancel_requested', Company::query()->findOrFail($document->company_id), $user, $document, ['local_number' => $document->local_number]);

            return $document;
        });

        try {
            $remote = $provider->cancel($this->setting($document), $document, $reason);
        } catch (FiscalProviderException $exception) {
            if ($exception->isTransient()) {
                // Pode ter sido aceito: a consulta e a conciliação confirmam. A situação fica "Cancelamento solicitado".
                $this->event($document, 'cancel_unconfirmed', "Cancelamento sem confirmação: {$exception->getMessage()}", null, null, $user);

                return $document;
            }

            DB::transaction(function () use ($document, $exception, $user): void {
                $locked = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);
                $locked->update(['status' => FiscalStatus::Authorized, 'cancel_reason' => null]);
                $this->event($locked, 'cancel_refused', "Cancelamento recusado: {$exception->getMessage()}", FiscalStatus::CancelRequested, FiscalStatus::Authorized, $user);
            });

            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return DB::transaction(fn (): FiscalDocument => $this->apply(FiscalDocument::query()->lockForUpdate()->findOrFail($document->id), $remote, $user, 'cancelamento'));
    }

    /**
     * Conciliação periódica: consulta documentos pendentes sem atualização recente e recoloca na fila os que ficaram
     * aguardando sem processamento.
     *
     * @return array{checked: int, changed: int, requeued: int}
     */
    public function reconcile(int $limit = 100): array
    {
        $checked = $changed = $requeued = 0;
        $stale = FiscalDocument::query()->where('status', FiscalStatus::Queued)->where('updated_at', '<', now()->subMinutes(10))->limit($limit)->pluck('id');

        foreach ($stale as $id) {
            TransmitFiscalDocument::dispatch((int) $id);
            $requeued++;
        }

        $pending = FiscalDocument::query()
            ->whereIn('status', [FiscalStatus::Processing, FiscalStatus::CancelRequested, FiscalStatus::CommunicationFailure])
            ->whereNotNull('submitted_at')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->orderBy('updated_at')->limit($limit)->get();

        foreach ($pending as $document) {
            $checked++;
            $before = $document->status;

            if ($this->refresh($document)->status !== $before) {
                $changed++;
            }
        }

        return ['checked' => $checked, 'changed' => $changed, 'requeued' => $requeued];
    }

    /**
     * Aplica a situação informada pelo provedor (transmissão, consulta, cancelamento ou webhook). Chamar dentro de
     * transação, com o documento travado.
     */
    public function apply(FiscalDocument $document, ProviderDocument $remote, ?User $user, string $source): FiscalDocument
    {
        $from = $document->status;
        $to = $remote->status;
        $fields = array_filter([
            'provider_reference' => $document->provider_reference ?? $remote->reference,
            'provider_status' => $remote->providerStatus !== '' ? $remote->providerStatus : null,
            'number' => $remote->number,
            'series' => $remote->series,
            'access_key' => $remote->accessKey,
            'authorization_protocol' => $remote->protocol,
            'response_summary' => $remote->summary !== [] ? $remote->summary : null,
        ], fn (mixed $value): bool => $value !== null);

        if ($to === null || ! $this->advances($from, $to, $remote)) {
            $document->update($fields);

            return $document;
        }

        // Cancelamento recusado pela autoridade: a nota segue autorizada.
        if ($from === FiscalStatus::CancelRequested && $to === FiscalStatus::Authorized) {
            if (($remote->summary['processingDetail']['status'] ?? null) !== 'failed') {
                $document->update($fields);

                return $document;
            }

            $message = (string) ($remote->summary['processingDetail']['message'] ?? 'Cancelamento não aceito.');
            $document->update([...$fields, 'status' => FiscalStatus::Authorized, 'cancel_reason' => null, 'error_message' => Str::limit($message, 990)]);
            $this->event($document, 'cancel_refused', "Cancelamento recusado: {$message}", $from, $to, $user);

            return $document;
        }

        $document->update([
            ...$fields,
            'status' => $to,
            'issued_at' => $to === FiscalStatus::Authorized ? ($remote->issuedAt ?? now()) : $document->issued_at,
            'cancelled_at' => $to === FiscalStatus::Cancelled ? ($remote->cancelledAt ?? now()) : $document->cancelled_at,
            'error_message' => in_array($to, [FiscalStatus::Rejected, FiscalStatus::Denied, FiscalStatus::CommunicationFailure], true) ? $remote->errorMessage : null,
        ]);
        $description = match ($to) {
            FiscalStatus::Authorized => 'Autorizado'.($remote->number ? " — número {$remote->number}" : '').($remote->series ? ", série {$remote->series}" : '').'.',
            FiscalStatus::Rejected => 'Rejeitado: '.($remote->errorMessage ?? 'motivo não informado.'),
            FiscalStatus::Denied => 'Denegado: '.($remote->errorMessage ?? 'motivo não informado.'),
            FiscalStatus::Cancelled => 'Cancelamento homologado.',
            default => "Situação: {$to->label()}.",
        };
        $this->event($document, 'status_changed', "{$description} (origem: {$source})", $from, $to, $user);
        $this->audit->record('fiscal.status_changed', Company::query()->findOrFail($document->company_id), $user, $document, ['from' => $from->value, 'to' => $to->value, 'source' => $source]);

        if (in_array($to, [FiscalStatus::Authorized, FiscalStatus::Cancelled], true)) {
            $documentId = $document->id;
            DB::afterCommit(fn () => StoreFiscalDocumentFiles::dispatch($documentId));
        }

        return $document;
    }

    /** Documento ativo da mesma origem e tipo impede outro (o rejeitado deve ser corrigido e reenviado). */
    private function guardOrigin(Company $company, string $originType, int $originId, DocumentType $type, ?int $ignoreId = null): void
    {
        $active = FiscalDocument::query()->where('company_id', $company->id)->where('origin_type', $originType)->where('origin_id', $originId)->where('type', $type)
            ->whereNotIn('status', [FiscalStatus::Cancelled, FiscalStatus::Denied])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->lockForUpdate()->first();

        if ($active !== null) {
            throw ValidationException::withMessages(['origin_id' => "Já existe a {$type->shortLabel()} nº interno {$active->local_number} ({$active->status->label()}) para esta origem. Consulte o documento existente."]);
        }
    }

    private function advances(FiscalStatus $from, FiscalStatus $to, ProviderDocument $remote): bool
    {
        if ($from === $to) {
            return false;
        }

        // Reenvio de rejeitado ou falha de comunicação: volta a processar.
        if (in_array($from, [FiscalStatus::Rejected, FiscalStatus::CommunicationFailure, FiscalStatus::Queued], true)) {
            return true;
        }

        if ($from === FiscalStatus::CancelRequested && $to === FiscalStatus::Authorized) {
            return true;
        }

        return $to->rank() > $from->rank();
    }

    /** @param array<string, mixed> $data */
    private function fillContent(Company $company, FiscalDocument $document, array $data): void
    {
        $partyId = array_key_exists('receiver_party_id', $data) ? $data['receiver_party_id'] : $document->receiver_party_id;
        $receiver = $data['receiver'] ?? $document->receiver;

        if ($partyId !== null && $partyId !== '' && (int) $partyId !== $document->receiver_party_id) {
            $party = Party::query()->where('company_id', $company->id)->find((int) $partyId) ?? throw ValidationException::withMessages(['receiver_party_id' => 'Destinatário não encontrado.']);
            $receiver = [...$this->origins->receiverFromParty($party), ...array_filter((array) ($data['receiver'] ?? []), fn (mixed $value): bool => $value !== null && $value !== '')];
        }

        $items = array_values(array_map(fn (array $item): array => $this->normalizeItem($item), (array) ($data['items'] ?? $document->items ?? [])));
        $itemsTotal = BigDecimal::zero();
        $discountTotal = BigDecimal::zero();

        foreach ($items as $item) {
            $itemsTotal = $itemsTotal->plus(BigDecimal::of($item['quantity'])->multipliedBy($item['unit_price'])->toScale(2, RoundingMode::HalfUp));
            $discountTotal = $discountTotal->plus($item['discount']);
        }

        if ($discountTotal->isGreaterThan($itemsTotal)) {
            throw ValidationException::withMessages(['items' => 'Os descontos superam o valor dos itens.']);
        }

        $document->fill([
            'receiver_party_id' => $partyId === '' || $partyId === null ? null : (int) $partyId,
            'receiver' => $receiver === null ? null : $this->normalizeReceiver((array) $receiver),
            'items' => $items,
            'description' => array_key_exists('description', $data) ? $data['description'] : $document->description,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $document->notes,
            'payment_method' => array_key_exists('payment_method', $data) ? $data['payment_method'] : $document->payment_method,
            'tax_data' => array_key_exists('tax_data', $data) ? $data['tax_data'] : $document->tax_data,
            'items_total' => CommercialMath::money($itemsTotal),
            'discount_total' => CommercialMath::money($discountTotal),
            'total' => CommercialMath::money($itemsTotal->minus($discountTotal)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $item = collect($item)->only(self::ITEM_FIELDS)->all();
        $item['description'] = trim((string) ($item['description'] ?? ''));
        $item['quantity'] = (string) BigDecimal::of((string) (($item['quantity'] ?? null) ?: '1'))->toScale(4, RoundingMode::HalfUp);
        $item['unit_price'] = CommercialMath::money((string) (($item['unit_price'] ?? null) ?: '0'));
        $item['discount'] = CommercialMath::money((string) (($item['discount'] ?? null) ?: '0'));
        $item['is_service'] = (bool) ($item['is_service'] ?? false);

        foreach (['ncm', 'cest', 'cfop', 'gtin'] as $digits) {
            $item[$digits] = filled($item[$digits] ?? null) ? preg_replace('/\D+/', '', (string) $item[$digits]) : null;
        }

        return array_filter($item, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $receiver
     * @return array<string, mixed>
     */
    private function normalizeReceiver(array $receiver): array
    {
        $address = array_filter((array) ($receiver['address'] ?? []), fn (mixed $value): bool => $value !== null && $value !== '');

        return array_filter([
            'name' => filled($receiver['name'] ?? null) ? trim((string) $receiver['name']) : null,
            'document' => preg_replace('/\D+/', '', (string) ($receiver['document'] ?? '')) ?: null,
            'state_registration' => $receiver['state_registration'] ?? null,
            'municipal_registration' => $receiver['municipal_registration'] ?? null,
            'email' => $receiver['email'] ?? null,
            'phone' => $receiver['phone'] ?? null,
            'address' => $address === [] ? null : $address,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Resumo da solicitação para suporte: estrutura e totais, sem documento, contato ou endereço do destinatário.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function summary(array $payload): array
    {
        return [
            'integrationId' => $payload['integrationId'] ?? null,
            'fields' => array_keys($payload),
            'items' => is_array($payload['items'] ?? null) ? count($payload['items']) : null,
            'total' => $payload['total'] ?? ($payload['payments'][0]['amount'] ?? null),
            'has_receiver' => isset($payload['receiver']),
        ];
    }

    private function setting(FiscalDocument $document): FiscalSetting
    {
        return FiscalSetting::query()->where('company_id', $document->company_id)->first()
            ?? throw ValidationException::withMessages(['fiscal' => 'Configure os dados fiscais da empresa.']);
    }

    private function providerFor(FiscalDocument $document): FiscalProvider
    {
        return $this->providers->get($document->provider ?? 'spedy');
    }

    private function event(FiscalDocument $document, string $event, string $description, ?FiscalStatus $from, ?FiscalStatus $to, ?User $user): void
    {
        FiscalDocumentEvent::create([
            'company_id' => $document->company_id,
            'fiscal_document_id' => $document->id,
            'event' => $event,
            'description' => Str::limit($description, 990),
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
        ]);
    }
}
