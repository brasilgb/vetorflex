# OMNICHANNEL-08 — Resultado

Data: 27/11/2026. Local: `gateway/vetorflex/`. Correio: SHA-256 `441e1289…`, seções 1 a 19. Detalhes do módulo em `docs/STOCK.md`; resumo da etapa em `docs/OMNICHANNEL-08.md`.

## Consolidação da etapa anterior

- O `correio.md` mudou desde a etapa anterior (passou da OMNICHANNEL-07 para a OMNICHANNEL-08).
- A OMNICHANNEL-07 foi consolidada no commit `3a744c1` (`feat: add point of sale module`) do `.git-local` (`main`), antes de qualquer alteração desta etapa.
- Não havia alterações concorrentes no diretório de trabalho além do `correio.md`.
- A estrutura `.git` / `.git-local` não foi unificada nem alterada. Sem push e sem deploy.

## RootAdmin (§3)

- O proprietário já cadastrou o administrador principal em 26/11/2026, com `php artisan rootadmin:create`. O e-mail está verificado.
- Nenhuma credencial foi criada, pedida ou versionada nesta etapa.
- Os 31 testes do painel continuam aprovados, inclusive o redirecionamento para `/rootadmin` após o login.

## Funcionalidades implementadas

**Estoque 1.0.0** (`inventory`, independente, comercializável sozinho):

- Cadastro simplificado de mercadorias sobre a entidade compartilhada de produto:
  - SKU (gerado se vazio), código interno e código de barras;
  - categoria existente ou nova, unidade (inclusive fracionável);
  - estoque mínimo e máximo, custo, preço opcional;
  - saldo inicial no depósito escolhido.
- Saldos total, reservado e disponível, e saldo por depósito.
- Depósitos e localizações. O principal é criado sob demanda e nunca é inativado.
- Entradas, saídas, ajustes por saldo contado e saldo inicial, por depósito, com justificativa.
- Estorno de lançamentos manuais. O histórico é imutável.
- Transferências entre depósitos: numeradas e idempotentes, sem alterar o saldo total nem o custo médio.
- Inventário (contagem física):
  - criação por depósito, com seleção de mercadorias ou todas;
  - contagens, comparação com o saldo atual e prévia de divergências;
  - aprovação por permissão própria, com um ajuste rastreável por divergência;
  - cancelamento sem ajustes; aprovação repetida recusada.
- Ficha da mercadoria (saldos por depósito, mínimo e máximo, últimas movimentações).
- Filtros por situação:
  - abaixo do mínimo;
  - acima do máximo;
  - sem saldo;
  - com saldo;
  - inativas.
- Estoque negativo configurável (recusado por padrão) e data de início do controle.
- Importação e exportação CSV, com modelo de arquivo.
- Painel com dados reais:
  - mercadorias cadastradas, com saldo e sem saldo;
  - abaixo do mínimo;
  - entradas e saídas no período;
  - sem movimentação;
  - últimas movimentações e distribuição por depósito;
  - valor estimado, somente com permissão de custo e custo conhecido.
- Sete relatórios com CSV: posição, abaixo do mínimo, acima do máximo, sem movimentação, entradas e saídas, saldo por depósito e valor estimado.
- 12 permissões `inventory.*` e menu Estoque com 8 itens, conforme as permissões.

**Pendências estruturais (§15)**:

- **Compras**: deixou de depender comercialmente de Produtos. O recebimento só movimenta estoque com Estoque ou Produtos; sem eles, registra documentos e custos sem saldo. Ganhou cadastro simplificado de item no pedido.
- **Vendas**: tela de configuração de venda sem identificação, com permissão `sales.manage_settings`.
- **Clientes**: cadastro, edição e importação gravam a pessoa por `PartyService`.
- **PHPStan**: os 20 erros antigos foram corrigidos; o projeto inteiro tem 0 erro.

## Funcionalidades reaproveitadas

- `InventoryService` (único ponto de escrita), custo médio, reservas, movimentações imutáveis e idempotentes.
- `ProductService`, catálogo, categorias e unidades.
- Atendimento e devolução de Vendas (também usados pelo PDV).
- Recebimento, devolução e estorno de Compras.
- `CsvFormat`, `SequenceService`, `AuditService`, `tenant.records` e `module.permission`.

Não há um segundo sistema de estoque.

## Decisões arquiteturais

- **Controle de estoque**: o módulo Estoque é a interface comercial do núcleo `Inventory`. O controle fica ativo com Estoque **ou** Produtos (`StockModule`); Produtos já controlava estoque e continua controlando. Vendas, PDV e Compras consultam esse único ponto.
- **Depósitos**: `inventory_balances` segue como saldo total da empresa, e `inventory_warehouse_balances` guarda o saldo por depósito. Os dois são gravados sob a mesma trava, e a soma dos depósitos é sempre o total. Operações sem depósito usam o principal.
- **Compatibilidade**: o depósito só entra no hash de idempotência quando informado, e nunca nos estornos; as chaves antigas continuam válidas.
- **Transferências**: dois registros por item (`transfer_out`/`transfer_in`). Nunca deixam a origem negativa e se corrigem com outra transferência.
- **Inventário**: a aprovação compara com o saldo no momento da aprovação. Cada ajuste tem chave determinística, o que impede duplicidade.
- **Isolamento**: a empresa vem sempre do contexto do usuário. Registros de outra empresa dão 404, e as chaves compostas `(company_id, id)` também valem nas novas tabelas.
- **Financeiro**: movimentações físicas não geram lançamentos financeiros.
- **Catálogo**: Estoque registrado como implementado 1.0.0, sem dependências, indisponível comercialmente até decisão do RootAdmin e sem ativação automática nas empresas.

## Migrations

- `2026_11_29_100000_create_stock_tables`, aditiva:
  - tabelas `inventory_warehouses`, `inventory_warehouse_balances`, `inventory_transfers`, `inventory_counts` e `inventory_count_items`;
  - colunas de depósito em `inventory_movements` e `maximum_stock` em `product_variants`;
  - depósito principal criado para as empresas que já tinham saldo, assumindo saldo e histórico.
- **Correção**: três nomes de chave passavam de 64 caracteres. A primeira aplicação no MariaDB local falhou e deixou duas tabelas vazias, criadas por essa tentativa. As tabelas foram removidas, os nomes passaram a ser explícitos e a migration foi reaplicada sem erro. O SQLite dos testes não tem esse limite.
- **Verificação no MariaDB local**: 2 depósitos principais, 9 saldos iguais à soma dos depósitos e nenhuma movimentação sem depósito.
- **Seeders locais**: `PlatformModuleSeeder` e `StockDemoSeeder`, este rodado duas vezes sem duplicar (47 movimentações, 2 transferências, 2 inventários). Compras ficou sem dependências no catálogo.

## Arquivos relevantes

- **Backend novo**:
  - `app/Modules/Inventory`: `StockModule`, `WarehouseService`, `StockItems`, `InventoryCountService`, `StockReports`, `StockImportService`, modelos, enums `InventoryPermission`/`CountStatus`, `StockCsv`, `StockPresenter`;
  - `app/Http/Controllers/Inventory/Stock/*`, `routes/inventory.php`, `config/inventory.php`;
  - `app/Http/Controllers/Sales/SalesSettingsController.php`;
  - `database/seeders/StockDemoSeeder.php`.
- **Backend alterado**:
  - `InventoryService`, `StockOperation`, `MovementType`, `MovementOrigin`, `InventoryMovement`;
  - `SalesStock`, `PurchaseReceiptService`, `PurchaseOrderController` (item simplificado), `SaleItems`, `SalePermission`;
  - `ProductService`, `ProductVariant`, `ProductPresenter`;
  - `CustomerController` e requisições de Clientes;
  - `PosRegisterController`, `InventoryMovementController`, `StoreInventoryMovementRequest`;
  - `User`, `Company`, `CompanyModule`, `PlatformModule`, `TenantContext`, `ActiveCompanyController`;
  - `PlatformModuleSeeder`, `DatabaseSeeder`, `routes/web.php`, `routes/sales.php`, `routes/purchases.php`.
- **Front-end**:
  - `pages/inventory/{dashboard,movements,transfers,warehouses,reports,settings}.tsx`;
  - `pages/inventory/items/{index,show}.tsx`, `pages/inventory/counts/{index,show}.tsx`;
  - `components/inventory/*`, `types/inventory.ts`;
  - `pages/sales/settings.tsx`, `components/purchases/quick-item-dialog.tsx`, `order-editor.tsx`, `app-sidebar.tsx`.
- **Testes**:
  - novos: `StockModuleTest`, `StockCountTest`, `StockIntegrationTest`, `StockDemoSeederTest`;
  - alterados: `tests/Pest.php` (auxiliares), `PurchaseOrderTest`, `PurchaseReceiptTest`, `PosIntegrationTest`, `SalesPagesTest`.
- **Documentação**:
  - novos: `STOCK.md` e `OMNICHANNEL-08.md`;
  - atualizados: `MODULES.md`, `ARCHITECTURE.md` e `INVENTORY-CORE.md`.

## Testes realizados

- **Estoque**: 36 testes, 368 asserções. Cobrem:
  - estoque independente, isolamento empresarial e permissões;
  - entradas e saídas por depósito, estoque negativo configurável;
  - transferências (idempotência e origem nunca negativa);
  - inventários (aprovação única, saldo do momento, cancelamento);
  - estorno único; integrações com Compras, Vendas, PDV e Financeiro;
  - ausência de baixa duplicada com PDV e Vendas juntos;
  - ativação posterior, preservação após desativação, CSV, relatórios, telas e demonstração.
- **Outros**: Vendas (tela de configuração) e Compras (item simplificado com e sem controle de estoque).
- **Regressão completa**: **471 aprovados, 2 ignorados, 0 falhas** (473 testes, 3.703 asserções).
- **Verificações**:
  - `npm run build` aprovado;
  - `tsc --noEmit` sem erros;
  - PHPStan do projeto inteiro com 0 erro;
  - Pint aplicado;
  - `vp check` sem erros nos arquivos desta etapa. Outros 25 arquivos de etapas anteriores, não alterados aqui, têm apenas avisos de formatação.

## Limitações

- Não houve teste em navegador (não há Chrome no ambiente). As telas foram verificadas só por build, tipos e testes de servidor.
- Reservas e custo médio são por empresa, não por depósito. Vendas, PDV e Compras movimentam o depósito principal.
- A importação CSV só cria mercadorias novas; não atualiza cadastros nem saldos existentes.
- Sem lotes, validade, número de série, WMS ou etiquetas (fora do escopo).
- A concorrência com depósitos não foi medida no MySQL 8.4, que não está disponível no ambiente.

## Pendências

1. Homologação visual e de uso em navegador, inclusive no celular.
2. Validação de concorrência no MySQL 8.4, quando disponível: saídas e transferências simultâneas no mesmo depósito.
3. Escolha de depósito em Compras (recebimento), Vendas e PDV, se necessária.
4. Formatação de 25 arquivos de front-end de etapas anteriores (somente estilo).

## Estado do Git

- `.git-local` (`main`): OMNICHANNEL-07 em `3a744c1`. Tudo da OMNICHANNEL-08 está no diretório de trabalho, **sem commit**.
- `.git`: último commit `7ef80d4`. As OMNICHANNEL-05 a 08 estão sem commit ali.
- Não versionados, como antes: `correio.md`, `pnpm-lock.yaml` e `yarn.lock`. Sem push, sem deploy e sem alteração em outros sistemas.

## Critérios (§4 a §19)

| Seção | Situação |
|---|---|
| 2. Consolidação anterior | Atendido (commit `3a744c1`) |
| 3. RootAdmin | Atendido (já cadastrado pelo proprietário; testes aprovados) |
| 4–5. Desacoplamento e reaproveitamento | Atendido (núcleo único; Estoque sem dependências) |
| 6. Módulo Estoque | Atendido |
| 7. Movimentações | Atendido |
| 8. Inventário | Atendido |
| 9. Integrações opcionais | Atendido |
| 10. Ativação posterior | Atendido |
| 11. Dashboard | Atendido |
| 12. Interface | Atendido por build e renderização; sem homologação em navegador |
| 13. Permissões e segurança | Atendido |
| 14. RootAdmin (módulo) | Atendido |
| 15. Pendências estruturais | Atendido |
| 16. Demonstração | Atendido |
| 17. Testes | Atendido; concorrência no MySQL 8.4 pendente por indisponibilidade |
| 19. Entrega | Atendido |

## Parecer técnico

A OMNICHANNEL-08 está implementada e verificada por testes automatizados, build, PHPStan e migração real no MariaDB, com duas ressalvas: as telas não foram observadas em navegador, e a concorrência não foi medida no MySQL 8.4. A OMNICHANNEL-09 não foi iniciada.
