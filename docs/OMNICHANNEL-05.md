# OMNICHANNEL-05

Entrega: módulo Vendas 1.0.0 (`SALES.md`) e reservas de estoque no domínio Inventory (`INVENTORY-CORE.md`).

## Navegação

Menu **Vendas**: Visão geral, Orçamentos, Novo orçamento, Pedidos, Nova venda, Atendimentos e Devoluções, conforme as permissões. A nova venda abre com "venda rápida" marcada (confirmar, atender e concluir ao registrar); desmarcada, salva rascunho.

## Ajustes em recursos compartilhados

- `PaymentCondition`, `PaymentMethod`, `PaymentSchedule` e o cálculo monetário passaram de Compras para `Core\Commerce` (`CommercialMath`). `PurchaseMath` continua existindo como subclasse, sem mudança de regra.
- `RemoteLookup` e `ReasonDialog` saíram de `components/purchases` para `components/`.
- `InventoryService`: saídas passam a respeitar reservas. Ajustes de contagem e estornos não são bloqueados por elas.
- `PurchaseObligationSource` e `SaleReceivableSource` vinculados no container.
- O teste do catálogo de módulos usava Vendas como exemplo de módulo em desenvolvimento; passou a usar Financeiro.

## Registro para adequação modular

Compras continua com dependência comercial de Produtos (`dependencies: products`). Não foi alterada nesta etapa para não ampliar o escopo; o mesmo arranjo de Vendas (catálogo compartilhado, estoque só com Produtos) pode ser aplicado depois.
