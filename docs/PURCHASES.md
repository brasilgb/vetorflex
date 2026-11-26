# Módulo Compras (`purchases`) 1.0.0

Pedidos de compra, recebimentos totais e parciais, entrada de mercadorias, formação de custo, devoluções, condições de pagamento, indicadores e documento em PDF. Depende de Produtos; não exige Clientes nem Financeiro. Fornecedores em `SUPPLIERS.md`; custos em `INVENTORY-COSTING.md`.

## Estrutura

| Tabela | Conteúdo |
|---|---|
| `purchase_orders` | Número por empresa, fornecedor e seu snapshot, datas, responsável, valores, condição de pagamento, referência externa, chave de idempotência |
| `purchase_order_items` | Snapshot do item (descrição, SKU, código do fornecedor, unidade), quantidade, custo, desconto, total, custo de aquisição rateado e quantidade recebida |
| `purchase_payment_schedules` | Vencimentos previstos |
| `purchase_status_history` | Transições com responsável, data e motivo |
| `purchase_receipts` / `_items` | Recebimentos, quantidades, custos efetivos, divergências e movimentação gerada |
| `purchase_returns` / `_items` | Devoluções, quantidades, valor histórico e valor de estoque |
| `company_sequences` | Numeração sequencial por empresa (pedido, recebimento, devolução), bloqueada até o fim da transação |

Todas as tabelas têm `company_id` e chaves estrangeiras compostas `(company_id, id)`, que impedem referências entre empresas no próprio banco. Nada é excluído fisicamente.

O nome conceitual `purchase_status_history` foi mantido; o model é `PurchaseStatusChange`. `supplier_imports` foi acrescentada para a importação em duas etapas.

## Estados

| De | Para |
|---|---|
| Rascunho | Confirmado, Cancelado |
| Confirmado | Parcialmente recebido, Recebido, Cancelado (sem recebimentos) |
| Parcialmente recebido | Recebido (recebimento final ou saldo encerrado), Confirmado (estorno) |
| Recebido | Parcialmente recebido ou Confirmado, somente por estorno de recebimento |
| Cancelado | — |

`PurchaseOrderStatus::canTransitionTo` é verificado no backend com o pedido bloqueado. Rascunho, confirmação e cancelamento não movimentam estoque. Só rascunhos são editáveis. Mercadoria recebida exige devolução ou estorno, nunca cancelamento.

A confirmação exige itens, custo informado (ou item marcado como bonificado), fornecedor ativo e vencimentos que somem o total; nela o custo de aquisição de cada item é fixado.

## Rateio

Frete, seguro e outras despesas, menos o desconto geral, são distribuídos pelo valor líquido de cada item. Itens bonificados não recebem rateio, salvo quando todos o são (pedido de valor zero): aí o peso é a quantidade. Sobras de centavos vão aos maiores restos e, no empate, ao item de menor posição. Detalhes em `INVENTORY-COSTING.md`.

## Recebimento

- Integral, parcial e múltiplos recebimentos; quantidade superior à pendente é recusada.
- Pedido, recebimento, movimentações, custo médio e último custo do fornecedor são gravados numa só transação; qualquer falha desfaz tudo.
- Ordem de bloqueio: pedido → numeração → unidades comercializáveis em ordem crescente (dentro de `InventoryService`), evitando deadlocks.
- Chave de idempotência por envio (UUID gerado ao abrir o formulário) com hash do conteúdo: repetir devolve o mesmo recebimento; reutilizar a chave com outro conteúdo é recusado.
- Cada entrada usa `InventoryService::recordEntry` com origem `purchase`, referência `purchase_receipt` e `"{recebimento}:{item}"`, usuário, data e chave `purchase-receipt-item:{id}`.
- Serviços e itens sem controle de estoque são recebidos sem movimentação.
- Em recebimentos parciais, o custo do item é alocado pela quantidade acumulada; o último recebimento fecha o valor exato, sem duplicar despesas.
- Movimentações de compra não podem ser estornadas pela tela de movimentações de Produtos; a correção é feita em Compras.

## Encerramento de saldo

Pedido parcialmente recebido pode ter o saldo encerrado (com motivo) e passa a Recebido. A parcela do custo rateado correspondente ao que não foi entregue não entra no estoque. O estorno de um recebimento reabre o saldo.

## Condições de pagamento

À vista (vencimento único automático), a prazo (1 vencimento), parcelado e entrada mais parcelas (2 a 60), ou a definir. Formas: dinheiro, PIX, boleto, cartão de crédito, cartão de débito, transferência, cheque e outros, por pedido ou por vencimento. A soma dos vencimentos deve ser igual ao total. É previsão comercial: não há cobrança, pagamento nem chamada a gateway.

## Compra rápida

`/purchases/quick`: seleciona ou cadastra o fornecedor (cadastro simplificado), informa itens, custos e pagamento e salva. `QuickPurchaseService` chama, numa única transação, os mesmos `SupplierService`, `PurchaseOrderService::create` e `confirm` e `PurchaseReceiptService::receive`. Exige criar, confirmar, receber e gerenciar custos; cadastrar fornecedor exige também `manage_suppliers`.

## Documento em PDF

`/purchases/orders/{id}/document` gera o pedido a cada acesso autenticado, sem arquivo armazenado nem URL pública (`Cache-Control: private, no-store`). Identificado como "Pedido de compra — documento sem valor fiscal". Sem `view_cost`, o PDF sai sem valores.

Gerado por `Core\Documents\PdfWriter` (PDF 1.4, Helvetica, WinAnsi, sem dependência nova) e pelo leiaute `Core\Documents\CommercialDocument` (emitente, contraparte, tabela, totais e seções), reutilizável por Vendas. Limitações: sem logotipo, sem fontes incorporadas e caracteres fora do Windows-1252 são transliterados. Os dados da empresa compradora vêm do nome e, se existirem, de `companies.settings` (`document`, `address`, `phone`, `email`), que ainda não têm tela de edição.

## Indicadores (`/purchases`)

Período informado (padrão: mês corrente). Compras efetivadas (confirmadas, parcialmente recebidas e recebidas), valor pedido, valor recebido, valor devolvido, pedidos confirmados em aberto, aguardando recebimento, atrasados, rascunhos, principais fornecedores, produtos mais adquiridos (por recebimento), evolução mensal de 12 meses (pedido × recebido) e variação do custo efetivo unitário entre o último recebimento e o anterior. Valores pagos não são exibidos: dependem do Financeiro. Sem `view_cost`, apenas contagens e quantidades.

## Permissões

| Permissão | Uso |
|---|---|
| `purchases.view` | Visão geral, pedidos, recebimentos, devoluções, fornecedores e PDF |
| `purchases.create` | Novo pedido |
| `purchases.update` | Editar rascunhos |
| `purchases.confirm` | Confirmar |
| `purchases.cancel` | Cancelar e encerrar saldo |
| `purchases.receive` | Registrar recebimentos |
| `purchases.return` | Devoluções e estorno de recebimentos |
| `purchases.view_cost` | Valores em telas, PDF, exportações e indicadores |
| `purchases.manage_cost` | Informar custos, despesas e pagamento; exige `view_cost` |
| `purchases.export` | Exportar pedidos |
| `purchases.manage_suppliers` | Cadastrar, editar, inativar fornecedores e vínculos com produtos |
| `purchases.import_suppliers` / `export_suppliers` | Importação e exportação de fornecedores |

Toda rota exige empresa ativa, vínculo ativo, módulo habilitado e a permissão individual (`module.permission`); registros de outra empresa respondem 404 (`tenant.records`). Sem `manage_cost`, custos enviados são descartados e os gravados preservados. `purchases.view_cost` não concede `products.view_cost`: o custo médio do estoque continua restrito a Produtos, e a busca de itens de Compras não expõe saldo nem custo médio.

## Preparação para Financeiro

`Purchases\Contracts\PurchaseObligationSource` (implementado por `PurchaseObligations`) devolve, para pedidos confirmados, um `PurchaseObligation` com empresa, fornecedor, pessoa, pedido, situação, total, valor recebido, valor devolvido, parcelas, origem e chave idempotente `purchase-order:{id}`. Rascunhos não geram obrigação. A obrigação pode nascer na confirmação (compra a prazo negociada) ou no recebimento, conforme a operação; a escolha será do Financeiro (OMNICHANNEL-06). Nenhuma conta a pagar é criada nesta etapa e Compras funciona sem Financeiro.

## Pontos de extensão omnichannel (não implementados)

| Necessidade | Onde estender |
|---|---|
| Pedidos de fornecedores importados ou por API | Novo adaptador que chame `PurchaseOrderService::create` com `external_reference` e `idempotency_key` derivada do identificador externo |
| Recebimento por documento externo (XML fiscal, EDI) | Adaptador que leia o documento, localize o pedido pela referência e chame `PurchaseReceiptService::receive`; `document_reference` guarda a chave do documento |
| Identificadores externos | `external_reference` no pedido e `supplier_code` em `supplier_products`; uma tabela de mapeamento por integração quando houver mais de um |
| Sincronização de custos | Ler `inventory_cost_changes` (imutável e ordenada) a partir do último id processado |
| Disponibilidade por eventos | Evento após o commit de `InventoryService::record`, já previsto em `INVENTORY-CORE.md` |
| Processamento idempotente | Toda escrita aceita chave de idempotência com hash de conteúdo; integrações devem gerar a chave a partir do id de origem |
