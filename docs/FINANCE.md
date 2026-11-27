# Módulo Financeiro (`finance`) 1.0.0

Contas a pagar e a receber, baixas, contas financeiras, livro de movimentações, transferências, recorrências, fluxo de caixa, conciliação manual e relatórios gerenciais, para MEIs e pequenas empresas.

## Independência

Sem dependências comerciais (`dependencies: []`). Integra-se opcionalmente a Vendas e Compras (`optional_integrations: sales, purchases`). Funciona sem Clientes, Produtos, Estoque, Compras, Vendas, Fiscal e Pagamentos. Pessoas são do domínio compartilhado `Parties`: o Financeiro cadastra uma pessoa simplificada (`FinanceParties`, sobre `PartyService`) sem contratar Clientes ou Compras. Nenhum módulo comercial grava em tabelas financeiras, e o Financeiro não grava nas deles.

Desativar o módulo não apaga dados nem interrompe Vendas e Compras: os ouvintes verificam a habilitação (`FinanceModule::isEnabled`) e simplesmente não agem.

## Estrutura

`app/Modules/Finance`: `Enums`, `Models`, `Data`, `Services`, `Integrations`, `Listeners`, `Events`, `Queries`, `Reports`, `Support`. Controladores em `app/Http/Controllers/Finance`, rotas em `routes/finance.php` (`/finance`), configuração em `config/finance.php`, telas em `resources/js/pages/finance`.

| Tabela                                                                                               | Conteúdo                                                                                                                              |
| ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `finance_accounts`                                                                                   | Caixa, conta corrente, digital, poupança, carteira e outras. Saldo inicial e data; o saldo atual nunca é gravado.                     |
| `finance_categories`                                                                                 | Receitas e despesas, com um nível de subcategoria. Sugeridas criadas no primeiro uso, editáveis e inativáveis.                        |
| `finance_titles`                                                                                     | Títulos a receber e a pagar: valor, ajustes, liquidado, cancelado, juros, multa, desconto, competência, vencimento, parcela e origem. |
| `finance_settlements`                                                                                | Baixas (integrais ou parciais), inclusive externas; estornadas permanecem no histórico.                                               |
| `finance_movements`                                                                                  | Livro: entrada, saída, transferência, ajuste e estorno. Nunca editado nem excluído.                                                   |
| `finance_transfers`                                                                                  | Transferências entre contas da mesma empresa.                                                                                         |
| `finance_recurrences`                                                                                | Receitas e despesas recorrentes.                                                                                                      |
| `finance_renegotiations`, `finance_title_adjustments`, `finance_title_events`, `finance_attachments` | Rastreabilidade: renegociações, ajustes por integração, histórico e anexos.                                                           |

Valores monetários são `decimal(15,2)` calculados com `Brick\Math` (`CommercialMath`); nenhum cálculo usa float. No navegador, os valores trafegam como texto e são somados em centavos inteiros (`lib/money.ts`). Todas as tabelas têm `company_id`, e as chaves estrangeiras compostas `(company_id, id)` impedem vínculo entre empresas no próprio banco.

## Regras

- **Situação do título**: pendente, parcialmente liquidado, liquidado, cancelado ou renegociado. "Vencido" não é gravado: é derivado do vencimento e do saldo em aberto (`isOverdueOn`), evitando situação inconsistente.
- **Saldo em aberto** = valor − ajustes − principal liquidado − cancelado.
- **Baixa**: conta, data efetiva, forma, principal, juros, multa e desconto. Valor movimentado = principal + juros + multa − desconto, calculado no servidor. O principal não pode superar o saldo em aberto. Valores a mais exigem um título de crédito explícito.
- **Concorrência e idempotência**: a baixa trava o título (`lockForUpdate`) e usa `idempotency_key` + hash da requisição. A mesma chave devolve a mesma baixa, e uma chave reutilizada com outros dados é recusada. O mesmo vale para títulos, transferências e ajustes.
- **Estorno**: cria uma movimentação compensatória (`reversal_of_id`, única) e marca a baixa como estornada, com motivo e responsável. O título volta a ter saldo.
- **Cancelamento**: cancela só o saldo em aberto. O que já foi liquidado permanece.
- **Renegociação**: os títulos originais ficam "renegociados" e as novas parcelas apontam para a renegociação.
- **Contas**: o saldo é calculado pelo livro (`FinanceLedger`). Correções só por ajuste de saldo (`adjust_balances`), com justificativa, lançado como ajuste fora do resultado.
- **Transferência**: saída na origem e entrada na destino, com referência compartilhada, fora de receitas e despesas (`is_operational = false`). O estorno é integral.
- **Recorrências**: semanal, mensal, trimestral, semestral e anual, com data inicial, final opcional e encerramento. O comando `finance:generate-recurrences` (diário, 00:20) gera títulos até `FINANCE_RECURRENCE_HORIZON_DAYS` (31) dias à frente, com chave `recurrence:{id}:{data}`. Nunca há baixa automática.
- **Anexos**: disco e tipos em `config/finance.php` (pdf, imagens, xml, txt; 5 MB), validados por extensão e conteúdo e servidos só a quem pode ver o título.

## Integrações (opcionais)

Ouvintes registrados em `AppServiceProvider` (eventos `ShouldDispatchAfterCommit`):

| Origem  | Eventos                                                                                                                  | Ouvinte                   |
| ------- | ------------------------------------------------------------------------------------------------------------------------ | ------------------------- |
| Vendas  | `SaleConfirmed`, `SaleCancelled`, `SaleReturnRegistered`, `SaleBalanceClosed`, `SaleFulfillmentReversed`                 | `SyncSaleReceivables`     |
| Compras | `PurchaseConfirmed`, `PurchaseCancelled`, `PurchaseReturnRegistered`, `PurchaseBalanceClosed`, `PurchaseReceiptReversed` | `SyncPurchaseObligations` |

Os ouvintes leem os contratos `SaleReceivableSource` e `PurchaseObligationSource`. `CommercialObligations` traduz cada um para `CommercialObligation`, e `ObligationReconciler` cria os títulos uma vez (chave por parcela) e concilia o valor devido:

- **Venda**: devido = total − devoluções − valor dos itens não atendidos com saldo encerrado. Cancelada, nada é devido. Orçamentos e rascunhos não geram títulos. Atendimento e conclusão não são recebimento.
- **Venda parcialmente atendida**: o título previsto continua pelo total enquanto o saldo pode ser atendido. Ao encerrar o saldo, o valor não atendido deixa de ser devido e é ajustado nos títulos em aberto, das parcelas mais distantes para as mais próximas. Estornar um atendimento volta a conciliar.
- **Compra**: devido = total (ou o valor recebido, se o saldo foi encerrado) − devoluções. Cancelada, nada é devido. Receber mercadoria não é pagar ao fornecedor.
- **Redução do devido**: ajusta só saldos em aberto (`finance_title_adjustments`, rastreável). Valores já liquidados nunca desaparecem.
- **Liquidado acima do devido** (cancelamento ou devolução após recebimento ou pagamento): gera um título de restituição explícito (`sale_refund`, a pagar ao cliente; `purchase_refund`, a receber do fornecedor) para confirmar a devolução do dinheiro ou o crédito.
- Falhas no ouvinte são registradas (`report`) e não interrompem Vendas e Compras. Reprocessar o mesmo evento não duplica títulos.

## Ativação posterior

`/finance/import` (`import_documents`): escolha da origem (Vendas ou Compras, conforme habilitação) e do período, prévia com documentos elegíveis, já importados e cancelados, seleção e confirmação. Nada é presumido pago. O usuário marca os documentos liquidados fora do sistema, que recebem **baixa externa**, sem movimentar contas. A seleção é validada por inteiro antes de gravar, e repetir a importação não duplica.

## Consultas e relatórios

- **Visão geral** (`FinanceDashboard`): saldo disponível; a receber e a pagar (previsto); vencidos; a vencer em 7 dias; recebido e pago no período (realizado); resultado; 12 meses de receitas e despesas; projeção de 30 dias; próximos vencimentos; despesas por categoria; e saldo por conta. Filtros por período e conta. Venda sem baixa nunca aparece como recebida.
- **Fluxo de caixa** (`CashFlow`): o realizado vem das movimentações efetivas. O previsto é o saldo em aberto a partir de hoje, com vencidos entrando hoje. Sem dupla contagem de títulos parciais. Agrupamento diário, semanal e mensal. No consolidado, transferências ficam fora. Filtrado por conta, mostra só o realizado.
- **Relatórios** (`FinanceReports`): contas a receber, contas a pagar, vencidos, liquidados, inadimplência, receitas e despesas por categoria (regime de caixa ou competência), extrato e fluxo de caixa. Mostram totais e exportam CSV (protegido contra fórmulas) e PDF (`FinanceReportDocument`), com aviso de que são gerenciais e sem valor contábil ou fiscal.
- **Conciliação manual**: extrato por conta e período, marcação de movimentações conferidas (data e responsável) e desfazer com motivo (`reconcile`). A estrutura (`external_reference`) está pronta para importação de extratos futura.

## Permissões

`finance.view_dashboard`, `view_accounts`, `manage_accounts`, `manage_categories`, `view_payables`, `manage_payables`, `pay`, `view_receivables`, `manage_receivables`, `receive`, `cancel_titles` (também renegociar), `reverse_settlements`, `transfer`, `adjust_balances`, `manage_recurrences`, `reconcile`, `import_documents`, `view_reports`, `export`.

As permissões dependentes do tipo (a receber ou a pagar) são verificadas no controlador. Elas não concedem acesso a Vendas, Compras ou Clientes.

## Demonstração

`FinanceDemoSeeder` (local e testing, idempotente):

- **Escritório Somente Financeiro**: três contas, títulos manuais, parcelamento em 3x, baixas parciais, vencidos, pagamento com juros e multa, baixa estornada e refeita, transferência, ajuste de caixa e três recorrências.
- **Loja Somente Vendas** (Financeiro + Vendas) e **Empresa Demonstração** (Financeiro + Vendas + Compras): Financeiro ativado depois, com importação assistida das vendas e compras, uma marcada como recebida externamente, e um recebimento parcial. A venda com devolução da demonstração entra pelo valor já descontado.

## Fora do escopo

Emissão fiscal, Pix, boletos, cartões, Efí, Sicredi, Open Finance, OFX e integrações bancárias, contabilidade oficial, folha, PDV e e-commerce.
