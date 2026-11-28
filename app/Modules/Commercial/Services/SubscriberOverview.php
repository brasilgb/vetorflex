<?php

namespace App\Modules\Commercial\Services;

use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Painel inicial do assinante: somente as aplicações habilitadas, com indicadores reais da empresa (consultas de
 * leitura por `company_id`) e orientações para começar. Nenhum dado fictício é criado.
 */
class SubscriberOverview
{
    /** Entrada, primeiros passos e se a aplicação depende de configuração com credenciais. */
    private const GUIDES = [
        'customers' => ['/customers', ['Cadastre seus clientes ou importe uma planilha CSV.', 'Use os filtros para encontrar clientes por nome ou documento.'], false],
        'products' => ['/products', ['Cadastre produtos e serviços com preço de venda.', 'Informe código de barras e categoria para agilizar as vendas.'], false],
        'purchases' => ['/purchases', ['Cadastre fornecedores.', 'Registre o primeiro pedido de compra e o recebimento.'], false],
        'sales' => ['/sales', ['Crie um orçamento ou uma venda.', 'Confirme a venda para registrar a entrega e o recebimento.'], false],
        'finance' => ['/finance', ['Cadastre suas contas (caixa, banco, carteira digital).', 'Lance as contas a pagar e a receber do mês.'], false],
        'pos' => ['/pos', ['Cadastre um terminal de caixa.', 'Abra o caixa com o troco inicial e registre a primeira venda.'], false],
        'inventory' => ['/inventory', ['Confira o depósito principal.', 'Informe o saldo inicial das mercadorias.'], false],
        'payments' => ['/payments', ['Conecte a conta da empresa no provedor de pagamento (assistente nas configurações).', 'Gere uma cobrança Pix de teste em homologação.'], true],
        'fiscal' => ['/fiscal', ['Preencha os dados fiscais da empresa e confirme-os com seu contador.', 'Envie o certificado digital e emita em homologação antes da produção.'], true],
    ];

    public function __construct(private TrialService $trials) {}

    /** @return array<string, mixed> */
    public function for(Company $company): array
    {
        $modules = PlatformModule::query()->whereHas('companyModules', fn ($query) => $query->where('company_id', $company->id)->whereNull('deactivated_at'))->orderBy('sort_order')->get();
        $monthStart = CarbonImmutable::now($company->timezone)->startOfMonth()->utc();

        return [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'subscription' => $this->trials->summary($company),
            'applications' => array_values($modules->map(fn (PlatformModule $module): array => [
                'identifier' => $module->identifier,
                'name' => $module->name,
                'tagline' => $module->tagline,
                'icon' => $module->icon,
                'href' => self::GUIDES[$module->identifier][0] ?? null,
                'steps' => self::GUIDES[$module->identifier][1] ?? [],
                'needs_setup' => self::GUIDES[$module->identifier][2] ?? false,
                'indicators' => $this->indicators($company, $module->identifier, $monthStart),
            ])->all()),
        ];
    }

    /** @return list<array{label: string, value: int|string}> */
    private function indicators(Company $company, string $identifier, CarbonImmutable $monthStart): array
    {
        $id = $company->id;

        return match ($identifier) {
            'customers' => [['label' => 'Clientes', 'value' => DB::table('party_roles')->where('company_id', $id)->where('role', 'customer')->count()]],
            'products' => [['label' => 'Itens ativos no catálogo', 'value' => DB::table('products')->where('company_id', $id)->where('is_active', true)->count()]],
            'purchases' => [['label' => 'Pedidos de compra em aberto', 'value' => DB::table('purchase_orders')->where('company_id', $id)->whereIn('status', ['confirmed', 'partially_received'])->count()]],
            'sales' => [['label' => 'Vendas no mês', 'value' => DB::table('sale_orders')->where('company_id', $id)->whereNotIn('status', ['draft', 'cancelled'])->where('created_at', '>=', $monthStart)->count()]],
            'finance' => [['label' => 'Contas a receber em aberto', 'value' => DB::table('finance_titles')->where('company_id', $id)->where('kind', 'receivable')->whereIn('status', ['pending', 'partial'])->count()], ['label' => 'Contas a pagar em aberto', 'value' => DB::table('finance_titles')->where('company_id', $id)->where('kind', 'payable')->whereIn('status', ['pending', 'partial'])->count()]],
            'pos' => [['label' => 'Vendas do caixa no mês', 'value' => DB::table('pos_sales')->where('company_id', $id)->where('status', 'completed')->where('completed_at', '>=', $monthStart)->count()]],
            'inventory' => [['label' => 'Depósitos', 'value' => DB::table('inventory_warehouses')->where('company_id', $id)->count()]],
            'payments' => [['label' => 'Cobranças pagas no mês', 'value' => DB::table('payment_charges')->where('company_id', $id)->where('status', 'paid')->where('paid_at', '>=', $monthStart)->count()]],
            'fiscal' => [['label' => 'Documentos autorizados no mês', 'value' => DB::table('fiscal_documents')->where('company_id', $id)->where('status', 'authorized')->where('issued_at', '>=', $monthStart)->count()]],
            default => [],
        };
    }
}
