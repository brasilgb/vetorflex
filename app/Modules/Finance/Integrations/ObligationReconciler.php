<?php

namespace App\Modules\Finance\Integrations;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Finance\Data\CommercialObligation;
use App\Modules\Finance\Enums\CategoryKind;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Enums\TitleOrigin;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Services\FinanceCategoryService;
use App\Modules\Finance\Services\FinanceTitleService;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Concilia os títulos de um documento comercial com o valor que continua devido. É idempotente: reprocessar o
 * mesmo evento não muda nada, pois o cálculo parte do estado atual dos títulos.
 *
 * - Redução (cancelamento, devolução, saldo encerrado): abate o saldo em aberto, das parcelas mais distantes
 *   para as mais próximas, com ajuste rastreável.
 * - Valor já liquidado acima do devido nunca é apagado: vira um título de restituição (crédito explícito).
 * - Aumento (estorno que reabre saldo): desfaz ajustes anteriores, sem recriar títulos.
 */
class ObligationReconciler
{
    public function __construct(private FinanceTitleService $titles, private FinanceCategoryService $categories) {}

    /** Cria os títulos previstos do documento (uma vez) e concilia. */
    public function register(CommercialObligation $obligation, ?User $user): void
    {
        DB::transaction(function () use ($obligation, $user): void {
            $company = Company::query()->findOrFail($obligation->companyId);
            $origin = $obligation->kind === TitleKind::Receivable ? TitleOrigin::Sale : TitleOrigin::Purchase;
            $category = $this->categories->defaultFor($company, $obligation->kind->categoryKind(), $obligation->kind === TitleKind::Receivable ? 'Venda de mercadorias' : 'Mercadorias');
            $installments = $obligation->installments !== []
                ? $obligation->installments
                : [['key' => "{$obligation->baseKey}:single", 'due_date' => $obligation->documentDate, 'amount' => $obligation->amount]];

            if (! BigDecimal::of($obligation->amount)->isPositive()) {
                return;
            }

            foreach ($installments as $index => $row) {
                $this->titles->create($company, $user, [
                    'kind' => $obligation->kind->value,
                    'origin' => $origin->value,
                    'description' => $obligation->label.(count($installments) > 1 ? ' — parcela '.($index + 1).'/'.count($installments) : ''),
                    'amount' => $row['amount'],
                    'competence_date' => $obligation->documentDate,
                    'due_date' => $row['due_date'],
                    'party_id' => $obligation->partyId,
                    'party_name' => $obligation->partyName,
                    'finance_category_id' => $category->id,
                    'source_type' => $obligation->sourceType,
                    'source_id' => $obligation->sourceId,
                    'source_key' => $row['key'],
                    'source_label' => $obligation->label,
                ]);
            }

            $this->reconcile($obligation, 'Conciliação inicial do documento.');
        });
    }

    public function reconcile(CommercialObligation $obligation, string $reason): void
    {
        DB::transaction(function () use ($obligation, $reason): void {
            $titles = $this->sourceTitles($obligation);

            if ($titles->isEmpty()) {
                return;
            }

            $effective = $titles->reduce(fn (BigDecimal $carry, FinanceTitle $title): BigDecimal => $carry->plus($title->effectiveAmount()), BigDecimal::zero());
            $delta = $effective->minus($obligation->target);
            $key = "{$obligation->baseKey}:reconcile";

            if ($delta->isPositive()) {
                $remaining = $delta;

                foreach ($titles->sortByDesc(fn (FinanceTitle $title): string => $title->due_date->format('Y-m-d').sprintf('%010d', $title->id)) as $title) {
                    if (! $remaining->isPositive()) {
                        break;
                    }

                    $remaining = $remaining->minus($this->titles->adjust($title, (string) $remaining, "{$obligation->label}: {$reason}", $key));
                }
            } elseif ($delta->isNegative()) {
                $remaining = $delta->negated();

                foreach ($titles->sortBy(fn (FinanceTitle $title): string => $title->due_date->format('Y-m-d').sprintf('%010d', $title->id)) as $title) {
                    if (! $remaining->isPositive()) {
                        break;
                    }

                    $remaining = $remaining->plus($this->titles->adjust($title, (string) $remaining->negated(), "{$obligation->label}: {$reason}", $key));
                }
            }

            $this->refund($obligation, $this->sourceTitles($obligation));
        });
    }

    /** Valor já liquidado acima do devido vira restituição: a pagar ao cliente, ou a receber do fornecedor. */
    /** @param Collection<int, FinanceTitle> $titles */
    private function refund(CommercialObligation $obligation, Collection $titles): void
    {
        $settled = $titles->reduce(fn (BigDecimal $carry, FinanceTitle $title): BigDecimal => $carry->plus($title->settled_principal), BigDecimal::zero());
        $refunds = FinanceTitle::query()->where('company_id', $obligation->companyId)->where('source_type', $obligation->sourceType)
            ->where('source_id', $obligation->sourceId)->whereIn('origin', [TitleOrigin::SaleRefund, TitleOrigin::PurchaseRefund])->get();
        $alreadyRefunded = $refunds->reduce(fn (BigDecimal $carry, FinanceTitle $title): BigDecimal => $carry->plus($title->effectiveAmount())->minus($title->cancelled_amount), BigDecimal::zero());
        $missing = $settled->minus($obligation->target)->minus($alreadyRefunded);

        if (! $missing->isPositive()) {
            return;
        }

        $company = Company::query()->findOrFail($obligation->companyId);
        $isSale = $obligation->kind === TitleKind::Receivable;
        $category = $this->categories->defaultFor($company, $isSale ? CategoryKind::Expense : CategoryKind::Income, $isSale ? 'Devoluções de vendas' : 'Devoluções de compras');
        $this->titles->create($company, null, [
            'kind' => ($isSale ? TitleKind::Payable : TitleKind::Receivable)->value,
            'origin' => ($isSale ? TitleOrigin::SaleRefund : TitleOrigin::PurchaseRefund)->value,
            'description' => ($isSale ? 'Restituição ao cliente' : 'Restituição do fornecedor')." — {$obligation->label}",
            'amount' => CommercialMath::money($missing),
            'competence_date' => now($company->timezone)->format('Y-m-d'),
            'due_date' => now($company->timezone)->format('Y-m-d'),
            'party_id' => $obligation->partyId,
            'party_name' => $obligation->partyName,
            'finance_category_id' => $category->id,
            'source_type' => $obligation->sourceType,
            'source_id' => $obligation->sourceId,
            'source_key' => "{$obligation->baseKey}:refund:".($refunds->count() + 1),
            'source_label' => $obligation->label,
            'notes' => 'Valor já liquidado acima do devido após cancelamento ou devolução. Confirme a restituição ou o crédito com a pessoa.',
        ]);
    }

    /** @return Collection<int, FinanceTitle> */
    private function sourceTitles(CommercialObligation $obligation): Collection
    {
        return FinanceTitle::query()->where('company_id', $obligation->companyId)->where('source_type', $obligation->sourceType)
            ->where('source_id', $obligation->sourceId)->whereIn('origin', [TitleOrigin::Sale, TitleOrigin::Purchase])
            ->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();
    }
}
