# Núcleo interno de estoque

Mecanismo compartilhado por Produtos e pelos futuros Compras e Vendas. O estoque avançado será um módulo comercial à parte.

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
| Consulta | `balanceOf`, `balancesOf` |

`StockOperation` leva empresa, unidade comercializável, quantidade, origem, referência, usuário, motivo, chave de idempotência e custo unitário.

## Estoque negativo

Sempre recusado. Para torná-lo configurável: ler a opção de `companies.settings` dentro de `InventoryService::recordLocked`, já sob bloqueio, mantendo a recusa como padrão. Nenhum outro ponto deve decidir sobre isso.

## Extensões previstas (não implementadas)

| Necessidade | Onde estender |
|---|---|
| Custo médio ponderado | `unit_cost` já é gravado; Compras deve informá-lo em `StockOperation` e calcular a média |
| Reserva | Nova tabela, sob o mesmo bloqueio; disponível = saldo − reservas |
| Sincronização com canais | Evento emitido após o commit de `InventoryService::record` |

## Verificação de concorrência

Em 25/11/2026, em MySQL, com processos simultâneos sobre a mesma unidade comercializável: 20 saídas de 1 com saldo 10 resultaram em 10 aceitas, 10 recusadas e saldo 0; 12 entradas com a mesma chave geraram 1 movimentação; 12 estornos do mesmo lançamento geraram 1 estorno. Saldo igual à soma das movimentações nos três casos. A suíte automatizada não repete esse teste, pois usa SQLite.

## Limitação

A imutabilidade é garantida no model, não no banco: SQL direto ainda conseguiria alterar o histórico.
