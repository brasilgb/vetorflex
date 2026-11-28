<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Sales\Data\SaleAbilities;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SaleOrigin;
use App\Modules\Sales\Enums\SaleQuoteStatus;
use App\Modules\Sales\Events\QuoteApproved;
use App\Modules\Sales\Events\QuoteConverted;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Sales\Support\Idempotency;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo do orçamento: rascunho, emissão, aprovação, recusa, cancelamento, expiração e conversão.
 * Nenhuma operação movimenta estoque ou gera obrigação financeira.
 */
class SaleQuoteService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private SaleDocumentBuilder $builder,
        private SequenceService $sequences,
        private SaleHistory $history,
        private AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, User $user, array $data, SaleAbilities $abilities): SaleQuote
    {
        $key = $data['idempotency_key'] ?? null;
        $hash = Idempotency::hash($data);

        try {
            return DB::transaction(function () use ($company, $user, $data, $abilities, $key, $hash): SaleQuote {
                $existing = $this->existing($company, $key, $hash);

                if ($existing !== null) {
                    return $existing;
                }

                $quote = new SaleQuote([
                    'company_id' => $company->id,
                    'number' => $this->sequences->next($company, 'sale_quote'),
                    'status' => SaleQuoteStatus::Draft,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'idempotency_key' => $key,
                    'request_hash' => $key === null ? null : $hash,
                ]);
                $this->builder->fill($company, $quote, $user, $data, $abilities);
                $this->history->record($quote, null, SaleQuoteStatus::Draft->value, $user, null);
                $this->audit->record('sales.quote_created', $company, $user, $quote, ['number' => $quote->number]);

                return $quote;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($company, $key, $hash) ?? throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(SaleQuote $quote, User $user, array $data, SaleAbilities $abilities): SaleQuote
    {
        return DB::transaction(function () use ($quote, $user, $data, $abilities): SaleQuote {
            $quote = $this->lock($quote);

            if ($quote->status !== SaleQuoteStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Somente orçamentos em rascunho podem ser editados.']);
            }

            $this->builder->fill($quote->company, $quote, $user, $data, $abilities);
            $this->audit->record('sales.quote_updated', $quote->company, $user, $quote, ['number' => $quote->number]);

            return $quote;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function issue(SaleQuote $quote, User $user, string $today): SaleQuote
    {
        return DB::transaction(function () use ($quote, $user, $today): SaleQuote {
            $quote = $this->lock($quote);
            $this->ensureTransition($quote, SaleQuoteStatus::Issued);

            if (! $quote->items()->exists()) {
                throw ValidationException::withMessages(['items' => 'Inclua ao menos um item antes de emitir.']);
            }

            if ($quote->valid_until->format('Y-m-d') < $today) {
                throw ValidationException::withMessages(['valid_until' => 'A validade já passou. Edite o orçamento antes de emiti-lo.']);
            }

            $quote->update(['issued_at' => now()]);
            $this->transition($quote, SaleQuoteStatus::Issued, $user);

            return $quote;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function approve(SaleQuote $quote, User $user, string $today, ?string $reason = null): SaleQuote
    {
        return $this->decide($quote, $user, $today, SaleQuoteStatus::Approved, $reason);
    }

    public function reject(SaleQuote $quote, User $user, string $today, string $reason): SaleQuote
    {
        return $this->decide($quote, $user, $today, SaleQuoteStatus::Rejected, $reason);
    }

    public function cancel(SaleQuote $quote, User $user, string $reason): SaleQuote
    {
        return DB::transaction(function () use ($quote, $user, $reason): SaleQuote {
            $quote = $this->lock($quote);
            $this->transition($quote, SaleQuoteStatus::Cancelled, $user, $reason);

            return $quote;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Expira orçamentos emitidos com validade vencida. Executado diariamente e antes de decisões sobre o orçamento.
     *
     * @return int Quantidade de orçamentos expirados.
     */
    public function expireDue(Company $company, ?User $user, string $today): int
    {
        $expired = 0;

        foreach (SaleQuote::query()->where('company_id', $company->id)->where('status', SaleQuoteStatus::Issued)->where('valid_until', '<', $today)->pluck('id') as $id) {
            $expired += DB::transaction(function () use ($company, $id, $user, $today): int {
                $quote = SaleQuote::query()->where('company_id', $company->id)->whereKey($id)->lockForUpdate()->first();

                if ($quote === null || ! $quote->isExpiredOn($today)) {
                    return 0;
                }

                $this->transition($quote, SaleQuoteStatus::Expired, $user, 'Validade encerrada em '.$quote->valid_until->format('d/m/Y').'.');

                return 1;
            });
        }

        return $expired;
    }

    /**
     * Converte o orçamento aprovado num pedido em rascunho, com os mesmos itens e preços. É idempotente:
     * repetir a conversão devolve o pedido já criado, e o pedido guarda a referência ao orçamento.
     */
    public function convert(SaleQuote $quote, User $user): SaleOrder
    {
        try {
            return DB::transaction(function () use ($quote, $user): SaleOrder {
                $quote = $this->lock($quote);
                $existing = SaleOrder::query()->where('company_id', $quote->company_id)->where('sale_quote_id', $quote->id)->first();

                if ($existing !== null) {
                    return $existing;
                }

                $this->ensureTransition($quote, SaleQuoteStatus::Converted);
                $order = SaleOrder::create([
                    ...Arr::only($quote->getAttributes(), ['company_id', 'customer_party_id', 'seller_id', 'seller_name', 'items_total', 'discount', 'freight', 'other_costs', 'total', 'payment_condition', 'payment_method', 'payment_notes', 'commercial_terms', 'notes']),
                    'customer_snapshot' => $quote->customer_snapshot,
                    'number' => $this->sequences->next($quote->company, 'sale_order'),
                    'status' => SaleOrderStatus::Draft,
                    'origin' => SaleOrigin::Quote,
                    'sale_quote_id' => $quote->id,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'order_date' => now($quote->company->timezone)->format('Y-m-d'),
                    'delivery_method' => 'pickup',
                ]);

                foreach ($quote->items()->get() as $item) {
                    $order->items()->create(Arr::except($item->getAttributes(), ['id', 'sale_quote_id', 'created_at', 'updated_at']));
                }

                foreach ($quote->paymentSchedules()->get() as $schedule) {
                    $order->paymentSchedules()->create(Arr::only($schedule->getAttributes(), ['company_id', 'sequence', 'is_entry', 'due_date', 'amount', 'method']));
                }

                $this->history->record($order, null, SaleOrderStatus::Draft->value, $user, "Convertido do orçamento #{$quote->number}.");
                $this->transition($quote, SaleQuoteStatus::Converted, $user, "Pedido de venda #{$order->number}.");
                $this->audit->record('sales.order_created', $quote->company, $user, $order, ['number' => $order->number, 'quote' => $quote->number]);
                QuoteConverted::dispatch($quote, $order);

                return $order;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            return SaleOrder::query()->where('company_id', $quote->company_id)->where('sale_quote_id', $quote->id)->first() ?? throw $exception;
        }
    }

    public function lock(SaleQuote $quote): SaleQuote
    {
        return SaleQuote::query()->where('company_id', $quote->company_id)->lockForUpdate()->findOrFail($quote->id);
    }

    private function decide(SaleQuote $quote, User $user, string $today, SaleQuoteStatus $next, ?string $reason): SaleQuote
    {
        $quote = DB::transaction(function () use ($quote, $user, $today, $next, $reason): SaleQuote {
            $quote = $this->lock($quote);

            if ($quote->isExpiredOn($today)) {
                $this->transition($quote, SaleQuoteStatus::Expired, $user, 'Validade encerrada em '.$quote->valid_until->format('d/m/Y').'.');

                return $quote;
            }

            $this->ensureTransition($quote, $next);
            $quote->update(['decided_at' => now()]);
            $this->transition($quote, $next, $user, $reason);

            if ($next === SaleQuoteStatus::Approved) {
                QuoteApproved::dispatch($quote);
            }

            return $quote;
        }, self::TRANSACTION_ATTEMPTS);

        if ($quote->status === SaleQuoteStatus::Expired) {
            throw ValidationException::withMessages(['status' => 'O orçamento expirou e não pode mais ser aprovado ou recusado.']);
        }

        return $quote;
    }

    private function transition(SaleQuote $quote, SaleQuoteStatus $next, ?User $user, ?string $reason = null): void
    {
        $this->ensureTransition($quote, $next);
        $previous = $quote->status;
        $quote->update(['status' => $next]);
        $this->history->record($quote, $previous->value, $next->value, $user, $reason);
    }

    private function ensureTransition(SaleQuote $quote, SaleQuoteStatus $next): void
    {
        if (! $quote->status->canTransitionTo($next)) {
            throw ValidationException::withMessages(['status' => "Não é possível passar o orçamento de \"{$quote->status->label()}\" para \"{$next->label()}\"."]);
        }
    }

    private function existing(Company $company, ?string $key, string $hash): ?SaleQuote
    {
        if ($key === null) {
            return null;
        }

        $existing = SaleQuote::query()->where('company_id', $company->id)->where('idempotency_key', $key)->first();

        if ($existing !== null && ! hash_equals((string) $existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta requisição já foi usada para outro orçamento. Recarregue a página.']);
        }

        return $existing;
    }
}
