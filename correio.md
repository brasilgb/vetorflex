# OMNICHANNEL-04 — Módulo Compras, Fornecedores e formação de custos

## 1. Contexto

Projeto VetorFlex — plataforma SaaS de gestão empresarial omnichannel da ABrasilSistemas.

Diretório atual:

`gateway/vetorflex/`

O caminho anterior `gateway/vetornew/` não deverá mais ser utilizado nas novas instruções.

Tecnologias existentes:

- Laravel.
- MySQL 8.4.
- Inertia e React.
- Monólito modular.
- Multi-tenancy.
- Redis e Docker preparados.

Etapas aprovadas:

- OMNICHANNEL-01 — Fundação modular.
- OMNICHANNEL-02 — Clientes e painel empresarial.
- OMNICHANNEL-03 — Produtos e núcleo de estoque.

Existem clientes aguardando a primeira entrega comercial, composta pelos módulos Clientes, Produtos, Compras, Vendas e Financeiro.

Priorizar desenvolvimento funcional, sem novas etapas exclusivamente de auditoria.

## 2. Consolidação anterior

- Inspecionar o estado do Git e confirmar a consolidação da OMNICHANNEL-03.
- Preservar o histórico existente.
- Atualizar referências obsoletas ao diretório vetornew na documentação e configurações relevantes.
- Não renomear indiscriminadamente bancos, containers ou serviços existentes.
- Não repetir a suíte completa de auditoria da OMNICHANNEL-03 sem necessidade.
- Preservar as funcionalidades existentes.
- Sem push ou deploy.

## 3. Objetivo

Implementar o módulo Purchases, contemplando:

- Fornecedores.
- Pedidos de compra.
- Recebimentos totais e parciais.
- Entrada de mercadorias.
- Formação de custos.
- Devoluções.
- Histórico de compras.
- Condições de pagamento.
- Indicadores.
- Preparação para integração com Financeiro.

Compras deverá depender de Produtos, mas não deverá exigir Clientes ou Financeiro.

## 4. Cadastro de fornecedores

Reutilizar obrigatoriamente o domínio compartilhado de pessoas existente:

- parties
- party_roles
- party_addresses
- party_contacts

Criar perfil específico de fornecedor, conforme necessário.

Uma mesma pessoa poderá ser cliente e fornecedor simultaneamente, sem duplicação cadastral.

Não duplicar o CRUD básico de pessoas. Extrair componentes compartilhados quando necessário, preservando o comportamento do módulo Clientes.

Implementar:

- Pessoa física ou jurídica.
- Razão social e nome fantasia.
- CPF/CNPJ.
- Inscrições aplicáveis.
- Endereços.
- Telefones.
- WhatsApp.
- E-mail.
- Contatos comerciais.
- Código interno.
- Prazo médio de entrega.
- Condição de pagamento preferencial.
- Pedido mínimo opcional.
- Observações.
- Situação ativa ou inativa.

Permitir fornecedores com cadastro simplificado.

Quando existir uma pessoa com o mesmo documento na empresa, oferecer a possibilidade de adicionar o papel fornecedor à pessoa existente.

O acesso ao módulo Compras não deverá conceder automaticamente acesso ao módulo Clientes.

## 5. Funcionalidades de fornecedores

Implementar:

- Cadastro.
- Listagem paginada.
- Pesquisa.
- Filtros.
- Edição.
- Ficha do fornecedor.
- Inativação e reativação.
- Importação e exportação CSV.
- Histórico de compras.
- Produtos adquiridos.
- Último preço de compra.
- Histórico de preços por fornecedor.

Reutilizar os padrões de importação e exportação existentes, incluindo proteção contra CSV injection, prévia e confirmação.

Não excluir fisicamente fornecedores que possuam referências operacionais.

## 6. Relacionamento entre fornecedor e produto

Permitir que um produto ou unidade comercializável possua múltiplos fornecedores.

Registrar, quando aplicável:

- Código do produto no fornecedor.
- Referência comercial.
- Último custo de aquisição.
- Prazo de entrega.
- Quantidade mínima.
- Fornecedor preferencial.
- Observações.

Não duplicar produtos para representar fornecedores diferentes.

A referência deverá utilizar a unidade comercializável existente em product_variants.

## 7. Estrutura de pedidos de compra

Criar entidades conforme as convenções existentes.

Estrutura conceitual:

- supplier_profiles
- supplier_products
- purchase_orders
- purchase_order_items
- purchase_receipts
- purchase_receipt_items
- purchase_returns
- purchase_return_items
- purchase_payment_schedules
- purchase_status_history

Adaptar a estrutura quando tecnicamente justificável.

Todos os registros empresariais deverão respeitar company_id.

Utilizar integridade referencial, índices e restrições apropriadas.

## 8. Pedido de compra

Implementar os seguintes dados:

- Número sequencial por empresa.
- Fornecedor.
- Data do pedido.
- Previsão de entrega.
- Responsável.
- Itens.
- Quantidades.
- Custo unitário.
- Descontos por item.
- Desconto geral.
- Frete.
- Seguro opcional.
- Outras despesas.
- Total.
- Condição de pagamento.
- Observações.
- Referência externa opcional.

Permitir produtos simples, produtos com variações e serviços.

Utilizar ProductCatalog para consulta das unidades comercializáveis.

Preservar snapshots dos dados comerciais dos itens e do fornecedor relevantes para o documento histórico.

Não alterar pedidos antigos quando o cadastro do produto ou fornecedor for atualizado.

## 9. Estados do pedido

Implementar máquina de estados explícita:

- Rascunho.
- Confirmado.
- Parcialmente recebido.
- Recebido.
- Cancelado.

Regras:

- Rascunho não movimenta estoque.
- Confirmação do pedido não movimenta estoque.
- Recebimento confirmado movimenta estoque.
- Cancelamento de pedido sem recebimento não gera movimentação.
- Pedido parcialmente recebido pode ter seu saldo pendente encerrado.
- Mercadorias recebidas exigem devolução ou estorno autorizado, não cancelamento destrutivo.

Registrar histórico de transições com responsável, data e motivo quando aplicável.

Impedir transições inválidas pelo backend.

## 10. Recebimento de mercadorias

Permitir:

- Recebimento integral.
- Recebimento parcial.
- Múltiplos recebimentos para o mesmo pedido.
- Conferência dos itens.
- Quantidades recebidas.
- Quantidades pendentes.
- Data de recebimento.
- Responsável.
- Referência da nota ou documento do fornecedor.
- Observações e divergências.

Impedir recebimento superior à quantidade pendente, salvo fluxo explícito futuramente autorizado.

O recebimento deverá ser registrado em transação, com bloqueio de concorrência apropriado.

Não permitir duplicidade por repetição da requisição.

Serviços não geram movimentação física.

## 11. Integração com estoque

Utilizar exclusivamente o InventoryService existente.

Não realizar alteração direta de inventory_balances.

Cada entrada deverá registrar:

- Empresa.
- Unidade comercializável.
- Quantidade.
- Origem purchase_receipt.
- Identificador do recebimento e item.
- Usuário.
- Data e hora.
- Chave de idempotência.

A confirmação do recebimento e as respectivas movimentações deverão manter consistência transacional.

Caso alguma movimentação falhe, não confirmar parcialmente a operação de recebimento.

Preservar o histórico imutável.

## 12. Formação do custo médio

Implementar custo médio ponderado móvel para produtos com estoque controlado.

Fórmula operacional:

Novo custo médio = (saldo anterior × custo médio anterior + quantidade recebida × custo efetivo da aquisição) / saldo resultante.

Regras:

- Utilizar valores decimais com precisão apropriada.
- Não utilizar ponto flutuante binário para cálculos monetários.
- Atualizar custos somente mediante recebimento confirmado.
- Não atualizar automaticamente o preço de venda.
- Registrar histórico do custo anterior e novo.
- Registrar a origem da alteração.
- Tratar corretamente saldo anterior igual a zero.
- Garantir consistência diante de recebimentos simultâneos.
- Utilizar a mesma fronteira transacional e estratégia de bloqueio do estoque.

Diferenciar claramente:

- Último custo de compra.
- Custo efetivo da aquisição.
- Custo médio do estoque.
- Preço de venda.

Inspecionar os campos existentes do módulo Produtos antes de implementar.

Não substituir silenciosamente o significado de campos existentes.

Documentar a transição do custo cadastrado atual para a nova estrutura.

## 13. Composição do custo de aquisição

Considerar:

- Valor dos itens.
- Descontos.
- Frete.
- Seguro.
- Outras despesas diretamente atribuíveis.

Implementar rateio proporcional ao valor líquido dos itens como regra inicial.

Definir tratamento explícito para itens gratuitos e pedidos com valor líquido igual a zero.

Garantir que diferenças de arredondamento sejam distribuídas de maneira determinística.

Em recebimentos parciais, alocar os custos correspondentes à quantidade efetivamente recebida, evitando duplicidade de despesas.

Não implementar apuração tributária ou contabilidade fiscal avançada nesta etapa.

Documentar as limitações do cálculo operacional.

## 14. Devoluções e estornos

Implementar devolução parcial ou total de mercadorias recebidas.

Registrar:

- Pedido original.
- Recebimento original.
- Itens.
- Quantidades.
- Motivo.
- Responsável.
- Data.
- Valores históricos correspondentes.

Não permitir devolver quantidade superior à recebida e ainda não devolvida.

Utilizar InventoryService para as saídas correspondentes.

Respeitar a política existente de estoque não negativo.

Não excluir movimentações anteriores.

Estabelecer uma política documentada de valorização da saída e ajuste de custo.

Não recalcular retroativamente vendas ou movimentações posteriores para simplesmente restaurar o custo anterior.

Diferenciar estorno técnico de uma operação incorreta e devolução comercial de mercadoria.

## 15. Condições de pagamento

Permitir registrar no pedido:

- Pagamento à vista.
- Pagamento a prazo.
- Parcelamento.
- Entrada mais parcelas.
- Datas de vencimento.
- Valores.
- Forma de pagamento prevista.
- Observações.

Formas iniciais:

- Dinheiro.
- PIX.
- Boleto.
- Cartão de crédito.
- Cartão de débito.
- Transferência.
- Cheque.
- Outros.

Permitir condição de pagamento ainda não definida quando necessário.

Validar que as parcelas correspondam ao valor financeiro acordado.

Não realizar cobranças, pagamentos reais ou chamadas a gateways.

Não confundir previsão de pagamento com pagamento efetuado.

## 16. Preparação para Financeiro

Estabelecer contratos internos para futura geração de contas a pagar.

A integração deverá considerar:

- Empresa.
- Fornecedor.
- Pedido de compra.
- Recebimento ou obrigação comercial confirmada.
- Valor.
- Parcelas.
- Vencimentos.
- Origem.
- Identificador idempotente.
- Cancelamentos.
- Devoluções.

Documentar que o nascimento da obrigação financeira poderá ocorrer independentemente do recebimento físico, conforme a operação comercial.

Não gerar contas a pagar fictícias.

Não criar uma implementação paralela do módulo Financeiro.

Compras deverá continuar funcional quando Financeiro não estiver contratado.

A futura integração deverá ser implementada na OMNICHANNEL-06.

## 17. Compras rápidas

Considerando MEIs e pequenas empresas, permitir um fluxo simplificado para registrar uma compra que já ocorreu.

O usuário poderá:

1. Selecionar ou cadastrar fornecedor.
2. Informar os produtos.
3. Informar quantidades e custos.
4. Informar as condições de pagamento.
5. Confirmar o recebimento imediato.

Internamente, reutilizar os mesmos serviços de pedido, confirmação e recebimento.

Não criar uma segunda lógica de estoque ou formação de custos.

Garantir atomicidade da operação rápida.

## 18. Interface operacional

Utilizar o layout empresarial existente.

Menu Compras:

- Visão geral.
- Pedidos de compra.
- Nova compra.
- Recebimentos.
- Fornecedores.
- Devoluções.

Implementar:

- Pesquisa.
- Paginação.
- Filtros por fornecedor.
- Filtros por período.
- Filtros por situação.
- Filtros por produtos.
- Identificação de pedidos atrasados.
- Identificação de recebimentos pendentes.
- Exibição clara de totais e quantidades.

A ficha do pedido deverá apresentar:

- Dados gerais.
- Itens.
- Recebimentos.
- Valores.
- Condições de pagamento.
- Devoluções.
- Histórico.

Não apresentar abas fictícias.

Priorizar facilidade de operação, navegação por teclado, utilização em desktop e responsividade mobile.

## 19. Documentos comerciais

Disponibilizar geração de pedido de compra em PDF, utilizando solução compatível com a infraestrutura existente.

O documento deverá conter:

- Dados da empresa compradora.
- Dados do fornecedor.
- Número e data.
- Itens.
- Quantidades.
- Valores.
- Descontos.
- Frete.
- Total.
- Condições de pagamento.
- Previsão de entrega.
- Observações.

O documento deverá ser identificado como pedido de compra, sem natureza fiscal.

Utilizar componentes e padrões reutilizáveis para os futuros documentos do módulo Vendas.

Proteger a geração e o acesso ao documento pelo tenant e permissões.

Não criar URLs públicas irrestritas para documentos empresariais.

## 20. Indicadores de Compras

Implementar indicadores reais:

- Total de compras no período.
- Valor dos pedidos confirmados.
- Valor das mercadorias recebidas.
- Pedidos pendentes.
- Pedidos atrasados.
- Principais fornecedores.
- Produtos mais adquiridos.
- Evolução mensal das compras.
- Variação dos custos de aquisição.

Diferenciar valores pedidos, recebidos e efetivamente pagos.

Não apresentar informações de pagamento real enquanto Financeiro não estiver implementado.

## 21. Permissões

Registrar permissões conforme as convenções existentes:

- purchases.view
- purchases.create
- purchases.update
- purchases.confirm
- purchases.cancel
- purchases.receive
- purchases.return
- purchases.view_cost
- purchases.manage_cost
- purchases.export
- purchases.manage_suppliers
- purchases.import_suppliers
- purchases.export_suppliers

Garantir:

- Empresa ativa.
- Vínculo autorizado.
- Módulo habilitado.
- Permissão individual.
- Isolamento de registros.
- Proteção dos valores de custo em respostas HTTP, PDFs, exportações e indicadores.

Avaliar a interação entre permissões de Compras e Produtos, evitando que permissões de leitura comercial concedam acesso indevido ao custo de estoque.

## 22. Interface compartilhada de permissões

Concluir a pendência referente à administração de permissões individuais.

Implementar uma interface reutilizável no painel empresarial para que o proprietário possa:

- Consultar usuários da empresa.
- Visualizar papéis.
- Consultar permissões por módulo contratado.
- Conceder permissões autorizadas.
- Revogar permissões.
- Consultar alterações relevantes.

Impedir escalonamento indevido de privilégios.

Um administrador ou operador não poderá conceder permissões superiores às suas próprias autorizações.

Não permitir alterações em privilégios RootAdmin pelo painel empresarial.

Preservar as regras existentes de proprietário.

A interface deverá atender Clientes, Produtos e Compras e ser reutilizável pelos futuros módulos.

## 23. Preparação omnichannel

Documentar pontos de extensão para:

- Importação de pedidos de fornecedores.
- Integração por API.
- Recebimento por documentos externos.
- Identificadores externos.
- Importação de XML fiscal.
- Sincronização de custos.
- Atualização de disponibilidade de estoque por eventos.
- Processamento idempotente.

Não implementar integrações externas nesta etapa.

## 24. Integração com RootAdmin

Ao concluir:

- Registrar Purchases como implementado.
- Atualizar sua versão.
- Registrar permissões.
- Validar a dependência de Products.
- Permitir disponibilidade comercial.
- Permitir ativação por empresa.
- Atualizar a navegação dinâmica.

Não ativar automaticamente para todas as empresas.

Preservar dados durante desativação e reativação.

## 25. Seed de demonstração

Expandir a base existente com:

- Fornecedores fictícios.
- Pessoa com papéis cliente e fornecedor.
- Produtos vinculados a múltiplos fornecedores.
- Pedidos em rascunho.
- Pedidos confirmados.
- Pedidos parcialmente recebidos.
- Pedidos totalmente recebidos.
- Compras com frete e descontos.
- Compras parceladas.
- Devoluções.
- Histórico de custos.

Utilizar os serviços reais para gerar movimentações.

Não alterar diretamente saldos para criar cenários fictícios.

Garantir idempotência e bloqueio em produção.

## 26. Critérios de aceite

1. RootAdmin consegue autorizar Compras.
2. A dependência de Produtos é validada.
3. Compras funciona sem Clientes e Financeiro contratados.
4. Fornecedores podem ser cadastrados e administrados.
5. Uma pessoa pode ser cliente e fornecedor.
6. Pedidos de compra podem ser cadastrados.
7. Recebimentos totais e parciais funcionam.
8. Estoque é atualizado exclusivamente pelo InventoryService.
9. Requisições duplicadas não geram entradas duplicadas.
10. Recebimentos concorrentes preservam consistência.
11. Custo médio é calculado corretamente.
12. Frete, descontos e despesas são rateados corretamente.
13. Devoluções e estornos preservam histórico.
14. Condições de pagamento e parcelas são registradas.
15. Compra rápida funciona.
16. Pedido de compra pode ser emitido em PDF.
17. Indicadores utilizam dados reais.
18. Permissões podem ser gerenciadas pela interface empresarial.
19. Isolamento entre empresas está validado.
20. Build do frontend aprovado.

Realizar testes direcionados às novas funcionalidades, principalmente transações, concorrência, custos, isolamento e permissões.

Não repetir verificações anteriores sem justificativa técnica.

Quando possível, realizar verificação objetiva no navegador. Caso indisponível, registrar a limitação sem interromper o desenvolvimento.

## 27. Fora do escopo

- Vendas.
- Financeiro completo.
- Pagamentos reais.
- Emissão fiscal.
- Importação de XML fiscal.
- Cotação avançada entre fornecedores.
- Aprovação hierárquica complexa.
- Múltiplos depósitos.
- Marketplaces.
- E-commerce.
- Inteligência artificial.
- Cobrança da plataforma.
- Deploy.

## 28. Entrega

Criar:

- docs/PURCHASES.md
- docs/SUPPLIERS.md
- docs/INVENTORY-COSTING.md
- docs/OMNICHANNEL-04.md

Atualizar os documentos existentes quando necessário.

Registrar no executed.md:

- Consolidação anterior.
- Decisões arquiteturais.
- Funcionalidades implementadas.
- Migrations.
- Arquivos modificados.
- Testes realizados.
- Limitações.
- Pendências.
- Estado do Git.
- Parecer técnico.

Não apresentar preparação arquitetural como funcionalidade concluída.

Não interromper a execução com relatórios intermediários, salvo impedimentos técnicos que exijam intervenção.

Não realizar push, deploy ou alterações em outros sistemas.

O objetivo é concluir o terceiro módulo comercial do VetorFlex e estabelecer a base necessária para iniciar imediatamente o módulo Vendas.
