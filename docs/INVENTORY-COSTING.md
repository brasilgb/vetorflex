# Formação de custos do estoque

Custo médio ponderado móvel por unidade comercializável, calculado por `AverageCostCalculator` dentro de `InventoryService`, na mesma transação e sob o mesmo bloqueio da movimentação.

## Quatro valores distintos

| Valor                      | Onde fica                                                                   | Origem                                                                                            | Quem vê                                 |
| -------------------------- | --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- | --------------------------------------- |
| Custo cadastrado           | `product_variants.cost_price`                                               | Informado no cadastro do produto                                                                  | `products.view_cost`                    |
| Último custo de compra     | `supplier_products.last_cost` e `purchase_receipt_items.purchase_unit_cost` | Preço negociado, líquido do desconto do item, do último recebimento confirmado daquele fornecedor | `purchases.view_cost`                   |
| Custo efetivo da aquisição | `purchase_receipt_items.unit_cost` e `acquisition_total`                    | Preço negociado mais o rateio de frete, seguro e despesas, menos o desconto geral                 | `purchases.view_cost`                   |
| Custo médio do estoque     | `inventory_balances.average_cost`                                           | Recebimentos confirmados                                                                          | `products.view_cost` (ficha do produto) |

O preço de venda nunca é alterado por Compras.

## Fórmula

Novo custo médio = (saldo anterior × custo médio anterior + valor efetivo da aquisição) ÷ saldo resultante.

- Calculado com `Brick\Math`, sem ponto flutuante. Custo médio com 6 casas, quantidades com 4 e valores em reais com 2, arredondamento meio para cima.
- Somente entradas que informam `StockOperation::entryValue` mudam o custo. Hoje, apenas recebimentos de compra. Entradas manuais, ajustes, saídas e estoque inicial preservam o custo médio vigente.
- Saldo anterior igual a zero: o custo passa a ser o custo efetivo da aquisição.
- Cada alteração grava `inventory_cost_changes` (imutável): quantidade e custo anteriores, origem do custo anterior, valor da entrada, novo custo, movimentação, referência, usuário e data.

## Transição do custo cadastrado

A coluna `average_cost` nasce nula; nenhum dado existente foi alterado. No primeiro recebimento de um item que já tinha saldo:

- havendo custo cadastrado, o saldo anterior é valorizado por ele (`previous_cost_source = registered`), uma única vez;
- sem custo cadastrado, o saldo anterior assume o custo desta aquisição (`none`).

Daí em diante vale o custo médio (`average`). O custo cadastrado continua editável em Produtos e segue sendo a referência de margem e markup; o custo médio é exibido ao lado, sem substituí-lo. O valor estimado do estoque em Produtos continua usando o custo cadastrado.

## Estornos e devoluções

- **Estorno técnico** (recebimento lançado por engano, `purchases.return`): reverte as entradas pelo `InventoryService`. O custo anterior só é restaurado quando a entrada estornada é a última movimentação do item; havendo movimentações posteriores, o custo vigente é mantido, porque elas já o utilizaram. Recebimentos com devolução não podem ser estornados.
- **Devolução comercial** ao fornecedor: saída valorizada pelo custo médio vigente (`purchase_return_items.stock_value`); o custo médio não muda. O valor histórico da aquisição devolvida (`historical_value`) é registrado à parte, para a futura conciliação financeira.
- Nada é recalculado retroativamente: vendas, saídas e movimentações posteriores mantêm os custos do momento em que ocorreram.

## Concorrência

O cálculo usa o saldo e o custo já bloqueados por `InventoryService`. Recebimentos simultâneos do mesmo item são serializados e produzem o mesmo resultado que em sequência.

## Limitações

- Custo operacional, sem apuração tributária (créditos de ICMS, IPI, PIS/COFINS ou substituição tributária) nem contabilidade fiscal.
- Entradas manuais sem valor não diluem o custo médio: são tratadas como entradas ao custo vigente.
- Itens sem controle de estoque e serviços não têm custo médio; o custo de aquisição fica apenas no recebimento.
- Diferenças entre o valor histórico e o custo médio nas devoluções não são lançadas em lugar algum até o módulo Financeiro.
