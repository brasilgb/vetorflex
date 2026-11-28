<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceRecurrence;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Models\FinanceTransfer;
use App\Modules\Finance\Services\FinanceAccountService;
use App\Modules\Finance\Services\FinanceCategoryService;
use App\Modules\Finance\Services\FinanceImportService;
use App\Modules\Finance\Services\FinanceRecurrenceService;
use App\Modules\Finance\Services\FinanceSettlementService;
use App\Modules\Finance\Services\FinanceTitleService;
use App\Modules\Finance\Services\FinanceTransferService;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\RoundingMode;
use Illuminate\Database\Seeder;

/**
 * Cenários financeiros gerados pelos serviços reais, em três combinações de módulos:
 * - "Escritório Somente Financeiro": contas, títulos manuais, parcelas, baixas parciais, vencidos, transferência,
 *   ajuste, recorrências e estorno;
 * - "Loja Somente Vendas": Financeiro contratado depois, com importação assistida da venda existente;
 * - "Empresa Demonstração" (Vendas e Compras): importação de vendas e compras, inclusive a venda com devolução.
 *
 * Restrito a local e testing. Repetir o seed não duplica: cada registro é procurado pela chave antes de criar.
 */
class FinanceDemoSeeder extends Seeder
{
    private Company $company;

    private User $user;

    public function run(): void
    {
        $user = User::query()->where('email', 'demo@vetornew.test')->first();

        if (! app()->environment(['local', 'testing']) || $user === null) {
            $this->command?->warn('FinanceDemoSeeder ignorado: disponível apenas nos ambientes local e testing, após SalesDemoSeeder.');

            return;
        }

        $this->user = $user;
        $this->financeOnly();
        $this->laterActivation('loja-somente-vendas', ['sales']);
        $this->laterActivation('empresa-demonstracao', ['sales', 'purchases']);
    }

    private function financeOnly(): void
    {
        $this->company = Company::query()->firstOrCreate(['slug' => 'escritorio-somente-financeiro'], ['name' => 'Escritório Somente Financeiro', 'status' => 'active', 'timezone' => 'America/Sao_Paulo', 'currency' => 'BRL']);

        if (! $this->company->users()->whereKey($this->user->id)->exists()) {
            $this->company->users()->attach($this->user, ['role' => 'owner', 'is_active' => true, 'permissions' => []]);
        }

        $this->activate('finance');
        app(FinanceCategoryService::class)->ensureDefaults($this->company);
        $now = now($this->company->timezone);
        $date = fn (int $days): string => $now->copy()->addDays($days)->format('Y-m-d');
        $cash = $this->account('Caixa do escritório', 'cash', '350.00', $date(-60));
        $bank = $this->account('Conta corrente', 'checking', '4200.00', $date(-60), 'Banco Cooperativo');
        $this->account('Conta digital', 'digital', '0.00', $date(-60), 'Banco Digital');

        $consulting = $this->title('demo-fin-consulting', ['kind' => 'receivable', 'description' => 'Consultoria mensal — Padaria Central', 'amount' => '1800.00', 'competence_date' => $date(-20), 'due_date' => $date(-10), 'finance_category_id' => $this->category('income', 'Prestação de serviços')]);
        $this->settle('demo-fin-consulting-partial', $consulting[0], $bank, '1000.00', $date(-9));

        $this->title('demo-fin-overdue', ['kind' => 'receivable', 'description' => 'Assessoria — Mercado do Bairro', 'amount' => '650.00', 'competence_date' => $date(-35), 'due_date' => $date(-15), 'finance_category_id' => $this->category('income', 'Prestação de serviços')]);
        $project = $this->title('demo-fin-project', ['kind' => 'receivable', 'description' => 'Projeto de implantação', 'amount' => '3000.00', 'competence_date' => $date(-5), 'due_date' => $date(5), 'installments' => 3, 'finance_category_id' => $this->category('income', 'Prestação de serviços')]);
        $this->settle('demo-fin-project-1', $project[0], $bank, '1000.00', $date(-1), ['interest' => '0', 'discount' => '20.00']);

        $energy = $this->title('demo-fin-energy', ['kind' => 'payable', 'description' => 'Conta de energia', 'amount' => '287.43', 'competence_date' => $date(-12), 'due_date' => $date(-2), 'finance_category_id' => $this->category('expense', 'Energia')]);
        $this->settle('demo-fin-energy-paid', $energy[0], $bank, '287.43', $date(-2), ['fine' => '5.75', 'interest' => '1.20']);
        $taxes = $this->title('demo-fin-taxes', ['kind' => 'payable', 'description' => 'DAS do Simples Nacional', 'amount' => '412.90', 'competence_date' => $date(-25), 'due_date' => $date(-5), 'finance_category_id' => $this->category('expense', 'Impostos')]);
        $this->title('demo-fin-marketing', ['kind' => 'payable', 'description' => 'Anúncios em redes sociais', 'amount' => '600.00', 'competence_date' => $date(0), 'due_date' => $date(12), 'finance_category_id' => $this->category('expense', 'Marketing')]);

        $duplicated = $this->settle('demo-fin-taxes-duplicated', $taxes[0], $cash, '412.90', $date(-5));

        if ($duplicated->status->value === 'confirmed') {
            app(FinanceSettlementService::class)->reverse($duplicated, $this->user, 'Pagamento lançado no caixa por engano; foi pago pelo banco.');
        }

        $this->settle('demo-fin-taxes-bank', $taxes[0]->refresh(), $bank, '412.90', $date(-5));

        if (! FinanceTransfer::query()->where('company_id', $this->company->id)->where('idempotency_key', 'demo-fin-transfer')->exists()) {
            app(FinanceTransferService::class)->transfer($this->company, $this->user, ['from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'amount' => '200.00', 'transferred_at' => $date(-3), 'description' => 'Depósito do caixa no banco', 'idempotency_key' => 'demo-fin-transfer']);
        }

        if (! FinanceMovement::query()->where('company_id', $this->company->id)->where('idempotency_key', 'adjustment:demo-fin-adjustment')->exists()) {
            app(FinanceAccountService::class)->adjust($cash, $this->user, '145.00', $date(0), 'Diferença na contagem do caixa', 'demo-fin-adjustment');
        }

        foreach ([
            ['payable', 'Aluguel da sala', '2200.00', 'monthly', 'Aluguel', 5],
            ['payable', 'Internet fibra', '129.90', 'monthly', 'Internet', 10],
            ['receivable', 'Mensalidade de suporte — Clínica Vida', '450.00', 'monthly', 'Prestação de serviços', 15],
        ] as [$kind, $description, $amount, $frequency, $category, $day]) {
            if (! FinanceRecurrence::query()->where('company_id', $this->company->id)->where('description', $description)->exists()) {
                app(FinanceRecurrenceService::class)->create($this->company, $this->user, [
                    'kind' => $kind, 'description' => $description, 'amount' => $amount, 'frequency' => $frequency,
                    'start_date' => $now->copy()->startOfMonth()->addDays($day - 1)->format('Y-m-d'), 'end_date' => null,
                    'finance_category_id' => $this->category($kind === 'payable' ? 'expense' : 'income', $category),
                ]);
            }
        }
    }

    /**
     * Financeiro contratado depois de Vendas ou Compras: importação assistida dos documentos do período, sem presumir
     * que estejam liquidados. A venda mais antiga é marcada como recebida fora do sistema.
     *
     * @param  list<string>  $sources
     */
    private function laterActivation(string $slug, array $sources): void
    {
        $company = Company::query()->where('slug', $slug)->first();

        if ($company === null) {
            $this->command?->warn("FinanceDemoSeeder: empresa {$slug} não encontrada; execute SalesDemoSeeder antes.");

            return;
        }

        $this->company = $company;
        $this->activate('finance');
        $now = now($company->timezone);
        $account = $this->account('Caixa', 'cash', '500.00', $now->copy()->subMonths(2)->format('Y-m-d'));
        $import = app(FinanceImportService::class);
        $from = $now->copy()->subMonths(2)->format('Y-m-d');
        $until = $now->format('Y-m-d');

        foreach ($sources as $type) {
            $eligible = array_values(array_filter($import->preview($company, $type, $from, $until), fn (array $row): bool => $row['is_eligible']));

            if ($eligible === []) {
                continue;
            }

            $import->import($company, $this->user, $type, $from, $until, array_column($eligible, 'source_id'), $type === 'sales' ? [$eligible[0]['source_id']] : []);
        }

        $open = FinanceTitle::query()->where('company_id', $company->id)->where('kind', 'receivable')->whereIn('status', ['pending', 'partial'])->orderBy('id')->first();

        if ($open !== null) {
            $this->settle("demo-fin-{$slug}-receipt", $open, $account, (string) $open->openBalance()->dividedBy(2, 2, RoundingMode::Down), $now->format('Y-m-d'));
        }
    }

    private function activate(string $identifier): void
    {
        $module = PlatformModule::query()->where('identifier', $identifier)->firstOrFail();
        CompanyModule::query()->firstOrCreate(['company_id' => $this->company->id, 'platform_module_id' => $module->id], ['activated_by' => $this->user->id, 'activated_at' => now()]);
    }

    private function account(string $name, string $type, string $openingBalance, string $openingDate, ?string $institution = null): FinanceAccount
    {
        return FinanceAccount::query()->where('company_id', $this->company->id)->where('name', $name)->first()
            ?? app(FinanceAccountService::class)->create($this->company, $this->user, ['name' => $name, 'type' => $type, 'institution' => $institution, 'opening_balance' => $openingBalance, 'opening_date' => $openingDate, 'notes' => null]);
    }

    private function category(string $kind, string $name): ?int
    {
        return FinanceCategory::query()->where('company_id', $this->company->id)->where('kind', $kind)->where('name', $name)->value('id');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<FinanceTitle>
     */
    private function title(string $key, array $data): array
    {
        $existing = FinanceTitle::query()->where('company_id', $this->company->id)->where('idempotency_key', $key)->orderBy('installment_number')->get();

        return array_values(($existing->isNotEmpty() ? $existing : app(FinanceTitleService::class)->create($this->company, $this->user, [...$data, 'idempotency_key' => $key]))->all());
    }

    /** @param array<string, string> $extra */
    private function settle(string $key, FinanceTitle $title, FinanceAccount $account, string $principal, string $date, array $extra = []): FinanceSettlement
    {
        return FinanceSettlement::query()->where('company_id', $this->company->id)->where('idempotency_key', $key)->first()
            ?? app(FinanceSettlementService::class)->settle($title, $this->user, ['finance_account_id' => $account->id, 'settled_at' => $date, 'method' => 'pix', 'principal' => $principal, 'idempotency_key' => $key, ...$extra]);
    }
}
