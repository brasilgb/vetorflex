# OMNICHANNEL-05 — Resultado

Data: 26/11/2026. Local: `gateway/vetorflex/`. Correio: SHA-256 `bdc04c37…`, seções 1 a 28. Detalhes do módulo em `docs/SALES.md`; resumo da etapa em `docs/OMNICHANNEL-05.md`.

## Consolidação da etapa anterior

- A OMNICHANNEL-04 foi consolidada no commit `8919435` (`feat: add purchases and suppliers module`) do histórico `.git-local` (`main`), sobre `c5272f2`. Antes, a suíte foi rodada uma vez como baseline: 258 aprovados, 2 ignorados.
- Ficaram fora desse commit, como antes: `correio.md`, `pnpm-lock.yaml` e `yarn.lock`.
- A estrutura com `.git` e `.git-local` não foi unificada nem alterada.
- **Ressalva:** antes desta etapa, a pedido do usuário nesta mesma sessão, foi feito um commit (`7ef80d4`) e um **push** no `.git` para `github.com/brasilgb/vetorflex` (`main`), com as alterações da OMNICHANNEL-04. Depois disso, nada mais foi enviado: o trabalho da OMNICHANNEL-05 não tem push nem deploy.

## Funcionalidades implementadas

- **Visão geral** com indicadores reais por período: total vendido, quantidade de vendas, ticket médio, valor devolvido, orçamentos emitidos e aprovados, taxa de conversão, pedidos pendentes, vendas concluídas e canceladas, produtos mais vendidos, principais clientes, vendas por responsável, evolução de 12 meses e margem bruta comercial. A margem só aparece com permissão, e itens sem custo ficam fora do cálculo e são contados à parte. O valor vendido não é apresentado como recebido.
- **Clientes em Vendas**: busca por nome, documento ou telefone. Cadastro simplificado (com endereço opcional) pelo `PartyService`, sem exigir o módulo Clientes. Uma pessoa já cadastrada, como um fornecedor, só recebe o papel `customer` após confirmação, sem ter os dados alterados. Há seleção de endereço de entrega, contato e venda sem identificação, conforme a configuração da empresa. Os dados do cliente ficam gravados no documento.
- **Itens** pelo `ProductCatalog`: produtos simples, variações e serviços, além de itens avulsos (com permissão própria). Quantidade inteira ou fracionária, conforme a unidade, preço, desconto percentual ou em reais, total e observação por item. O preço de catálogo e o praticado ficam gravados. Há cadastro simplificado de item no catálogo compartilhado, sem catálogo paralelo.
- **Orçamentos**: criação, edição em rascunho, emissão, aprovação, recusa, cancelamento, expiração (comando diário `sales:expire-quotes` e verificação na decisão) e conversão em venda idempotente, com referência ao orçamento e sem duplicidade. Não movimentam estoque.
- **Pedidos de venda**: diretos, convertidos de orçamento e venda rápida. Esta última confirma, atende e conclui numa única transação. Os dados incluem origem (com valores já previstos para PDV, loja virtual e integração), referência externa, responsável, datas, entrega, frete, outras despesas, pagamento e observações comerciais e internas. Todos os valores são calculados no servidor.
- **Máquina de estados** explícita (rascunho, confirmado, parcialmente atendido, atendido, concluído, cancelado), com histórico de usuário, data, situação anterior e nova e motivo. Concluir não marca a venda como paga.
- **Estoque (opcional)**: com Produtos habilitado, a confirmação reserva o pendente. O atendimento consome a reserva e registra a saída. Cancelamento e encerramento de saldo liberam a reserva, e o estorno a restaura. Serviços e itens avulsos não movimentam estoque. Sem Produtos, nenhuma reserva ou movimentação é criada.
- **Reservas reutilizáveis** no domínio Inventory (`InventoryReservationService`, `inventory_reservations`, `inventory_balances.reserved_quantity`). As saídas de estoque passaram a respeitar o reservado.
- **Atendimentos**: retirada, entrega ou serviço, integrais, parciais ou múltiplos, com histórico, encerramento justificado do saldo e estorno técnico. São independentes da situação financeira.
- **Cancelamentos e devoluções**: cancelamento de orçamento, de venda e do saldo não atendido. Devoluções parciais ou totais com motivo, referência ao atendimento e reposição opcional ao estoque. A devolução é limitada ao que foi atendido e preserva os valores históricos.
- **Custo e margem**: o custo médio do momento do atendimento fica gravado. Sem custo confiável, a margem aparece como "indisponível", nunca como custo zero. Nada é recalculado depois. Custos e margens são protegidos por permissão.
- **Pagamento previsto**: à vista, a prazo, parcelado, entrada mais parcelas e a definir, com 8 formas, vencimentos, forma por parcela e validação da soma. É apenas previsão.
- **Contrato para o Financeiro**: `SaleReceivableSource` / `SaleReceivable`, com valor, atendido, devolvido, parcelas, devoluções e chaves idempotentes. Vendas não grava em tabelas financeiras.
- **Eventos** publicados após o commit: `QuoteApproved`, `QuoteConverted`, `SaleConfirmed`, `SaleFulfilled`, `SaleCompleted`, `SaleCancelled`, `SaleReturnRegistered`.
- **PDFs** de orçamento, pedido, comprovante de atendimento (com campo de assinatura) e devolução, sem natureza fiscal, nunca com custo ou margem. **Exportação CSV** de vendas.
- **Interface** (Inertia/React): menu Vendas (visão geral, orçamentos, novo orçamento, pedidos, nova venda, atendimentos, devoluções). Listagens paginadas com filtros por cliente, período, responsável, situação, item, origem e forma de pagamento (os filtros de cliente e item vêm por link). A ficha do pedido tem as abas dados gerais, itens, atendimento, valores, pagamento, devoluções e histórico. A venda rápida inclui busca por Enter (compatível com leitor de código de barras) e cadastro de cliente e item sem sair da tela.
- **Permissões**: 18 permissões (`sales.*`), concedidas em `/company/members` sem mudar a tela, que já lista pelo catálogo. Elas não concedem permissões de Produtos, Clientes ou Compras.
- **RootAdmin**: Vendas registrado como implementado (1.0.0), sem dependências comerciais (`optional_integrations: customers, products, finance`), não ativado automaticamente e indisponível comercialmente até decisão do RootAdmin.
- **Demonstração**: `SalesDemoSeeder` idempotente, bloqueado fora de `local`/`testing`. Cobre orçamento pendente, orçamento convertido, venda direta, venda de serviços, venda parcial, venda concluída com devolução parcial e venda cancelada, além da empresa **Loja Somente Vendas** (só Vendas, sem estoque).

## Decisões arquiteturais

- **Estoque habilitado = módulo Produtos ativo na empresa** (`SalesStock`), pois o estoque pertence a ele. O catálogo e as pessoas são dependências técnicas compartilhadas. Não são comerciais.
- **Itens criados em Vendas sem Produtos** nascem sem controle de estoque, para não criar saldos ocultos.
- **Reserva com semântica de "definir"**: cada chamada informa quanto o documento mantém reservado, o que a torna idempotente. Usa a mesma ordem de bloqueio do estoque (unidade comercializável antes do saldo, em ordem crescente). O estoque negativo continua proibido: só se reserva o disponível.
- **Receita líquida** = valor do item após o rateio do desconto geral, fixado na confirmação. Frete e despesas não entram na margem. Atendimentos e devoluções sucessivos usam parcelas acumuladas, sem sobra de centavos.
- **Devolução com reposição** entra no estoque pelo custo histórico, e o custo médio volta a refletir a mercadoria. **Estorno técnico** é separado da devolução e só vale antes da conclusão.
- **Margem exige `view_cost` e `view_margin`**, pois revela o custo.
- **Ajustes pontuais em recursos compartilhados**: condições e formas de pagamento, `PaymentSchedule` e cálculo monetário foram movidos de Compras para `Core\Commerce`, e `PurchaseMath` virou uma subclasse sem mudança de regra. `RemoteLookup` e `ReasonDialog` foram para `components/`. Os contratos financeiros foram vinculados no container. Assim Vendas não depende de Compras.
- O histórico de situação (`sale_status_history`) serve a orçamentos e pedidos, com a situação gravada como texto.

## Migrations

`2026_11_26_100000` (reservas e `reserved_quantity`, aditiva), `100001` (orçamentos, pedidos, itens, vencimentos e histórico) e `100002` (atendimentos e devoluções). Foram aplicadas ao banco local `new` (MariaDB) sem remover dados. Também foram rodados `PlatformModuleSeeder` e `SalesDemoSeeder`, este duas vezes, sem duplicar.

## Arquivos relevantes

- **Backend novo**: `app/Modules/Sales` (enums, models, serviços, contratos, dados, eventos, documentos, apresentação e CSV), `app/Modules/Core/Commerce`, `app/Modules/Core/Documents/DocumentFormat.php`, `app/Modules/Inventory/{Models/InventoryReservation.php,Services/InventoryReservationService.php}`, `app/Http/Controllers/Sales`, `app/Http/Requests/Sales`, `routes/sales.php`, `config/sales.php` e `database/seeders/SalesDemoSeeder.php`.
- **Backend alterado**: `InventoryService`, `InventoryBalance`, `PurchaseMath` (agora subclasse) e os usos das classes de pagamento em Compras, além de `AppServiceProvider`, `PlatformModuleSeeder`, `DatabaseSeeder`, `routes/{web,console}.php`.
- **Front-end novo**: `pages/sales/*`, `components/sales/*` e `types/sales.ts`. **Alterados**: `app-sidebar.tsx` e `types/index.ts`. **Movidos**: `components/{remote-lookup,reason-dialog}.tsx`.
- **Testes novos**: `SaleOrderTest`, `SaleFulfillmentTest`, `SaleQuoteTest`, `SalesModularityTest`, `SalesIndicatorsTest`, `SalesDemoSeederTest` e `SalesPagesTest`. **Alterados**: `tests/Pest.php` (auxiliares) e `CompanyModuleTest`, que passou a usar Financeiro como exemplo de módulo em desenvolvimento.
- **Documentação**: `SALES.md` e `OMNICHANNEL-05.md` (novos), além de `ARCHITECTURE.md`, `MODULES.md` e `INVENTORY-CORE.md`.

## Testes realizados

| Verificação | Resultado |
|---|---|
| `php artisan test --compact` (SQLite), regressão final | 318 aprovados, 2 ignorados, 0 falhas (60 testes novos) |
| Testes filtrados por `--filter=Sale` | 65 aprovados |
| `npm run build` | Aprovado; 27 entradas de `pages/sales` no manifest |
| `vp check` nos arquivos novos e alterados | Sem avisos |
| `tsc` | 12 erros antigos; nenhum nos arquivos novos |
| Pint | Aprovado |
| PHPStan | 20 apontamentos antigos (o mesmo número da etapa anterior); nenhum nos arquivos desta etapa |
| Migrations e seed duplo no banco local | Aprovados, sem duplicidade |

Os testes cobrem: numeração, totais no servidor (inclusive ignorando os enviados), snapshots, idempotência, preço e desconto conforme permissão, itens avulsos, frações, item sem preço, venda sem cliente e endereço obrigatório na entrega. Também cobrem soma das parcelas, reserva com e sem saldo disponível, saídas manuais bloqueadas pela reserva, cancelamento com liberação, venda rápida atômica com rollback e contrato financeiro. Do lado do atendimento, cobrem atendimento parcial com custo histórico, repetição e excesso, custo indisponível, custo que não muda com entradas posteriores, encerramento de saldo, estorno, bloqueio de estorno após a conclusão, devolução parcial e total com reposição ao custo histórico e devolução sem reposição. Por fim, cobrem o ciclo do orçamento, conversão única, expiração (comando e decisão), operação só com Vendas (sem Clientes, Produtos e Financeiro), cliente vinculado a fornecedor sem alteração, busca de clientes, isolamento entre empresas (404) e itens e clientes de outra empresa, matriz de permissões (403), ocultação de custos e margens, PDFs sem custo, CSV contra fórmulas, indicadores e renderização de todas as páginas.

## Limitações

- Não houve teste em navegador (não há Chrome no ambiente). Diálogos, abas, teclado e responsividade foram verificados só por build, tipos e testes de servidor.
- A concorrência de reservas não foi medida em MySQL 8.4 nem em MariaDB nesta etapa; a suíte usa SQLite.
- A configuração de venda sem identificação fica em `companies.settings`, que ainda não tem tela.
- Os filtros por cliente e item nas listagens só funcionam por parâmetro de URL, sem seletor na tela.
- A dependência `view_margin` → `view_cost` é aplicada em tempo de execução, não na tela de permissões.
- Os eventos não têm ouvintes; eles existem para Financeiro, PDV, Fiscal, E-commerce, CRM e relatórios.

## Pendências

1. Homologação visual e de usabilidade em navegador, inclusive no celular.
2. Teste de concorrência de reservas e atendimentos em MySQL 8.4.
3. A dependência comercial de Compras em relação a Produtos está registrada em `OMNICHANNEL-05.md` para adequação posterior.
4. Recebimentos, contas a receber e o tratamento financeiro das devoluções ficam para a OMNICHANNEL-06.
5. Continuam valendo as pendências das etapas anteriores (`OMNICHANNEL-02` a `04`), entre elas Clientes ainda não usar `PartyService` no próprio módulo.

## Estado do Git

- `.git-local` (`main`): OMNICHANNEL-04 consolidada em `8919435`. Tudo da OMNICHANNEL-05 está no diretório de trabalho, **sem commit**.
- `.git`: `7ef80d4` foi enviado ao GitHub nesta sessão, antes desta etapa. As alterações da OMNICHANNEL-05 também estão sem commit ali.
- Não versionados: `correio.md`, `pnpm-lock.yaml` e `yarn.lock`. Não houve deploy nem alteração em outros sistemas.

## Critérios de aceite

| # | Critério | Situação |
|---|---|---|
| 1 | Vendas funcional no backend e frontend | Atendido |
| 2 | Cadastrar e emitir orçamentos | Atendido |
| 3 | Conversão sem duplicidade | Atendido (teste de repetição) |
| 4 | Vendas diretas | Atendido, inclusive venda rápida |
| 5 | Atendimentos integrais e parciais | Atendido |
| 6 | Cancelamentos e devoluções | Atendido |
| 7 | Integração com Estoque quando habilitado | Atendido (reservas e saídas) |
| 8 | Funcionar sem os demais módulos | Atendido (teste só com Vendas) |
| 9 | Cliente e item simplificados nos domínios compartilhados | Atendido |
| 10 | Valores e quantidades no backend | Atendido |
| 11 | Custos e margens protegidos | Atendido |
| 12 | PDFs comerciais | Atendido |
| 13 | Condições de pagamento registradas | Atendido |
| 14 | Contrato para o Financeiro | Atendido |
| 15 | Indicadores com dados reais | Atendido |
| 16 | Isolamento empresarial | Atendido |
| 17 | Permissões individuais | Atendido |
| 18 | RootAdmin controla a habilitação | Atendido |
| 19 | Testes direcionados e build | Atendido |
| 20 | Implementação documentada | Atendido |

## Parecer técnico

Os critérios 1 a 20 estão atendidos e verificados por testes automatizados e build. A única ressalva é a mesma da etapa anterior: a interação em navegador e a responsividade não foram observadas diretamente. O módulo Vendas está apto para homologação manual. A próxima etapa é a OMNICHANNEL-06 (Financeiro), que vai consumir `SaleReceivableSource` e `PurchaseObligationSource`.
