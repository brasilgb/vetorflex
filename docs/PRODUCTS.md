# Módulo Produtos

Identificador `products`, versão 1.0.0. Independente de Clientes, Compras, Vendas e Financeiro.

## Estrutura

| Domínio                 | Tabelas                                                                                            |
| ----------------------- | -------------------------------------------------------------------------------------------------- |
| Catálogo                | `products`, `product_categories`, `product_brands`, `units_of_measure`                             |
| Unidade comercializável | `product_variants`, `product_attributes`, `product_attribute_values`, `product_variant_attributes` |
| Apoio                   | `product_images`, `product_price_changes`, `product_imports`                                       |

Todas têm `company_id`. Chaves estrangeiras compostas com `company_id` impedem associação entre empresas, e o middleware `tenant.records` responde 404 a registros de outra empresa antes da validação.

## Unidade comercializável

`product_variants` é a unidade comercializável. Produto simples e serviço possuem exatamente uma, com `is_default`; produto com variações possui uma por combinação de atributos. Preço, SKU, código de barras, dimensões e saldo pertencem a ela, e Compras, Vendas e movimentações devem referenciá-la.

Variações não são excluídas, apenas inativadas. `combination_key` impede combinações repetidas.

## Preços

- Decimais no banco, `Brick\Math` no backend e inteiros (`lib/decimal.ts`) no front-end.
- Margem é o lucro sobre a venda; markup, sobre o custo. Ambos usam o preço promocional quando informado.
- Toda gravação passa por `ProductService::saveVariant`, que registra `product_price_changes`.
- Tabelas de preços futuras devem referenciar a unidade comercializável, sem alterar esses campos.

## Permissões

| Permissão                                                     | Efeito                                         |
| ------------------------------------------------------------- | ---------------------------------------------- |
| `products.view`                                               | Catálogo                                       |
| `products.create`, `update`, `deactivate`, `reactivate`       | Cadastro; `update` inclui variações e imagens  |
| `products.import`, `export`                                   | CSV                                            |
| `products.manage_categories`, `manage_brands`, `manage_units` | Cadastros auxiliares                           |
| `products.view_cost`                                          | Custo, margem, markup e histórico de custo     |
| `products.manage_cost`                                        | Alterar custo; exige também `view_cost`        |
| `products.manage_prices`                                      | Alterar preço de venda e promocional           |
| `products.view_inventory`                                     | Saldos, movimentações e indicadores de estoque |
| `products.adjust_inventory`                                   | Movimentar e estornar                          |

Custos e saldos não são enviados ao navegador sem permissão. Preços e custos enviados por quem não pode alterá-los são descartados, preservando o valor gravado. O valor estimado do estoque exige `view_inventory` e `view_cost`.

## Importação CSV

Fluxo: envio → prévia (nada é gravado) → confirmação → relatório. Na confirmação cada linha é avaliada de novo, pois o catálogo pode ter mudado.

- UTF-8 ou Windows-1252, separado por `;` ou `,`. Modelo em `/products/imports/template`.
- Somente `nome` é obrigatório. Decimais aceitam `1.234,56` ou `1234.56`; sem vírgula, o ponto é sempre decimal.
- `categoria` aceita caminho: `Papelaria > Escrita`. Categoria e marca inexistentes são criadas apenas para quem pode gerenciá-las.
- `tipo_codigo`: `gtin` ou `interno`; vazio, é deduzido pelo dígito verificador. `controla_estoque`: `sim` ou `nao`.
- Duplicidade por SKU, código de barras ou código interno; sem nenhum código, pelo nome. Repetição dentro do arquivo é erro.
- Produtos existentes só são atualizados quando marcados linha a linha. Células vazias não apagam dados.
- O saldo nunca é alterado. `estoque_inicial` só vale para produto novo e gera movimentação com a chave `product-import:{importação}:{linha}`.
- Linha com erro não impede as demais. Confirmar de novo não repete nada.
- Limites: `PRODUCT_IMPORT_MAXIMUM_KILOBYTES` (2048) e `PRODUCT_IMPORT_MAXIMUM_ROWS` (500).
- As linhas lidas ficam criptografadas até a confirmação; prévias abandonadas são removidas após um dia por `model:prune` (exige o agendador ativo). Só o autor acessa a importação.

### Formato reservado para variações (não implementado)

Uma linha por variação, com `tipo` = `Produto com variações`, `nome` igual ao do produto e `variacao` no formato `Cor=Branca|Tamanho=M`. Hoje essas linhas são recusadas.

## Exportação CSV

Uma linha por unidade comercializável, com os filtros da listagem. Custo e saldo só aparecem com permissão. Fórmulas são neutralizadas por `CsvCell`. O arquivo pode ser reimportado; `tipo`, `variacao`, `saldo` e `situacao` não são gravados.

## Imagens

Disco privado, em `companies/{empresa}/products/{produto}`, servidas por rota autenticada. São recodificadas em WebP, o que descarta metadados. Sem GD com WebP, são gravadas como enviadas.

## Contratos internos

| Necessidade                                    | Contrato                                                             |
| ---------------------------------------------- | -------------------------------------------------------------------- |
| Produtos ativos                                | `ProductCatalog::activeProducts`                                     |
| Unidades comercializáveis e leitura por código | `ProductCatalog::sellableUnits`, `searchSellableUnits`, `findByCode` |
| Preços                                         | `ProductCatalog::pricesOf`                                           |
| Saldo                                          | `ProductCatalog::balanceOf`                                          |

Movimentações: `INVENTORY-CORE.md`.

## Pontos de extensão omnichannel (não implementados)

| Necessidade                           | Onde estender                                                                 |
| ------------------------------------- | ----------------------------------------------------------------------------- |
| Identificadores externos e mapeamento | Tabelas do domínio de integrações, ligadas a `products` e `product_variants`  |
| Publicações por canal                 | Domínio de integrações; nada específico de canal em `products`                |
| Sincronização de preços e catálogo    | Evento emitido após o commit de `ProductService`                              |
| Sincronização e reserva de estoque    | `INVENTORY-CORE.md`                                                           |
| Importação de pedidos                 | Módulo Vendas, com chave de idempotência derivada do pedido externo           |
| Origem comercial                      | `MovementOrigin` identifica o domínio; o canal fica no domínio de integrações |
| Imagens em catálogos externos         | `products.images.disk` e URLs temporárias                                     |
