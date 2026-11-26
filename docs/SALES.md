# Módulo Vendas (`sales`) 1.0.0

Orçamentos, pedidos de venda (diretos, convertidos de orçamento e venda rápida), atendimentos integrais e parciais, cancelamentos, devoluções, condições de pagamento previstas, custo e margem históricos, indicadores e documentos em PDF.

**Não depende comercialmente de nenhum outro módulo** (`dependencies: []`, `optional_integrations: customers, products, finance`). Uma empresa que contrata só Vendas cadastra clientes e itens simplificados e vende normalmente, sem estoque.

## Dependências técnicas × comerciais

| Recurso | Fonte | Exige o módulo contratado? |
|---|---|---|
| Pessoas e papel `customer` | `Parties` (`PartyService`) | Não. Clientes só adiciona as telas de gestão |
| Itens comercializáveis | Catálogo compartilhado (`ProductCatalog`, `ProductService`) | Não. Produtos só adiciona as telas de gestão |
| Estoque, reservas e custo médio | `Inventory` | **Sim: o estoque só é usado com Produtos habilitado** (`SalesStock`) |
| Numeração, auditoria, PDF, CSV, condições de pagamento | `Core` | — |

Sem Produtos habilitado, Vendas não cria reservas nem movimentações, nem mesmo para itens que controlam estoque, e os itens cadastrados em Vendas nascem sem controle de estoque. As telas de Produtos e Clientes continuam respondendo 403 a quem não tem esses módulos.

## Estrutura

| Tabela | Conteúdo |
|---|---|
| `sale_quotes` / `sale_quote_items` | Orçamento: número por empresa, cliente e snapshot, responsável, emissão, validade, valores, condições comerciais |
| `sale_orders` / `sale_order_items` | Pedido: origem, orçamento de origem (único), cliente e snapshot, responsável, datas, entrega, valores, pagamento, observações comerciais e internas, valor líquido rateado por item, quantidades atendidas e devolvidas |
| `sale_payment_schedules` | Vencimentos previstos de orçamentos e pedidos |
| `sale_status_history` | Transições com usuário, data, situação anterior e nova, motivo |
| `sale_fulfillments` / `_items` | Atendimentos: forma, data, receita, custo histórico e movimentação gerada |
| `sale_returns` / `_items` | Devoluções: motivo, reposição ao estoque, valor e custo históricos |
| `inventory_reservations` | Reservas por documento (domínio Inventory, reutilizável) |

Chaves estrangeiras compostas `(company_id, id)` em todas as tabelas. Item avulso não tem unidade comercializável (`product_variant_id` nulo). Venda sem cliente tem `customer_party_id` nulo, quando a empresa permite (`companies.settings.sales.allow_anonymous_customer`, padrão `config/sales.php`).

## Estados

Orçamento: Rascunho → Emitido → Aprovado → Convertido; Emitido → Recusado ou Expirado; Rascunho, Emitido e Aprovado → Cancelado. Expiração diária (`sales:expire-quotes`, 00:10) e na tentativa de aprovar ou recusar fora da validade.

| Venda: de | Para |
|---|---|
| Rascunho | Confirmado, Cancelado |
| Confirmado | Parcialmente atendido, Atendido, Cancelado (sem atendimentos) |
| Parcialmente atendido | Atendido (atendimento final ou saldo encerrado), Confirmado (estorno) |
| Atendido | Concluído; Parcialmente atendido ou Confirmado, somente por estorno |
| Concluído, Cancelado | — (devoluções continuam possíveis em Concluído) |

A situação é comercial e de atendimento. A situação financeira pertence ao Financeiro: concluir não marca a venda como paga.

## Regras

- **Cálculos no servidor**: totais enviados são ignorados. Total do item = quantidade × preço (centavos) − desconto; desconto percentual vira valor sobre o bruto. Total = itens − desconto geral + frete + outras despesas.
- **Preço**: sem `sales.change_prices`, o preço é o efetivo do catálogo (promocional, se houver) ou o já gravado no item. Sem `sales.apply_discounts`, descontos enviados são descartados e os gravados, preservados. Item sem preço não é vendido pelo catálogo.
- **Snapshots**: cliente (nome, documento, contato, endereço) e item (descrição, SKU, unidade, preço de catálogo e praticado). Mudanças posteriores não alteram documentos.
- **Conversão** idempotente: o pedido guarda `sale_quote_id` (único); repetir devolve o mesmo pedido.
- **Idempotência**: pedidos, orçamentos, atendimentos e devoluções aceitam chave UUID com hash do conteúdo.
- **Bloqueios**: pedido → numeração → unidades comercializáveis em ordem crescente (reserva e estoque).
- **Reserva**: na confirmação, com estoque habilitado, cada item que controla estoque reserva o pendente. Atendimento consome a reserva antes da saída; cancelamento e encerramento de saldo a liberam; estorno a restaura. Estoque negativo não é permitido: só se reserva o disponível (saldo − reservas de outros documentos), e saídas manuais também respeitam reservas.
- **Custo e margem**: o custo do item atendido é o custo médio vigente no atendimento, gravado e nunca recalculado. Sem custo médio, o custo é indisponível (nulo) e a margem do pedido não é estimada. Receita líquida = valor do item após rateio do desconto geral (frete e despesas não entram).
- **Devolução**: só do que foi atendido e ainda não devolvido, pela parcela histórica de receita e custo. Com reposição, a entrada no estoque leva o custo histórico como valor. **Estorno técnico** de atendimento desfaz saídas e quantidades, só antes da conclusão e sem devoluções.
- **Clientes**: busca por nome, documento ou telefone; cadastro simplificado pelo `PartyService`. Documento já cadastrado (por exemplo, de fornecedor) exige confirmação e só acrescenta o papel, sem alterar dados.

## Permissões

`sales.view`, `create`, `update`, `confirm` (confirmar e concluir), `cancel` (e encerrar saldo), `manage_quotes`, `approve_quotes`, `fulfill`, `return`, `apply_discounts`, `change_prices`, `custom_items`, `register_customers`, `register_items`, `view_cost`, `view_margin` (exige também `view_cost`), `export`, `view_indicators`. Concedidas em `/company/members`; não concedem permissões de Produtos, Clientes ou Compras. A venda rápida exige `create`, `confirm` e `fulfill`.

## Contratos e eventos

- `SaleReceivableSource::receivableFor(SaleOrder)` (`SaleReceivables`, vinculado no container) devolve `SaleReceivable`: empresa, cliente, venda, situação, valor, atendido, devolvido, parcelas e devoluções, com chaves idempotentes. Nulo para rascunhos. Vendas não grava tabelas financeiras.
- Eventos (`ShouldDispatchAfterCommit`): `QuoteApproved`, `QuoteConverted`, `SaleConfirmed`, `SaleFulfilled`, `SaleCompleted`, `SaleCancelled`, `SaleReturnRegistered`, `SaleBalanceClosed` e `SaleFulfillmentReversed`. O Financeiro ouve confirmação, cancelamento, devolução, encerramento de saldo e estorno de atendimento (`FINANCE.md`); sem Financeiro habilitado, nada acontece.
- Origens `pos`, `ecommerce` e `integration` já existem em `SaleOrigin` para PDV, lojas e integrações.

## Documentos

PDF de orçamento, pedido, comprovante de atendimento e devolução (`SaleDocument`, sobre `Core\Documents`), sem natureza fiscal, gerados a cada acesso, protegidos por empresa e permissão. **Nunca contêm custo ou margem.** Exportação CSV de vendas com proteção contra fórmulas, sem custos.

## Demonstração

`SalesDemoSeeder` (local e testing): na empresa de demonstração, orçamento pendente, orçamento convertido, venda direta, venda de serviços, venda parcialmente atendida, venda concluída com devolução parcial e venda cancelada. Cria também a **Loja Somente Vendas**, só com Vendas, com cliente e item simplificados e uma venda rápida sem estoque.
