<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Finance\Data\CommercialObligation;
use App\Modules\Finance\Enums\TitleOrigin;
use App\Modules\Finance\Integrations\CommercialObligations;
use App\Modules\Finance\Integrations\ObligationReconciler;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Purchases\Contracts\PurchaseObligationSource;
use App\Modules\Sales\Contracts\SaleReceivableSource;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Importação assistida de vendas e compras anteriores à contratação do Financeiro: período escolhido, prévia,
 * seleção e confirmação. Nada é presumido pago: documentos liquidados fora do sistema são marcados pelo usuário e
 * recebem baixa externa, sem movimentar contas. Repetir a importação não duplica títulos.
 */
class FinanceImportService
{
    public function __construct(
        private TenantContext $tenants,
        private ObligationReconciler $reconciler,
        private FinanceSettlementService $settlements,
        private AuditService $audit,
    ) {}

    /** @return list<string> Tipos disponíveis conforme os módulos habilitados. */
    public function availableTypes(Company $company): array
    {
        $modules = $this->tenants->enabledModulesFor($company);

        return array_values(array_filter([$modules->contains('identifier', 'sales') ? 'sales' : null, $modules->contains('identifier', 'purchases') ? 'purchases' : null]));
    }

    /** @return list<array{source_id: int, label: string, party: string|null, date: string, amount: string, due: string, is_cancelled: bool, is_imported: bool, is_eligible: bool}> */
    public function preview(Company $company, string $type, string $from, string $until): array
    {
        return array_map(function (CommercialObligation $obligation): array {
            $isImported = FinanceTitle::query()->where('company_id', $obligation->companyId)->where('source_type', $obligation->sourceType)->where('source_id', $obligation->sourceId)->exists();

            return [
                'source_id' => $obligation->sourceId,
                'label' => $obligation->label,
                'party' => $obligation->partyName,
                'date' => $obligation->documentDate,
                'amount' => $obligation->amount,
                'due' => $obligation->target,
                'is_cancelled' => $obligation->isCancelled,
                'is_imported' => $isImported,
                'is_eligible' => ! $isImported && ! $obligation->isCancelled && BigDecimal::of($obligation->target)->isPositive(),
            ];
        }, $this->obligations($company, $type, $from, $until));
    }

    /**
     * @param  list<int>  $selected
     * @param  list<int>  $settledExternally  Documentos que o usuário informou estarem liquidados fora do sistema.
     * @return int Quantidade de documentos importados.
     */
    public function import(Company $company, User $user, string $type, string $from, string $until, array $selected, array $settledExternally): int
    {
        $obligations = collect($this->obligations($company, $type, $from, $until))->keyBy('sourceId');
        $selected = array_values(array_unique($selected));

        // A seleção é validada por inteiro antes de gravar: ou tudo é importado, ou nada.
        if (array_diff($selected, $obligations->keys()->all()) !== []) {
            throw ValidationException::withMessages(['selected' => 'Um documento selecionado não está no período ou não é elegível.']);
        }

        $imported = DB::transaction(fn (): int => $this->importSelected($company, $user, array_values($obligations->only($selected)->all()), $settledExternally));
        $this->audit->record('finance.documents_imported', $company, $user, null, ['type' => $type, 'from' => $from, 'until' => $until, 'count' => $imported]);

        return $imported;
    }

    /**
     * @param  list<CommercialObligation>  $obligations
     * @param  list<int>  $settledExternally
     */
    private function importSelected(Company $company, User $user, array $obligations, array $settledExternally): int
    {
        $imported = 0;

        foreach ($obligations as $obligation) {
            $sourceId = $obligation->sourceId;

            if ($obligation->isCancelled || FinanceTitle::query()->where('company_id', $company->id)->where('source_type', $obligation->sourceType)->where('source_id', $sourceId)->exists()) {
                continue;
            }

            DB::transaction(function () use ($obligation, $user, $settledExternally, $sourceId): void {
                $this->reconciler->register($obligation, $user);

                if (! in_array($sourceId, $settledExternally, true)) {
                    return;
                }

                foreach (FinanceTitle::query()->where('company_id', $obligation->companyId)->where('source_type', $obligation->sourceType)->where('source_id', $sourceId)->whereIn('origin', [TitleOrigin::Sale, TitleOrigin::Purchase])->get() as $title) {
                    if ($title->openBalance()->isPositive()) {
                        $this->settlements->settle($title, $user, [
                            'is_external' => true,
                            'settled_at' => $title->due_date->format('Y-m-d'),
                            'principal' => (string) $title->openBalance(),
                            'notes' => 'Liquidado fora do sistema, informado na importação.',
                            'idempotency_key' => "import-external:{$title->id}",
                        ]);
                    }
                }
            });
            $imported++;
        }

        return $imported;
    }

    /** @return list<CommercialObligation> */
    private function obligations(Company $company, string $type, string $from, string $until): array
    {
        if (! in_array($type, $this->availableTypes($company), true)) {
            throw ValidationException::withMessages(['type' => 'O módulo de origem não está habilitado para esta empresa.']);
        }

        return $type === 'sales'
            ? array_map(CommercialObligations::fromSale(...), app(SaleReceivableSource::class)->receivablesBetween($company, $from, $until))
            : array_map(CommercialObligations::fromPurchase(...), app(PurchaseObligationSource::class)->obligationsBetween($company, $from, $until));
    }
}
