<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Parties\Services\PartyService;
use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Payments\Data\ProviderCharge;
use App\Modules\Payments\Enums\AccountStatus;
use App\Modules\Payments\Enums\ChargeOrigin;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Events\PaymentReceived;
use App\Modules\Payments\Events\PaymentRefundConfirmed;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentChargeEvent;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Providers\ProviderException;
use App\Modules\Payments\Providers\ProviderRegistry;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida das cobranças: criação (avulsa ou de uma venda, título ou atendimento de PDV), emissão no provedor,
 * sincronização com a consulta ao provedor, cancelamento e devolução.
 *
 * - A criação é idempotente (chave e conteúdo); `active_origin_key` impede duas cobranças abertas para a mesma origem.
 * - O identificador no provedor (txid) é gravado antes da emissão: repetir a emissão nunca cria outra cobrança lá.
 * - A situação nunca vem só do webhook: `sync` consulta o provedor e aplica os fatos. Pagamentos são únicos por
 *   identificador do provedor, por isso eventos repetidos ou fora de ordem não duplicam nem regridem a situação.
 * - Eventos `PaymentReceived` e `PaymentRefundConfirmed` saem uma vez por fato, depois do commit.
 */
class ChargeService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private ProviderRegistry $providers,
        private PaymentsModule $module,
        private ChargeOrigins $origins,
        private PartyService $parties,
        private SequenceService $sequences,
        private AuditService $audit,
    ) {}

    /**
     * Dados: `description`, `amount` (opcional com origem: saldo devido), `due_date`, `reference`, `payer_party_id`
     * ou `payer` (nome, documento, e-mail, telefone), `origin`, `origin_id`, `expires_in` (segundos), `metadata`,
     * `issue` (emitir já) e `idempotency_key`.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, ?User $user, array $data): PaymentCharge
    {
        $origin = ChargeOrigin::from((string) ($data['origin'] ?? ChargeOrigin::Manual->value));
        $resolved = $this->origins->resolve($company, $origin, isset($data['origin_id']) ? (int) $data['origin_id'] : null, $data['origin_key'] ?? null);
        $amount = CommercialMath::money((string) ($data['amount'] ?? $resolved['balance'] ?? '0'));

        if (! BigDecimal::of($amount)->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }

        if ($resolved['balance'] !== null && BigDecimal::of($amount)->isGreaterThan($resolved['balance'])) {
            throw ValidationException::withMessages(['amount' => 'O valor supera o saldo devido ('.CommercialMath::money($resolved['balance']).').']);
        }

        $hash = hash('sha256', (string) json_encode([$origin->value, $resolved['type'], $resolved['id'], $amount, $data['description'] ?? null, $data['due_date'] ?? null, $data['payer_party_id'] ?? null, $data['payer']['document'] ?? null]));
        $existing = $this->existing($company, (string) $data['idempotency_key'], $hash);

        if ($existing !== null) {
            return $existing;
        }

        $account = $this->module->activeAccount($company);

        try {
            $charge = DB::transaction(function () use ($company, $user, $data, $origin, $resolved, $amount, $hash, $account): PaymentCharge {
                $payer = $this->payer($company, $data, $resolved['party_id']);
                $charge = PaymentCharge::create([
                    'company_id' => $company->id,
                    'number' => $this->sequences->next($company, 'payment_charge'),
                    'payment_provider_account_id' => $account?->id,
                    'provider' => $account?->provider,
                    'environment' => $account?->environment,
                    'method' => 'pix',
                    'status' => ChargeStatus::Draft,
                    'amount' => $amount,
                    'description' => Str::limit(trim((string) ($data['description'] ?? $resolved['description'] ?? 'Cobrança')), 200, ''),
                    'reference' => $data['reference'] ?? null,
                    'due_date' => $data['due_date'] ?? $resolved['due_date'] ?? now($company->timezone)->format('Y-m-d'),
                    'expires_at' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
                    'payer_party_id' => $payer?->id,
                    'payer_name' => $payer->name ?? ($resolved['payer_name'] ?? null),
                    'payer_document' => $payer?->document,
                    'origin' => $origin,
                    'origin_type' => $resolved['type'],
                    'origin_id' => $resolved['id'],
                    'origin_label' => $resolved['label'],
                    'active_origin_key' => $resolved['key'],
                    'metadata' => $data['metadata'] ?? null,
                    'idempotency_key' => (string) $data['idempotency_key'],
                    'request_hash' => $hash,
                    'created_by' => $user?->id,
                    'created_by_name' => $user?->name,
                ]);
                $this->event($charge, 'created', "Cobrança #{$charge->number} criada ({$origin->label()}).", null, ChargeStatus::Draft, $user);
                $this->audit->record('payments.charge_created', $company, $user, $charge, ['number' => $charge->number, 'amount' => $amount, 'origin' => $origin->value]);

                return $charge;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->existing($company, (string) $data['idempotency_key'], $hash);

            if ($existing !== null) {
                return $existing;
            }

            throw ValidationException::withMessages(['origin_id' => 'Já existe uma cobrança em aberto para esta origem. Consulte ou cancele a cobrança existente.']);
        }

        return ($data['issue'] ?? true) && $account !== null ? $this->issue($charge, $user) : $charge;
    }

    /** Emite no provedor uma cobrança em rascunho (ou que falhou antes de chegar ao provedor). */
    public function issue(PaymentCharge $charge, ?User $user): PaymentCharge
    {
        $company = Company::query()->findOrFail($charge->company_id);
        $account = $charge->payment_provider_account_id === null ? $this->module->activeAccount($company) : PaymentProviderAccount::query()->find($charge->payment_provider_account_id);

        if ($account === null || $account->status !== AccountStatus::Active || ! $this->providers->isAvailable($account->provider)) {
            throw ValidationException::withMessages(['provider' => 'Configure e teste a conexão com um provedor de pagamento antes de emitir a cobrança.']);
        }

        $charge = DB::transaction(function () use ($charge, $account): PaymentCharge {
            $charge = PaymentCharge::query()->lockForUpdate()->findOrFail($charge->id);

            if (! in_array($charge->status, [ChargeStatus::Draft, ChargeStatus::Failed], true)) {
                return $charge;
            }

            $charge->update([
                'payment_provider_account_id' => $account->id,
                'provider' => $account->provider,
                'environment' => $account->environment,
                'provider_charge_id' => $charge->provider_charge_id ?? $this->txid($charge),
                'status' => ChargeStatus::Processing,
                'failure_reason' => null,
            ]);

            return $charge;
        });

        if ($charge->status !== ChargeStatus::Processing) {
            return $charge;
        }

        try {
            $remote = $this->providers->get($account->provider)->createPixCharge($account, $charge);
        } catch (ProviderException $exception) {
            return $this->fail($charge, $exception, $user);
        }

        return DB::transaction(function () use ($charge, $remote, $user): PaymentCharge {
            $charge = PaymentCharge::query()->lockForUpdate()->findOrFail($charge->id);
            $charge->update([
                'status' => ChargeStatus::Pending,
                'provider_status' => $remote->status,
                'provider_location_id' => $remote->locationId,
                'pix_copy_paste' => $remote->pixCopyPaste,
                'qr_code_image' => $remote->qrCodeImage,
                'payment_url' => $remote->paymentUrl,
                'expires_at' => $charge->expires_at ?? now()->addSeconds($charge->account->expiration_seconds ?? 3600),
                'last_synced_at' => now(),
            ]);
            $this->event($charge, 'issued', 'Cobrança emitida no provedor.', ChargeStatus::Processing, ChargeStatus::Pending, $user);

            return $this->apply($charge, $remote, $user);
        });
    }

    /** Consulta o provedor e aplica pagamentos, devoluções e remoção. Seguro para repetir e para eventos fora de ordem. */
    public function sync(PaymentCharge $charge, ?User $user = null): PaymentCharge
    {
        $account = $charge->payment_provider_account_id === null ? null : PaymentProviderAccount::query()->find($charge->payment_provider_account_id);

        if ($account === null || $charge->provider_charge_id === null || $charge->status === ChargeStatus::Draft) {
            return $charge;
        }

        $remote = $this->providers->get($account->provider)->fetchCharge($account, $charge);

        return DB::transaction(fn (): PaymentCharge => $this->apply(PaymentCharge::query()->lockForUpdate()->findOrFail($charge->id), $remote, $user));
    }

    public function cancel(PaymentCharge $charge, User $user, string $reason): PaymentCharge
    {
        $charge = $this->sync($charge, $user);

        if (! $charge->status->isOpen() || BigDecimal::of($charge->paid_amount)->isPositive()) {
            throw ValidationException::withMessages(['charge' => "Cobrança \"{$charge->status->label()}\" não pode ser cancelada."]);
        }

        $account = $charge->payment_provider_account_id === null ? null : PaymentProviderAccount::query()->find($charge->payment_provider_account_id);

        if ($account !== null && $charge->provider_charge_id !== null && $charge->status !== ChargeStatus::Draft) {
            $provider = $this->providers->get($account->provider);

            if (! $provider->capabilities()->cancel) {
                throw ValidationException::withMessages(['charge' => "{$provider->name()} não permite cancelar esta cobrança."]);
            }

            try {
                $provider->cancelCharge($account, $charge);
            } catch (ProviderException $exception) {
                throw ValidationException::withMessages(['charge' => $exception->getMessage()]);
            }
        }

        return DB::transaction(function () use ($charge, $user, $reason): PaymentCharge {
            $charge = PaymentCharge::query()->lockForUpdate()->findOrFail($charge->id);
            $from = $charge->status;
            $charge->update(['status' => ChargeStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by_name' => $user->name, 'cancellation_reason' => $reason, 'active_origin_key' => null]);
            $this->event($charge, 'cancelled', "Cobrança cancelada: {$reason}", $from, ChargeStatus::Cancelled, $user);
            $this->audit->record('payments.charge_cancelled', Company::query()->findOrFail($charge->company_id), $user, $charge, ['number' => $charge->number, 'reason' => $reason]);

            return $charge;
        });
    }

    /** Devolução de parte ou do total de um pagamento recebido. Idempotente pela chave. */
    public function refund(PaymentCharge $charge, PaymentTransaction $transaction, User $user, string $amount, string $reason, string $idempotencyKey): PaymentRefund
    {
        $existing = PaymentRefund::query()->where('company_id', $charge->company_id)->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $account = PaymentProviderAccount::query()->findOrFail($charge->payment_provider_account_id);
        $provider = $this->providers->get($account->provider);

        if (! $provider->capabilities()->refund) {
            throw ValidationException::withMessages(['amount' => "{$provider->name()} não permite devoluções pela integração."]);
        }

        $refund = DB::transaction(function () use ($charge, $transaction, $user, $amount, $reason, $idempotencyKey): PaymentRefund {
            $transaction = PaymentTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $pending = (string) PaymentRefund::query()->where('payment_transaction_id', $transaction->id)->whereIn('status', [RefundStatus::Processing, RefundStatus::Refunded])->sum('amount');
            $available = BigDecimal::of($transaction->amount)->minus($pending ?: '0');
            $value = BigDecimal::of(CommercialMath::money($amount));

            if (! $value->isPositive() || $value->isGreaterThan($available)) {
                throw ValidationException::withMessages(['amount' => 'Valor de devolução inválido. Disponível: '.CommercialMath::money($available).'.']);
            }

            $refund = PaymentRefund::create([
                'company_id' => $charge->company_id,
                'payment_charge_id' => $charge->id,
                'payment_transaction_id' => $transaction->id,
                'provider_refund_id' => 'D'.Str::upper(Str::random(20)),
                'amount' => (string) $value,
                'status' => RefundStatus::Processing,
                'reason' => $reason,
                'requested_by' => $user->id,
                'requested_by_name' => $user->name,
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->event($charge, 'refund_requested', 'Devolução de R$ '.str_replace('.', ',', (string) $value)." solicitada: {$reason}", null, null, $user);
            $this->audit->record('payments.refund_requested', Company::query()->findOrFail($charge->company_id), $user, $charge, ['number' => $charge->number, 'amount' => (string) $value]);

            return $refund;
        });

        try {
            $remote = $provider->refund($account, $transaction, $refund);
        } catch (ProviderException $exception) {
            $refund->update(['status' => RefundStatus::Failed, 'failure_reason' => $exception->getMessage(), 'completed_at' => now()]);
            $this->event($charge, 'refund_failed', "Devolução não realizada: {$exception->getMessage()}", null, null, $user);

            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        DB::transaction(function () use ($charge, $refund, $remote, $user): void {
            $this->applyRefund(PaymentRefund::query()->lockForUpdate()->findOrFail($refund->id), $remote->status, $remote->rtrId, $remote->reason, PaymentCharge::query()->lockForUpdate()->findOrFail($charge->id), $user);
        });

        return $refund->refresh();
    }

    /** Registra a falha de emissão, libera a origem e preserva o histórico. */
    private function fail(PaymentCharge $charge, ProviderException $exception, ?User $user): PaymentCharge
    {
        return DB::transaction(function () use ($charge, $exception, $user): PaymentCharge {
            $charge = PaymentCharge::query()->lockForUpdate()->findOrFail($charge->id);
            $charge->update(['status' => ChargeStatus::Failed, 'failure_reason' => $exception->getMessage(), 'active_origin_key' => null]);
            $this->event($charge, 'failed', "Falha na emissão: {$exception->getMessage()}", ChargeStatus::Processing, ChargeStatus::Failed, $user);
            PaymentProviderAccount::query()->whereKey($charge->payment_provider_account_id)->update(['last_error' => Str::limit($exception->getMessage(), 490)]);

            return $charge;
        });
    }

    /**
     * Aplica os fatos informados pelo provedor. Chamar somente dentro de transação, com a cobrança travada.
     */
    private function apply(PaymentCharge $charge, ProviderCharge $remote, ?User $user): PaymentCharge
    {
        $company = Company::query()->findOrFail($charge->company_id);
        $account = PaymentProviderAccount::query()->find($charge->payment_provider_account_id);
        $from = $charge->status;

        foreach ($remote->payments as $payment) {
            $transaction = PaymentTransaction::query()->where('company_id', $charge->company_id)->where('provider_transaction_id', $payment->id)->first();

            if ($transaction === null) {
                if (! $charge->status->acceptsPayment() && $charge->status !== ChargeStatus::Cancelled) {
                    continue;
                }

                $amount = CommercialMath::money($payment->amount);
                $transaction = PaymentTransaction::create([
                    'company_id' => $charge->company_id,
                    'payment_charge_id' => $charge->id,
                    'provider_transaction_id' => $payment->id,
                    'amount' => $amount,
                    'fee_amount' => $payment->fee === null ? null : CommercialMath::money($payment->fee),
                    'net_amount' => $payment->fee === null ? null : CommercialMath::money(BigDecimal::of($amount)->minus($payment->fee)),
                    'paid_at' => now()->parse($payment->paidAt),
                    'payer_name' => $payment->payerName,
                    'payer_document' => BrazilianDocument::normalize($payment->payerDocument),
                ]);
                $this->event($charge, 'paid', 'Pagamento de R$ '.str_replace('.', ',', $amount).' confirmado pelo provedor.', null, null, $user);
                $event = new PaymentReceived($charge->company_id, $charge->id, $transaction->id, $amount, $transaction->paid_at->setTimezone($company->timezone)->format('Y-m-d'), $charge->origin->value, $charge->origin_type, $charge->origin_id, $account?->finance_account_id, $charge->created_by);
                DB::afterCommit(fn () => event($event));
            }

            foreach ($payment->refunds as $remoteRefund) {
                $refund = PaymentRefund::query()->where('payment_transaction_id', $transaction->id)->where('provider_refund_id', $remoteRefund->id)->lockForUpdate()->first();

                if ($refund !== null) {
                    $this->applyRefund($refund, $remoteRefund->status, $remoteRefund->rtrId, $remoteRefund->reason, $charge, $user);
                }
            }
        }

        $paid = BigDecimal::of((string) (PaymentTransaction::query()->where('payment_charge_id', $charge->id)->sum('amount') ?: '0'));
        $refunded = BigDecimal::of((string) (PaymentRefund::query()->where('payment_charge_id', $charge->id)->where('status', RefundStatus::Refunded)->sum('amount') ?: '0'));
        $fees = PaymentTransaction::query()->where('payment_charge_id', $charge->id)->whereNotNull('fee_amount')->sum('fee_amount');
        $status = match (true) {
            $paid->isPositive() && $refunded->isGreaterThanOrEqualTo($paid) => ChargeStatus::Refunded,
            $paid->isGreaterThanOrEqualTo($charge->amount) => ChargeStatus::Paid,
            $paid->isPositive() => ChargeStatus::PartiallyPaid,
            $remote->isRemoved && $charge->status->isOpen() => ChargeStatus::Cancelled,
            $charge->status->isOpen() && $charge->expires_at !== null && $charge->expires_at->isPast() => ChargeStatus::Expired,
            default => $charge->status,
        };
        $charge->update([
            'status' => $status,
            'provider_status' => $remote->status !== '' ? $remote->status : $charge->provider_status,
            'paid_amount' => CommercialMath::money($paid),
            'refunded_amount' => CommercialMath::money($refunded),
            'fee_amount' => $fees ? CommercialMath::money((string) $fees) : $charge->fee_amount,
            'net_amount' => $fees ? CommercialMath::money($paid->minus((string) $fees)) : null,
            'paid_at' => $charge->paid_at ?? ($paid->isPositive() ? PaymentTransaction::query()->where('payment_charge_id', $charge->id)->min('paid_at') : null),
            'cancelled_at' => $status === ChargeStatus::Cancelled ? ($charge->cancelled_at ?? now()) : $charge->cancelled_at,
            'active_origin_key' => $status->isOpen() && $status !== ChargeStatus::PartiallyPaid ? $charge->active_origin_key : null,
            'last_synced_at' => now(),
        ]);

        if ($from !== $status) {
            $this->event($charge, 'status_changed', "Situação alterada de {$from->label()} para {$status->label()}.", $from, $status, $user);
        }

        return $charge;
    }

    private function applyRefund(PaymentRefund $refund, RefundStatus $status, ?string $rtrId, ?string $reason, PaymentCharge $charge, ?User $user): void
    {
        if ($refund->status !== RefundStatus::Processing || $status === RefundStatus::Processing) {
            if ($rtrId !== null && $refund->rtr_id === null) {
                $refund->update(['rtr_id' => $rtrId]);
            }

            return;
        }

        $refund->update(['status' => $status, 'rtr_id' => $rtrId ?? $refund->rtr_id, 'failure_reason' => $status === RefundStatus::Failed ? $reason : null, 'completed_at' => now()]);
        $this->event($charge, $status === RefundStatus::Refunded ? 'refunded' : 'refund_failed', $status === RefundStatus::Refunded ? 'Devolução de R$ '.str_replace('.', ',', (string) $refund->amount).' confirmada pelo provedor.' : 'Devolução não realizada pelo provedor'.($reason ? ": {$reason}" : '.'), null, null, $user);

        if ($status !== RefundStatus::Refunded) {
            return;
        }

        $transaction = PaymentTransaction::query()->lockForUpdate()->findOrFail($refund->payment_transaction_id);
        $refunded = BigDecimal::of((string) PaymentRefund::query()->where('payment_transaction_id', $transaction->id)->where('status', RefundStatus::Refunded)->sum('amount'));
        $transaction->update(['refunded_amount' => CommercialMath::money($refunded)]);
        $account = PaymentProviderAccount::query()->find($charge->payment_provider_account_id);
        $event = new PaymentRefundConfirmed($charge->company_id, $charge->id, $transaction->id, $refund->id, (string) $refund->amount, CommercialMath::money(BigDecimal::of($transaction->amount)->minus($refunded)), $account?->finance_account_id, $refund->requested_by);
        DB::afterCommit(fn () => event($event));
    }

    /** @param array<string, mixed> $data */
    private function payer(Company $company, array $data, ?int $originPartyId): ?Party
    {
        if (filled($data['payer_party_id'] ?? null) || $originPartyId !== null) {
            return Party::query()->where('company_id', $company->id)->findOrFail((int) ($data['payer_party_id'] ?? $originPartyId));
        }

        $payer = $data['payer'] ?? null;

        if (! is_array($payer) || blank($payer['name'] ?? null)) {
            return null;
        }

        $document = BrazilianDocument::normalize($payer['document'] ?? null);

        if ($document !== null && ! (strlen($document) === 11 ? BrazilianDocument::isCpf($document) : BrazilianDocument::isCnpj($document))) {
            throw ValidationException::withMessages(['payer.document' => 'Informe um CPF ou CNPJ válido.']);
        }

        $party = $this->parties->findByDocument($company, $document) ?? new Party;

        return $this->parties->save($company, $party, PartyRole::CUSTOMER, array_filter([
            'type' => $document !== null && strlen($document) === 14 ? 'company' : 'individual',
            'name' => $party->exists ? null : trim((string) $payer['name']),
            'document' => $document,
            'email' => $payer['email'] ?? null,
            'phone' => $payer['phone'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /** Identificador da cobrança no Pix: 32 caracteres alfanuméricos (o padrão aceita de 26 a 35). */
    private function txid(PaymentCharge $charge): string
    {
        return Str::upper(substr('VF'.str_pad((string) $charge->company_id, 6, '0', STR_PAD_LEFT).str_pad((string) $charge->number, 8, '0', STR_PAD_LEFT).Str::random(16), 0, 32));
    }

    private function existing(Company $company, string $key, string $hash): ?PaymentCharge
    {
        $existing = PaymentCharge::query()->where('company_id', $company->id)->where('idempotency_key', $key)->first();

        if ($existing !== null && ! hash_equals($existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta solicitação já foi usada para outra cobrança.']);
        }

        return $existing;
    }

    private function event(PaymentCharge $charge, string $event, string $description, ?ChargeStatus $from, ?ChargeStatus $to, ?User $user): void
    {
        PaymentChargeEvent::create([
            'company_id' => $charge->company_id,
            'payment_charge_id' => $charge->id,
            'event' => $event,
            'description' => Str::limit($description, 490),
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'user_id' => $user?->id,
            'user_name' => $user->name ?? 'Sistema',
        ]);
    }
}
