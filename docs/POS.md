# PDV — Frente de Caixa (`pos`) 1.0.0

Terminais, abertura e fechamento de caixa, venda rápida com pagamentos combinados, vendas suspensas, sangrias, suprimentos, devoluções, comprovantes não fiscais e relatórios, para MEIs, microempresas e pequenos comércios.

## Independência

Sem dependências comerciais (`dependencies: []`). Integra-se opcionalmente a Clientes, Produtos, Vendas e Financeiro (`optional_integrations: customers, products, sales, finance`). Funciona sem nenhum deles: uma empresa somente com o PDV vende itens avulsos ou itens do catálogo compartilhado, identifica clientes pelo cadastro simplificado de `Parties` e mantém o próprio controle de caixa.

A integração nunca concede interfaces ou permissões de módulos não contratados: com o PDV e sem Vendas, `/sales` continua proibido; sem Financeiro, nada é gravado em tabelas financeiras.

## Estrutura

`app/Modules/Pos`: `Enums`, `Models`, `Data`, `Services`, `Events`, `Support`. Controladores em `app/Http/Controllers/Pos`, requisições em `app/Http/Requests/Pos`, rotas em `routes/pos.php` (`/pos`), telas em `resources/js/pages/pos`, componentes em `resources/js/components/pos` e carrinho em `resources/js/lib/pos-cart.ts`.

| Tabela | Conteúdo |
|---|---|
| `pos_terminals` | Pontos de atendimento: nome, identificação única por empresa, localização, situação, largura do comprovante (58 ou 80 mm) e configurações. |
| `pos_terminal_operators` | Operadores autorizados. Sem operadores, qualquer usuário com `pos.open_session` pode abrir o terminal. |
| `pos_sessions` | Sessões de caixa: operador, saldo inicial, abertura, fechamento cego, esperado, contado, divergência, justificativa e resumo congelado. `open_terminal_id` único impede duas sessões abertas no mesmo terminal. |
| `pos_sales` | Atendimentos do caixa: suspenso (carrinho), concluído (vínculo com `sale_orders`) ou cancelado. Recebido, a prazo, troco, devolvido, autorização, segunda via e chave de idempotência. |
| `pos_payments` | Formas de pagamento de cada venda: valor, entregue, troco, recebido ou a prazo, vencimento e vínculo com a parcela da venda. |
| `pos_cash_movements` | Suprimento, sangria, ajuste e restituição em dinheiro. Imutáveis; correções após o fechamento ficam marcadas (`is_post_close`). |
| `pos_refunds` | Restituições ao cliente vinculadas à devolução de Vendas (`sale_returns`). |
| `pos_favorites` | Itens favoritos do catálogo compartilhado, em ordem. |

A migration `add_origin_reference_to_sale_orders_table` adiciona a referência legível do canal (terminal e sessão) à venda comercial.

Valores monetários são `decimal(15,2)` (itens e quantidades ficam na venda comercial), calculados com `Brick\Math` (`CommercialMath`). No navegador, os valores trafegam como texto e são somados em centavos inteiros (`lib/money.ts`). Todas as tabelas têm `company_id`, e as chaves compostas `(company_id, id)` impedem vínculo entre empresas no banco.

## Serviços comerciais compartilhados

O PDV não tem tabela de venda, documento, cálculo ou movimentação de estoque próprios:

- **`QuickSaleService`** (Vendas) registra a venda: mesma entidade `SaleOrder`, numeração, documentos, estoque e eventos. Recebe um `SaleChannel` definido pelo servidor (origem `pos`, referência e regra de venda sem identificação) e uma etapa `beforeConfirm`, em que o PDV define os pagamentos combinados (`SaleOrderService::schedulePayment`) depois do cálculo do total.
- A regra de negócio fica nos serviços; a autorização comercial fica nas rotas (`module.permission:sales,…` ou `module.permission:pos,…`). Por isso o PDV usa o serviço sem que Vendas esteja contratado.
- **`SaleItems`** e **`ProductCatalog`**: busca por código de barras, SKU, código interno e descrição, leitura exata para leitores que digitam como teclado e cadastro simplificado de item. Sem o estoque habilitado, o item nasce sem controle de estoque.
- **`SaleReturnService`**: devoluções totais e parciais, com custo histórico e reposição opcional ao estoque.
- **`SaleCustomers`** / **`PartyService`**: busca por nome, documento ou telefone e cadastro simplificado.
- `PaymentCondition::Combined` (várias formas no mesmo documento, fora dos formulários) e `PaymentMethod` (dinheiro, Pix, crédito, débito, cheque, vale, boleto e outros) são do núcleo `Core/Commerce`.

## Regras

- **Abertura**: terminal ativo, operador autorizado, valor inicial e observações. O saldo inicial não é receita. A chave de idempotência evita sessões duplicadas.
- **Finalização** (`PosCheckout::finalize`): transacional e idempotente; repetir a chave devolve a venda já registrada. O servidor recalcula preços, descontos e totais; o preço do navegador só vale como alteração autorizada. Pagamentos precisam cobrir o total; troco só existe em dinheiro; parte a prazo exige cliente identificado e fica em aberto.
- **Descontos e preços**: o desconto acima do limite configurado exige `pos.authorize` ou credenciais de supervisor (`PosAuthorizer`, com tentativas limitadas); sem essa opção, é recusado. Alterar preço exige a configuração e `pos.change_prices`.
- **Pagamentos**: Pix, cartão e boleto são registrados pelo operador como informados, nunca confirmados automaticamente.
- **Vendas suspensas**: guardam o carrinho, o operador e o terminal, sem venda comercial, reserva, estoque ou finanças. Na recuperação e na finalização, preços, disponibilidade e permissões são validados de novo.
- **Cancelamento**: carrinho em andamento ou venda suspensa, com motivo e `pos.cancel_sales` (ou supervisor); o histórico é preservado. Venda concluída é desfeita por devolução.
- **Devoluções**: total ou parcial, pelo `SaleReturnService`. A restituição é o que já foi recebido acima do novo valor devido (valores a prazo em aberto são reduzidos antes). Em dinheiro, sai da gaveta do caixa aberto.
- **Sangria, suprimento e ajuste**: valor, motivo, operador e autorizador. A sangria não pode superar o dinheiro esperado. O ajuste sempre exige autorização. Nada disso é receita ou despesa.
- **Fechamento**: resumo calculado pelos registros (saldo inicial, vendas, recebimentos por forma, suprimentos, sangrias, devoluções, cancelamentos e dinheiro esperado). Divergência exige justificativa. No fechamento cego, o operador não vê o esperado até fechar. Fechar o caixa de outro operador exige supervisor. Depois de fechado, o caixa só aceita ajustes autorizados, exibidos à parte.
- **Concorrência**: sessões e vendas são travadas (`lockForUpdate`) nas operações; chaves únicas por empresa garantem a idempotência mesmo em requisições simultâneas.

## Integrações

- **Produtos/Estoque**: validação de disponibilidade e saída na conclusão pelo domínio `Inventory`, respeitando reservas e a proibição de estoque negativo; a devolução repõe se indicado. Sem Produtos, nenhuma reserva ou movimentação é criada.
- **Vendas**: as vendas do caixa aparecem em Vendas com origem PDV, terminal, sessão e operador, e entram nos indicadores. Não existe segunda venda.
- **Financeiro** (`SyncPosOperations`, só pelos dados dos eventos `PosSaleCompleted`, `PosRefundIssued` e `PosCashMovementRecorded`): os títulos vêm da integração com Vendas, uma única vez; só os pagamentos recebidos recebem baixa, na conta configurada para a forma. Valores a prazo e formas sem conta ficam em aberto. A restituição baixa o título de restituição. Sangrias e suprimentos viram transferências entre a conta do caixa e a de tesouraria, quando configuradas. A abertura não gera nada. As chaves são determinísticas, e falhas não interrompem o PDV.

## Comprovantes

Comprovante comercial não fiscal com aviso "sem validade fiscal": impressão pelo navegador em 58 ou 80 mm (`pos/receipt`), PDF (`receipt.pdf`) e segunda via. A primeira via fica disponível ao operador da venda enquanto o caixa está aberto. A segunda via exige `pos.reprint`, é contada e auditada. Cabeçalho e rodapé vêm das configurações. Sem NFC-e e sem agentes locais de impressão.

## Interface

- `/pos`: terminais e caixas abertos do usuário, com abertura de caixa.
- `/pos/sessions/{id}/register`: frente de caixa em tela cheia. Busca e leitor de código de barras, favoritos, quantidade, preço, desconto, cliente, item avulso, suspensão, recuperação, cancelamento, pagamentos combinados, troco, sangria e suprimento. Atalhos: F2 buscar, F3 cliente, F4 quantidade, F6 desconto, F7 suspender, F8 remover item, F9 recuperar, F10 pagamento e Ctrl+Enter finalizar. Tudo também funciona por clique e toque.
- **Sem conexão**: o operador é avisado, o carrinho fica guardado no navegador e nada é confirmado sem resposta do servidor. A mesma chave de idempotência é reaproveitada, para não duplicar a venda. Não há operação offline completa.
- Telas administrativas: sessões (histórico, resumo, fechamento e correção), vendas do caixa (detalhe, devolução e segunda via), terminais, relatórios e configurações (inclusive favoritos). O menu **PDV** mostra cada item conforme as permissões.

## Relatórios

`PosReports`: vendas por operador, por terminal e por dia, formas de pagamento, produtos vendidos, cancelamentos, devoluções, divergências de caixa e histórico de sessões, além de indicadores (quantidade, total, ticket médio, recebido, a prazo, cancelamentos, restituições e divergência). Filtros por período, terminal e operador, com exportação CSV. Nenhum relatório expõe custo ou margem.

## Permissões

`pos.operate`, `pos.open_session`, `pos.close_session`, `pos.view_sessions`, `pos.manage_terminals`, `pos.apply_discounts`, `pos.change_prices`, `pos.authorize`, `pos.withdraw`, `pos.supply`, `pos.cancel_sales`, `pos.return`, `pos.reprint`, `pos.view_reports` e `pos.manage_settings`. O operador não recebe acesso administrativo nem financeiro.

## Configurações

Em `companies.settings['pos']` (`PosSettings`): venda sem identificação, desconto máximo, autorização para desconto superior, alteração de preço, formas de pagamento, fechamento cego, impressão do comprovante, cabeçalho e rodapé, conta financeira por forma de pagamento e contas de caixa e tesouraria. As contas aparecem somente com o Financeiro habilitado e são validadas contra a empresa.

## Demonstração

`PosDemoSeeder` (local e testing, idempotente): "Mercadinho Somente PDV", "Papelaria PDV + Produtos", "Lanchonete PDV + Vendas" e "Quiosque PDV + Financeiro". Cada empresa tem dois terminais, o proprietário e um operador (`operador.pdv@vetornew.test`), uma sessão fechada (com vendas, pagamento combinado, suprimento, sangria, devolução e divergência justificada) e uma sessão aberta, com venda concluída e venda suspensa.

## Limitações

- Sem NFC-e, NF-e, TEF, Pix integrado, adquirentes, balanças, gavetas automáticas, aplicativo nativo ou operação offline completa.
- Sem conexão, o carrinho fica só no navegador daquele terminal.
- Com a restituição em dinheiro, o operador precisa de um caixa aberto; nas outras formas, a devolução pode ser registrada sem caixa.
