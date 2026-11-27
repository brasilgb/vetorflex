# OMNICHANNEL-08

Entrega: módulo Estoque 1.0.0 (`STOCK.md`), comercializável sozinho, sobre o núcleo `Inventory` existente; correção das pendências estruturais de Compras, Vendas, Clientes e PHPStan.

## Navegação

- Menu **Estoque**: Visão geral, Mercadorias, Movimentações, Transferências, Depósitos, Inventários, Relatórios e Configurações, conforme as permissões `inventory.*`.
- Menu **Vendas**: novo item Configurações (`sales.manage_settings`).

## Reaproveitado

- `InventoryService` (único ponto de escrita), `AverageCostCalculator`, `InventoryReservationService`, `StockOperation`, movimentações imutáveis e idempotentes.
- `ProductService`, `ProductCatalog`, unidades e categorias: mercadorias do Estoque são os mesmos produtos de Produtos, Compras, Vendas e PDV.
- `SaleFulfillmentService`, `SaleReturnService` e o recebimento de Compras continuam movimentando o estoque pelo mesmo serviço.
- `CsvFormat`, `SequenceService`, `AuditService`, `tenant.records`, `module.permission`.

## Ajustes em recursos compartilhados

- `InventoryService`: depósito por movimentação, saldo por depósito, `transfer()`, estoque negativo configurável e tipos `transfer_out`/`transfer_in`; origem `count`. As chaves de idempotência anteriores continuam válidas: o depósito só entra no hash quando informado, e nunca nos estornos.
- `StockModule::isEnabled` substitui a regra "estoque = Produtos" em `SalesStock` (Vendas e PDV) e passa a controlar o recebimento de Compras.
- Compras: dependência comercial de Produtos removida do catálogo; recebimento sem controle de estoque não movimenta; novo `POST /purchases/items` (cadastro simplificado de item no pedido).
- Vendas: tela de configuração de venda sem identificação (`/sales/settings`) e permissão `sales.manage_settings`.
- Clientes: cadastro, edição e importação passam a gravar a pessoa por `PartyService`.
- Produtos: o formulário de movimentação recusa os tipos de transferência (antes, eles chegariam a um `match` sem tratamento).

## Correções

- Migration com nomes de chave acima de 64 caracteres no MySQL/MariaDB (o SQLite dos testes não tem esse limite): falhou na primeira aplicação local; as duas tabelas vazias criadas pela tentativa foram removidas e os nomes passaram a ser explícitos.
- PHPStan: os 20 erros antigos (Clientes, Tenancy, modelos sem tipos genéricos) e os novos foram corrigidos; a análise do projeto inteiro passou a 0 erro.

## RootAdmin

O administrador principal já foi cadastrado pelo proprietário com `php artisan rootadmin:create` em 26/11/2026; nenhuma credencial foi criada ou versionada nesta etapa. Os testes do painel e do redirecionamento após o login continuam aprovados. O módulo Estoque aparece no catálogo como implementado 1.0.0, sem dependências, indisponível comercialmente até decisão do RootAdmin e sem ativação automática nas empresas.
