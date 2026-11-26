# Fornecedores

Fornecedor é o papel `supplier` de uma pessoa do domínio compartilhado (`parties`, `party_roles`, `party_addresses`, `party_contacts`). Os dados comerciais ficam em `supplier_profiles` (um por pessoa). Uma pessoa pode ser cliente e fornecedora sem duplicação.

## Cadastro

- Pessoa física ou jurídica, razão social, nome fantasia, CPF/CNPJ (validado), inscrições estadual e municipal, telefones, WhatsApp, e-mail, endereços e contatos comerciais: gravados por `Parties\Services\PartyService`, extraído nesta etapa.
- Perfil: código interno (único por empresa), prazo médio de entrega, condição de pagamento preferencial, pedido mínimo, observações e situação.
- Cadastro simplificado: apenas tipo e nome são obrigatórios.
- Documento já cadastrado na empresa: o sistema mostra a pessoa existente e pede confirmação (`link_existing`) para adicionar o papel fornecedor, sem alterar os dados dela.
- As observações do fornecedor ficam no perfil e não sobrescrevem as observações da pessoa usadas por Clientes.
- Editar um fornecedor altera os dados compartilhados da pessoa (nome, documento, contatos), refletindo no cadastro de cliente.

## Situação

Inativar o fornecedor muda apenas o perfil: a pessoa continua ativa como cliente. Fornecedor inativo não recebe novos pedidos nem confirmações, e pedidos antigos permanecem. Não há exclusão física.

## Telas

Listagem paginada com pesquisa (nome, fantasia, documento, e-mail, código) e filtros por situação e tipo; ficha com cadastro, histórico de compras, produtos adquiridos com último preço, e histórico de preços (preço negociado e custo efetivo por recebimento). Valores exigem `purchases.view_cost`.

## Produtos do fornecedor

`supplier_products` liga o fornecedor a uma unidade comercializável (`product_variants`), sem duplicar produtos: código e referência no fornecedor, prazo, quantidade mínima, preferencial (no máximo um por item) e observações. O último custo e a data da última compra são recalculados a partir do último recebimento confirmado, inclusive após estornos; não são digitados. O vínculo é criado automaticamente no primeiro recebimento. A busca de itens do pedido aceita o código do fornecedor.

## Importação e exportação

- Mesmo formato de Produtos (`CsvFormat`): UTF-8 com BOM, ponto e vírgula, até 500 linhas e 2 MB (`config/purchases.php`), proteção contra CSV injection (`CsvCell`) e prévia antes de gravar.
- Colunas: `nome`, `tipo` (`fisica`/`juridica`; vazio deduz pelo documento), `nome_fantasia`, `documento`, `inscricao_estadual`, `inscricao_municipal`, `email`, `telefone`, `celular`, `whatsapp`, `codigo_interno`, `prazo_entrega_dias`, `condicao_pagamento`, `pedido_minimo`, `observacoes`. A exportação acrescenta `situacao` e `tambem_cliente`, que a importação ignora.
- Na prévia cada linha é classificada como nova, pessoa existente (vínculo opcional, confirmado uma vez para todas), já fornecedor (ignorada) ou com erro. A confirmação reavalia as linhas, é idempotente e gera relatório de ocorrências.
- Prévias ficam criptografadas e são removidas após um dia pelo agendador (`model:prune`).

## Limitações

- Não há busca de CEP nem consulta de CNPJ em serviços externos.
- O cadastro de Clientes continua recusando documento já existente; o vínculo entre papéis é feito a partir de Fornecedores.
