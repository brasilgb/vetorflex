# Núcleo interno de estoque

Mecanismo compartilhado por Produtos, Compras, Vendas, PDV e Estoque. O módulo comercial Estoque (`STOCK.md`) é a interface sobre este núcleo.

## Regras

- `InventoryService` é o único ponto de escrita de `inventory_balances` e `inventory_movements`.
- Cada operação abre transação e bloqueia primeiro a linha da unidade comercializável, que serve de mutex. Operações com vários itens devem processá-los em ordem crescente de unidade comercializável.
- Movimentações são imutáveis; correções são estornos, e cada movimentação só pode ser estornada uma vez.
- A chave de idempotência é única por empresa e conferida por hash do conteúdo: repetir devolve a movimentação existente; reutilizá-la com outro conteúdo é erro.
- Quantidades têm até 4 casas. Unidades não fracionárias recusam frações.
- Serviços e produtos sem controle de estoque não geram movimentação.

## Operações

| Operação | Método |
|---|---|
| Estoque inicial | `recordInitial`, somente antes da primeira movimentação |
| Entrada e saída | `recordEntry`, `recordExit` |
| Ajuste | `adjustTo`, recebe o saldo contado e grava a diferença |
| Estorno | `reverse` |
| Consulta | `balanceOf`, `balancesOf`, `averageCostOf` |

`StockOperation` leva empresa, unidade comercializável, quantidade, origem, referência, usuário, motivo, chave de idempotência, custo unitário e, nas entradas de compra, o valor total da aquisição (`entryValue`), que alimenta o custo médio (`INVENTORY-COSTING.md`). Movimentações de origem `purchase` só são corrigidas em Compras.

## Depósitos (OMNICHANNEL-08)

Cada movimentação pertence a um depósito (`StockOperation::warehouseId`; sem ele, o principal da empresa). `InventoryService` grava `inventory_balances` (total da empresa) e `inventory_warehouse_balances` (por depósito) sob a mesma trava; a soma dos depósitos é sempre o total. O ajuste define o saldo do depósito. `transfer()` registra `transfer_out` e `transfer_in` por item numa transação, sem alterar total nem custo médio. Reservas e custo médio continuam por empresa. Detalhes em `STOCK.md`.

## Estoque negativo

Recusado por padrão. A opção `companies.settings['inventory']['allow_negative']` é lida dentro de `InventoryService::recordLocked`, já sob bloqueio (`StockModule::allowsNegative`); nenhum outro ponto decide sobre isso. Transferências nunca deixam o depósito de origem negativo.

## Extensões previstas (não implementadas)

| Necessidade | Onde estender |
|---|---|
| Reserva | Implementada (`InventoryReservationService`, OMNICHANNEL-05) |
| Sincronização com canais | Evento emitido após o commit de `InventoryService::record` |

## Verificação de concorrência

Em 25/11/2026, em MySQL, com processos simultâneos sobre a mesma unidade comercializável: 20 saídas de 1 com saldo 10 resultaram em 10 aceitas, 10 recusadas e saldo 0; 12 entradas com a mesma chave geraram 1 movimentação; 12 estornos do mesmo lançamento geraram 1 estorno. Saldo igual à soma das movimentações nos três casos. A suíte automatizada não repete esse teste, pois usa SQLite.

Em 26/11/2026, em MariaDB 11.8 local (banco temporário), pelos serviços de Compras: 14 recebimentos simultâneos de 1 unidade em pedido de 10 resultaram em 10 aceitos e 4 recusados; 12 repetições simultâneas com a mesma chave geraram 1 recebimento; recebimentos simultâneos de pedidos diferentes do mesmo item resultaram no custo médio esperado (11,485275).

## Limitação

A imutabilidade é garantida no model, não no banco: SQL direto ainda conseguiria alterar o histórico.

## Reservas

`InventoryReservationService::reserve(empresa, unidade, tipo, referência, quantidade)` define a quantidade reservada por um documento (semântica de "definir", idempotente) e mantém `inventory_balances.reserved_quantity`. Bloqueia a unidade comercializável e o saldo, como `InventoryService`, e exige transação aberta. Só reserva o disponível (saldo − reservas de outros documentos). `availabilityOf` devolve saldo, reservado e disponível. Saídas de `InventoryService` não podem consumir o reservado para outros documentos; quem reservou reduz a reserva antes da saída. Usado por Vendas (`sale_order_item`) e disponível para PDV e lojas virtuais.
