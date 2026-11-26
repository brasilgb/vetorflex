# OMNICHANNEL-04 — Resultado

Data: 26/11/2026. Local: `gateway/vetorflex/`. Correio: SHA-256 `002c9c18…`, seções 1 a 28.

## Consolidação anterior

- OMNICHANNEL-03 consolidada no commit `c5272f2` do histórico `.git-local` (branch `main`), sobre `9dd241e` e `d211064`. Histórico preservado; nada foi reescrito.
- Também existe um `.git` com um único commit (`f6724bc first commit`), fora do histórico das etapas. Não foi alterado.
- Referências a `gateway/vetornew/` não existem mais em documentação ou configuração; resta só a menção histórica no `correio.md`. `package-lock.json` passou de `vetornew` para `vetorflex` (alteração já presente no início desta etapa). A conta `demo@vetornew.test`, bancos, containers e serviços não foram renomeados.
- A suíte completa da OMNICHANNEL-03 só foi repetida como regressão; a auditoria não foi refeita.

## Decisões arquiteturais

- **Fornecedor** é o papel `supplier` de `parties`, com perfil próprio (`supplier_profiles`). Situação e observações ficam no perfil: inativar o fornecedor não inativa o cliente. Documento já cadastrado exige confirmação explícita para vincular, sem alterar a pessoa.
- **Integridade entre empresas no banco**: chaves estrangeiras compostas `(company_id, id)` em todas as tabelas de Compras.
- **Snapshots**: fornecedor (nome, documento, contato, endereço) e itens (descrição, SKU, código do fornecedor, unidade) são copiados para o pedido; mudanças posteriores no cadastro não alteram pedidos.
- **Máquina de estados** explícita em `PurchaseOrderStatus`, verificada no backend com o pedido bloqueado. Estoque só muda no recebimento.
- **Transações e bloqueio**: pedido → numeração → unidades comercializáveis em ordem crescente. Recebimento, movimentações, custo médio e último custo gravados numa única transação.
- **Idempotência**: pedidos, recebimentos, devoluções e compra rápida aceitam chave UUID com hash de conteúdo. Repetir devolve o mesmo documento; mudar o conteúdo é recusado.
- **Custo médio** calculado dentro de `InventoryService` (`AverageCostCalculator`), com o mesmo bloqueio; só entradas com valor de aquisição o alteram. O custo cadastrado do produto não muda de significado: o custo médio é uma coluna nova (`inventory_balances.average_cost`).
- **Rateio** fixado na confirmação, pelo valor líquido, com sobras de centavos pelos maiores restos. Recebimentos parciais alocam pela quantidade acumulada, e o último fecha o valor exato.
- **Devolução × estorno**: devolução comercial sai ao custo médio vigente, sem alterá-lo, e guarda o valor histórico. O estorno técnico reverte as entradas e só restaura o custo anterior se não houver movimentação posterior. Nada é recalculado retroativamente.
- **Permissões**: `manage_cost` exige `view_cost`. `purchases.view_cost` não concede `products.view_cost`. A busca de itens de Compras é própria e não exige Produtos nem expõe saldo ou custo médio.
- **PDF sem dependência nova**: um gerador PDF 1.4 mínimo (`Core\Documents`), com leiaute reutilizável para Vendas.
- **Permissões individuais** em tela comum a todos os módulos. Ninguém concede o que não possui; o administrador só gerencia operadores.
- Foi mantido o nome conceitual `purchase_status_history`. Foram acrescentadas `company_sequences`, `supplier_imports` e `inventory_cost_changes`.

## Funcionalidades implementadas

- **Fornecedores**: cadastro completo ou simplificado, PF/PJ, documento validado, inscrições, telefones, WhatsApp, e-mail, endereços, contatos, código interno, prazo, condição preferencial, pedido mínimo, observações, situação. Listagem paginada com pesquisa e filtros, ficha (cadastro, histórico de compras, produtos adquiridos com último preço, histórico de preços), inativação e reativação, importação CSV com prévia e confirmação, exportação CSV protegida contra injection.
- **Fornecedor × produto**: vários fornecedores por unidade comercializável, com código e referência no fornecedor, prazo, quantidade mínima, preferencial único, observações e último custo derivado dos recebimentos.
- **Pedidos**: número sequencial por empresa, datas, responsável, itens (simples, variações e serviços via `ProductCatalog`), custo, descontos por item e geral, frete, seguro, outras despesas, total, condição e forma de pagamento, referência externa, observações. Rascunho, confirmação, cancelamento e encerramento de saldo com histórico e motivo.
- **Recebimentos**: integral, parcial e múltiplos, com conferência, divergências, documento do fornecedor e data. Estorno técnico.
- **Devoluções** parciais ou totais com motivo, valores históricos e saída pelo `InventoryService`.
- **Custo médio ponderado móvel** com histórico (`inventory_cost_changes`), exibido na ficha do produto para quem tem `products.view_cost`.
- **Condições de pagamento**: à vista, a prazo, parcelado, entrada mais parcelas e a definir, com 8 formas e validação da soma dos vencimentos. É apenas previsão.
- **Compra rápida** atômica, com cadastro simplificado de fornecedor, pelos mesmos serviços.
- **PDF** do pedido de compra, sem natureza fiscal, protegido por empresa e permissão, com valores omitidos sem `view_cost`.
- **Indicadores** reais por período: compras, valores pedido, recebido, devolvido e em aberto, pendentes, atrasados, rascunhos, principais fornecedores, produtos mais adquiridos, evolução mensal e variação de custo de aquisição.
- **Interface**: menu Compras (visão geral, pedidos, nova compra, recebimentos, fornecedores, devoluções), filtros por fornecedor, período, situação e item, destaque de atrasados e pendentes, ficha do pedido com abas reais (dados gerais, itens, recebimentos, valores, pagamento, devoluções, histórico). As abas de valores só aparecem com permissão.
- **Permissões individuais**: `/company/members`, para Clientes, Produtos, Compras e módulos futuros, com histórico.
- **RootAdmin**: Compras registrado como implementado, versão 1.0.0, com 13 permissões e dependência de Produtos validada na ativação. Não é ativado automaticamente; disponibilidade comercial e ativação por empresa seguem o fluxo existente.
- **Contrato para Financeiro** (`PurchaseObligationSource`), sem gerar contas a pagar.
- **Seed** de demonstração idempotente, bloqueado fora de `local` e `testing`.

## Migrations

`2026_11_25_170000` (sequências, fornecedores, vínculos, importações), `170001` (pedidos, itens, vencimentos, histórico), `170002` (recebimentos e devoluções) e `170003` (custo médio e histórico de custos, aditiva). Aplicadas no banco local `new` sem remover dados. Migração do zero e seed repetido validados num banco temporário, depois removido.

## Arquivos modificados

- **Backend novo**: `app/Modules/Purchases` (enums, models, serviços, presenter, CSV, documento, contrato financeiro), `app/Modules/Core/{Documents,Services/SequenceService.php,Support/CsvFormat.php}`, `app/Modules/Inventory/{Models/InventoryCostChange.php,Services/AverageCostCalculator.php}`, `app/Modules/Parties/{Services,Concerns}`, `app/Modules/Tenancy/Services/MemberPermissionService.php`, `app/Http/Controllers/{Purchases,Company}`, `app/Http/Requests/Purchases`, `routes/purchases.php`, `config/purchases.php`, factories e `PurchaseDemoSeeder`.
- **Backend alterado**: `InventoryService`, `StockOperation`, `InventoryBalance`, `InventoryMovementController` (bloqueia estorno de movimentação de compra), `ProductCsv` (passa a herdar `CsvFormat`), `ProductPresenter`, `Party`, `PartyRole`, `PlatformModule`, `Company`, `CompanyMembership`, `AuditLog` (docblocks e relação `user`), `HandleInertiaRequests`, `routes/{web,console}.php`, `PlatformModuleSeeder`, `DatabaseSeeder`.
- **Front-end**: `resources/js/pages/{purchases,company}`, `components/purchases`, `lib/date.ts`, `types/purchases.ts`. Alterados: `app-sidebar.tsx`, `confirm-button.tsx` (aceita POST), `use-tenant.ts`, `products/show.tsx` (custo médio), `movement-table.tsx`, `types/{index,products}.ts`.
- **Testes**: `PurchaseCostingTest`, `PurchaseOrderTest`, `PurchaseReceiptTest`, `SupplierManagementTest`, `CompanyMemberPermissionTest`, `PurchaseIndicatorsTest`. Alterados: `tests/Pest.php` (auxiliares) e `CompanyModuleTest`, cuja expectativa mudou: Compras deixou de ser o exemplo de módulo em desenvolvimento e o teste passou a usar Vendas.
- **Documentação**: `PURCHASES.md`, `SUPPLIERS.md`, `INVENTORY-COSTING.md`, `OMNICHANNEL-04.md` (novos); `ARCHITECTURE.md`, `MODULES.md`, `INVENTORY-CORE.md`, `DEVELOPMENT.md`, `OMNICHANNEL-02.md`, `OMNICHANNEL-03.md`.

## Testes realizados

| Verificação | Resultado |
|---|---|
| `php artisan test --compact` (SQLite) | 258 aprovados, 2 ignorados, 0 falhas (46 testes novos) |
| Mesma suíte em MariaDB 11.8 local (banco temporário) | 258 aprovados, 2 ignorados, 0 falhas |
| 14 recebimentos simultâneos de 1 unidade, pedido de 10 | 10 aceitos, 4 recusados, recebido = 10 |
| 12 recebimentos simultâneos com a mesma chave | 1 recebimento |
| Recebimentos simultâneos de pedidos diferentes do mesmo item | Saldo 127 e custo médio 11,485275, igual ao cálculo sequencial |
| Migração do zero e seed repetido (inclusive em outro dia) | Aprovados, sem duplicar |
| `npm run build`, `vp check` nos arquivos novos e Pint | Aprovados |
| PHPStan | 20 apontamentos antigos (eram 26); nenhum nos arquivos desta etapa |
| `tsc` | 12 erros antigos; nenhum nos arquivos novos |
| Servidor local com a conta demo | 13 URLs de Compras e Permissões com 200 e componente correto; PDF (`%PDF-1.4`) e CSV gerados |

Os testes cobrem: transições, idempotência, rollback de recebimento com falha no estoque, recebimento acima do pendente, frações, serviços sem movimentação, estorno, devolução acima do recebido e com estoque insuficiente, rateio e centavos, custo médio com saldo zero e com custo cadastrado, compra rápida atômica, ocultação de custos (telas, PDF, exportação, indicadores), isolamento entre empresas, dependência de Produtos, funcionamento sem Clientes e Financeiro, e escalonamento de privilégios.

O banco temporário foi criado e removido nesta execução. O seed de Compras foi aplicado ao banco local `new`.

## Limitações

- Custo operacional, sem apuração tributária. Entradas manuais sem valor não diluem o custo médio. Detalhes em `INVENTORY-COSTING.md`.
- O PDF não tem logotipo nem fontes incorporadas, e os dados da empresa compradora dependem de `companies.settings`, que ainda não tem tela.
- A importação de fornecedores é síncrona, até 500 linhas.
- O ambiente local usa MariaDB 11.8, não o MySQL 8.4 do `compose.yaml`.

## Pendências

1. **Navegador não testado** (sem Chrome no ambiente): diálogos de recebimento e devolução, geração de parcelas, abas, teclado e responsividade em celular exigem homologação manual.
2. Clientes ainda não usa `PartyService` e recusa documento já cadastrado; o vínculo de papéis parte de Fornecedores.
3. Não há convite de usuários nem troca de papel pelo painel da empresa.
4. Repetir o teste de concorrência no MySQL 8.4 antes da produção.
5. Contas a pagar, pagamentos e conciliação de devoluções ficam para a OMNICHANNEL-06.
6. As demais pendências estão em `docs/OMNICHANNEL-02.md` e `docs/OMNICHANNEL-03.md`.

## Estado do Git

- Nada desta etapa foi commitado: todas as alterações estão no diretório de trabalho, sobre `c5272f2` (`.git-local`, `main`).
- Fora do escopo e não versionados: `correio.md`, `pnpm-lock.yaml`, `yarn.lock`.
- Sem push, deploy ou alteração em outros sistemas.

## Parecer técnico

Critérios de aceite 1 a 20 atendidos e verificados por testes automatizados e pelo servidor. A única ressalva é a parte visual: no critério 20 o build foi aprovado, mas a interação e a responsividade não foram verificadas em navegador. O módulo Compras está apto para homologação manual. A base para Vendas fica pronta: catálogo e estoque com custo médio, numeração, documento comercial em PDF, pessoas compartilhadas e permissões individuais.
