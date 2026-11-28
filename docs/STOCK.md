# Módulo Estoque (`inventory`) 1.0.0

Mercadorias, saldos por depósito, entradas e saídas, transferências, inventários (contagem física), alertas de estoque mínimo e máximo, relatórios e CSV, para MEIs e pequenos negócios. O núcleo de escrita continua sendo o `Inventory` (`INVENTORY-CORE.md`, `INVENTORY-COSTING.md`); o módulo Estoque é a interface comercial sobre ele, sem um segundo sistema de estoque.

## Independência

Sem dependências comerciais (`dependencies: []`). Integra-se opcionalmente a Produtos, Compras, Vendas, PDV e Financeiro. Uma empresa só com Estoque cadastra mercadorias, movimenta, transfere e faz inventário sem contratar Produtos, Compras, Vendas, Financeiro ou PDV, e as rotas desses módulos continuam proibidas para ela.

**Controle de estoque** (`StockModule::isEnabled`): ativo com o módulo Estoque **ou** com Produtos (que já controlava estoque antes desta etapa e continua controlando). Sem nenhum dos dois, Compras, Vendas e PDV não criam reservas nem movimentações, nem ocultas. `SalesStock` (Vendas e PDV) e o recebimento de Compras consultam esse mesmo ponto.

## Estrutura

`app/Modules/Inventory`: `Data`, `Enums`, `Models`, `Services`, `Support`. Controladores em `app/Http/Controllers/Inventory/Stock`, rotas em `routes/inventory.php` (`/inventory`), configuração em `config/inventory.php`, telas em `resources/js/pages/inventory`, componentes em `resources/js/components/inventory`.

| Tabela                                      | Conteúdo                                                                                                                                                                      |
| ------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `inventory_warehouses`                      | Depósitos e localizações: nome, código único por empresa, localização, situação. Um principal por empresa (`default_company_id` único), criado sob demanda e nunca inativado. |
| `inventory_warehouse_balances`              | Saldo por depósito e unidade comercializável. A soma dos depósitos é sempre `inventory_balances.quantity`.                                                                    |
| `inventory_transfers`                       | Transferências numeradas entre depósitos, com chave de idempotência.                                                                                                          |
| `inventory_counts`, `inventory_count_items` | Inventários: depósito, situação, contagens, saldo do sistema na aprovação, diferença e movimentação de ajuste.                                                                |
| `inventory_movements` (colunas novas)       | `inventory_warehouse_id` e `warehouse_balance_after`.                                                                                                                         |
| `product_variants.maximum_stock`            | Estoque máximo opcional.                                                                                                                                                      |

Mercadorias são as mesmas `products`/`product_variants` de Produtos, Compras, Vendas e PDV. O cadastro simplificado (`StockItems::create`) usa `ProductService`.

## Serviços

| Serviço                           | Responsabilidade                                                                                                                                                                         |
| --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `InventoryService`                | Único ponto de escrita de saldo. Agora grava saldo total e por depósito sob a mesma trava; `transfer()` registra saída e entrada por item numa transação; estoque negativo configurável. |
| `WarehouseService`                | Depósito principal sob demanda, resolução e validação de depósito da empresa, cadastro.                                                                                                  |
| `StockModule`                     | Habilitação do controle, configurações (`allow_negative`, `started_at`) e data de início do controle.                                                                                    |
| `StockItems`                      | Consulta de mercadorias com saldo total, reservado e por depósito; cadastro simplificado; estoque mínimo e máximo.                                                                       |
| `InventoryCountService`           | Criação, contagem, aprovação e cancelamento de inventários.                                                                                                                              |
| `StockReports`                    | Indicadores do painel e relatórios.                                                                                                                                                      |
| `StockImportService` / `StockCsv` | Importação e exportação CSV.                                                                                                                                                             |

## Regras

- **Movimentação**: empresa, mercadoria, depósito, tipo, quantidade (até 4 casas; frações só em unidades fracionáveis), origem, referência ao documento, usuário, data e hora, justificativa (obrigatória em saídas e ajustes manuais) e chave de idempotência. Histórico imutável: correções por estorno ou movimento compensatório.
- **Depósito**: operações sem depósito informado (Compras, Vendas, PDV, cadastro de Produtos) usam o principal. Depósito inativo recusa movimentações.
- **Ajuste**: informa o saldo contado do depósito; a diferença é gravada.
- **Transferência**: dois registros por item (`transfer_out`, `transfer_in`), sem alterar o saldo total nem o custo médio; nunca deixa a origem negativa, mesmo com estoque negativo permitido. Não é estornada individualmente: corrige-se com outra transferência.
- **Estoque negativo**: recusado por padrão. Com `allow_negative`, saídas acima do saldo são aceitas (inclusive de Vendas e PDV) e a trava de reservas não se aplica; a empresa assume o risco.
- **Estorno**: só de lançamentos manuais; movimentações de Compras, Vendas e inventários são corrigidas na origem.
- **Inventário**: 1) criação com depósito e mercadorias (ou todas); 2) registro das contagens; 3) comparação com o saldo atual do depósito; 4) divergências destacadas; 5) aprovação por quem tem `inventory.approve_counts`; 6) um ajuste por divergência, com chave `inventory-count-item:{id}` e origem `count`; 7) encerramento. A aprovação compara com o saldo do momento da aprovação (movimentos feitos durante a contagem são respeitados). Aprovar de novo é recusado; itens sem contagem não são ajustados; o cancelamento não ajusta nada.
- **Concorrência**: a linha da unidade comercializável continua sendo o mutex; os saldos total e do depósito são travados na mesma ordem; transferências processam os itens em ordem crescente.
- **Isolamento**: a empresa vem sempre do contexto do usuário; registros de outra empresa retornam 404 (`tenant.records`) e depósitos de outra empresa são recusados; chaves compostas `(company_id, id)` impedem vínculo entre empresas no banco.

## Ativação posterior e desativação

- Empresas que começaram com PDV, Compras ou Vendas mantêm todos os registros. Movimentações históricas não são reproduzidas.
- O saldo inicial é informado por mercadoria ("Saldo inicial", só antes da primeira movimentação) ou por importação CSV. A data de início do controle (`started_at`) é registrada no primeiro cadastro ou lançamento e pode ser ajustada nas configurações.
- Empresas que já tinham saldo (Produtos) recebem, pela migration, um depósito principal com todo o saldo e o histórico; nenhuma movimentação é criada.
- Desativar o módulo não apaga dados; reativar mostra os mesmos saldos.

## Integrações

- **Compras**: o recebimento dá entrada (com custo de aquisição) quando há controle de estoque; devoluções e estornos de recebimento geram saídas e estornos compensatórios. Sem controle, Compras registra documentos e custos sem saldo. Compras deixou de depender comercialmente de Produtos e ganhou cadastro simplificado de item no pedido.
- **Vendas e PDV**: reservas e saída na conclusão pelo mesmo serviço (`SaleFulfillmentService`); com os dois módulos habilitados, a venda do PDV é a mesma venda comercial e baixa o estoque uma única vez. Devoluções respeitam a opção de reposição.
- **Financeiro**: nenhuma movimentação física gera receita ou despesa.

## Indicadores e relatórios

Painel: mercadorias cadastradas, com e sem saldo, abaixo do mínimo, entradas e saídas no período, sem movimentação há N dias (`INVENTORY_IDLE_DAYS`, padrão 60), últimas movimentações, distribuição por depósito e valor estimado. Relatórios com CSV: posição de estoque, abaixo do mínimo, acima do máximo, sem movimentação, entradas e saídas por mercadoria, saldo por depósito e valor estimado.

O valor estimado exige `inventory.view_cost` e usa somente o custo médio registrado pelo núcleo; mercadorias com saldo e sem custo conhecido são informadas à parte, nunca estimadas.

## CSV

Exportação com saldo e reservado (custo somente com permissão). Importação (`nome;sku;codigo_barras;codigo_interno;categoria;unidade;estoque_minimo;estoque_maximo;custo;preco_venda;saldo_inicial;deposito`, até 1.000 linhas): cada linha nova cria uma mercadoria com saldo inicial opcional no depósito do código informado; SKU ou código de barras já existentes são ignorados sem alterar saldos. O resultado lista as linhas ignoradas e com erro.

## Permissões

`inventory.view`, `inventory.manage_items`, `inventory.move`, `inventory.transfer`, `inventory.adjust`, `inventory.count`, `inventory.approve_counts`, `inventory.manage_warehouses`, `inventory.view_reports`, `inventory.view_cost`, `inventory.import_export` e `inventory.manage_settings`. Menu **Estoque**: Visão geral, Mercadorias, Movimentações, Transferências, Depósitos, Inventários, Relatórios e Configurações, conforme as permissões.

## Demonstração

`StockDemoSeeder` (local e testing, idempotente): "Depósito Somente Estoque", "Distribuidora Estoque + Compras", "Loja Estoque + Vendas", "Mercearia Estoque + PDV" e, na "Empresa Demonstração" (todos os módulos), Estoque ativado depois com depósito da loja, transferência e inventário.

## Limitações

- Reservas continuam por empresa, não por depósito; Vendas e PDV usam o depósito principal.
- O custo médio é por empresa (não por depósito).
- Sem lotes, validade, número de série, endereçamento de prateleira (WMS) ou etiquetas.
- A importação CSV só cria mercadorias novas; não atualiza cadastros nem saldos existentes.
