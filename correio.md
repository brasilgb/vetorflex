# OMNICHANNEL-03 — Módulo Produtos e núcleo de movimentação de mercadorias

## 1. Contexto

Projeto VetorNew, localizado em:

`gateway/vetornew/`

Plataforma SaaS de gestão empresarial omnichannel da ABrasilSistemas, construída como monólito modular Laravel, utilizando MySQL, Inertia e React.

A OMNICHANNEL-02 foi aprovada para continuidade.

Já estão implementados:

- Fundação multiempresa.
- RootAdmin.
- Gerenciamento de módulos.
- Painel empresarial.
- Seletor de empresas.
- Navegação dinâmica.
- Autorização por módulo.
- Permissões individuais no backend.
- Módulo Clientes.
- Domínio compartilhado de pessoas.

Existem clientes aguardando os módulos Clientes, Produtos, Compras, Vendas e Financeiro.

Priorizar desenvolvimento funcional e evitar novas etapas exclusivamente de auditoria.

## 2. Consolidação da etapa anterior

- Inspecionar o estado do Git.
- Consolidar a OMNICHANNEL-02 em commit local.
- Preservar o histórico existente em `.git-local`, caso a limitação do ambiente permaneça.
- Garantir que o seeder de demonstração não possa criar contas com senha conhecida em produção.
- Verificar proteção contra CSV injection nas exportações existentes.
- Registrar demais pendências da OMNICHANNEL-02 na documentação.
- Não interromper o desenvolvimento por pendências não impeditivas.
- Não realizar push ou deploy.

## 3. Objetivo

Implementar o módulo Products, disponibilizando um catálogo centralizado de produtos e serviços para cada empresa.

O módulo deverá funcionar independentemente de Compras, Vendas e Financeiro.

Sua arquitetura deverá permitir utilização futura por:

- Compras.
- Vendas.
- Estoque.
- PDV.
- E-commerce.
- Marketplaces.
- Logística.
- Emissão fiscal.

Não implementar integrações externas nesta etapa.

## 4. Estrutura de dados

Inspecionar a arquitetura existente e criar as entidades necessárias.

Estrutura conceitual sugerida:

- products
- product_variants
- product_categories
- product_brands
- units_of_measure
- product_attributes
- product_attribute_values
- product_variant_attributes
- product_images
- inventory_movements
- inventory_balances

Adaptar os nomes às convenções existentes.

Todos os registros empresariais devem respeitar company_id.

Utilizar integridade referencial, índices e restrições de unicidade apropriados.

Não permitir associações entre registros de empresas diferentes.

## 5. Cadastro de produtos e serviços

Implementar os seguintes tipos:

- Produto simples.
- Produto com variações.
- Serviço.

Campos principais:

- Nome.
- Descrição curta.
- Descrição completa.
- Código interno.
- SKU.
- Código de barras.
- Categoria.
- Marca.
- Unidade de medida.
- Referência do fabricante.
- Preço de custo.
- Preço de venda.
- Preço promocional opcional.
- Estoque mínimo.
- Controle de estoque habilitado ou desabilitado.
- Peso.
- Altura.
- Largura.
- Comprimento.
- Observações.
- Status ativo ou inativo.

Permitir cadastros simplificados, sem obrigar o preenchimento de campos desnecessários.

Serviços não devem gerar movimentações de estoque físico.

## 6. Categorias

Implementar:

- Cadastro.
- Edição.
- Pesquisa.
- Ativação e inativação.
- Categorias e subcategorias.
- Associação aos produtos.

Impedir dependências circulares na hierarquia.

Não permitir exclusão física indiscriminada de categorias utilizadas.

## 7. Marcas

Implementar:

- Cadastro.
- Edição.
- Pesquisa.
- Ativação e inativação.
- Associação opcional aos produtos.

## 8. Unidades de medida

Disponibilizar unidades comuns:

- Unidade.
- Caixa.
- Pacote.
- Quilograma.
- Grama.
- Litro.
- Mililitro.
- Metro.
- Centímetro.
- Hora.
- Serviço.

Permitir unidades personalizadas por empresa.

Suportar quantidades fracionárias quando aplicável.

Não implementar conversões complexas entre unidades nesta etapa.

## 9. Produtos e variações

Estabelecer uma estrutura consistente para os itens comercializáveis.

Um produto simples deverá possuir uma única unidade comercializável padrão.

Produtos com variações deverão possuir múltiplas unidades comercializáveis.

Permitir atributos como:

- Cor.
- Tamanho.
- Modelo.
- Material.
- Voltagem.
- Capacidade.
- Outros atributos personalizados.

Cada variação poderá possuir:

- SKU próprio.
- Código de barras próprio.
- Preço de custo.
- Preço de venda.
- Imagem.
- Peso e dimensões.
- Status.
- Saldo de estoque.

Implementar geração de combinações de atributos com prévia e confirmação.

Evitar combinações duplicadas.

Compras, Vendas e movimentações futuras deverão referenciar a unidade comercializável, evitando regras distintas de estoque para produtos simples e produtos com variações.

Não permitir alterações destrutivas em variações que já possuam movimentações ou referências comerciais.

## 10. SKU e código de barras

O SKU deverá ser único dentro da empresa, quando utilizado.

Permitir geração automática de SKU e preenchimento manual.

Normalizar identificadores quando apropriado.

Validar códigos GTIN/EAN/UPC quando informados como tais, permitindo códigos internos em campos apropriados.

Não exigir código de barras para cadastrar um produto.

Preparar busca por leitores de código de barras que funcionem como teclado.

Não implementar geração de etiquetas nesta etapa.

## 11. Preços e custos

Implementar:

- Preço de custo.
- Preço de venda.
- Preço promocional opcional.
- Cálculo de margem sobre a venda.
- Cálculo de markup sobre o custo.
- Histórico de alterações de preços e custos.

Diferenciar corretamente margem e markup.

Utilizar valores decimais no banco e cálculos monetários com precisão adequada, sem depender de ponto flutuante binário.

Não permitir preços negativos.

Preparar o modelo para tabelas de preços futuras, sem implementá-las nesta etapa.

## 12. Informações fiscais

Reservar campos opcionais para:

- NCM.
- CEST.
- Origem da mercadoria.

Não implementar cálculo tributário, CFOP automático, emissão fiscal ou validação de enquadramento tributário.

Não apresentar o preenchimento desses campos como garantia de conformidade fiscal.

## 13. Imagens

Permitir múltiplas imagens por produto.

Implementar:

- Upload.
- Imagem principal.
- Ordenação.
- Exclusão autorizada.
- Associação opcional à variação.
- Validação de tipo, tamanho e conteúdo.
- Redimensionamento e otimização quando suportados pelo ambiente.

Utilizar armazenamento Laravel com abstração por disco.

Garantir isolamento dos arquivos por empresa.

Remover metadados desnecessários quando possível.

Não permitir upload de arquivos executáveis.

Preparar a estrutura para futura publicação das imagens em catálogos externos.

Não expor imagens empresariais publicamente sem autorização.

## 14. Núcleo interno de estoque

Implementar o mecanismo básico compartilhado de movimentação de mercadorias.

O estoque avançado continuará sendo um módulo comercial futuro.

Nesta etapa, disponibilizar:

- Saldo atual.
- Estoque inicial.
- Entrada manual.
- Saída manual.
- Ajuste de estoque.
- Estoque mínimo.
- Histórico de movimentações.

Cada movimentação deverá registrar:

- Empresa.
- Unidade comercializável.
- Tipo.
- Quantidade.
- Origem da operação.
- Referência de origem, quando aplicável.
- Usuário responsável.
- Data e hora.
- Motivo ou observação.
- Chave de idempotência, quando aplicável.

O histórico deverá ser preservado.

Correções de movimentações deverão ocorrer por movimentações compensatórias, e não por alteração retroativa do histórico.

O saldo deverá ser atualizado exclusivamente pelo serviço responsável pelas movimentações.

Não permitir edição direta do saldo pelo cadastro de produtos.

Utilizar transações e bloqueios de concorrência apropriados.

Impedir duplicidade de movimentações.

Impedir estoque negativo por padrão.

Documentar a estratégia para configuração futura dessa regra.

Suportar quantidades decimais.

## 15. Preparação para Compras e Vendas

Disponibilizar serviços internos para:

- Consultar produtos ativos.
- Consultar unidades comercializáveis.
- Consultar preços.
- Consultar saldo.
- Registrar entradas.
- Registrar saídas.
- Estornar movimentações.
- Identificar a origem da movimentação.

Compras e Vendas deverão utilizar esses serviços, sem modificar diretamente o saldo de estoque.

Não implementar os módulos Compras e Vendas nesta etapa.

Preparar a futura atualização de custo médio ponderado por recebimento de compras.

Não implementar apuração contábil avançada.

## 16. Preparação para omnichannel

Documentar os pontos de extensão para:

- Identificadores externos por integração.
- Mapeamento de produtos e variações.
- Publicações por canal.
- Sincronização de estoque.
- Sincronização de preços.
- Importação de pedidos.
- Reserva de estoque.
- Registro da origem comercial.
- Eventos de atualização de catálogo.
- Processamento idempotente.

Não criar campos específicos de Mercado Livre, Shopee, Amazon ou outras plataformas diretamente na tabela principal de produtos.

Os dados específicos de cada canal deverão pertencer ao futuro domínio de integrações.

Não implementar conectores, filas fictícias ou sincronizações simuladas.

## 17. Interface operacional

Utilizar o layout empresarial e os componentes existentes.

Menu do módulo:

Produtos
- Todos os produtos.
- Categorias.
- Marcas.
- Unidades de medida.
- Movimentações.

Implementar:

- Listagem paginada.
- Pesquisa por nome, SKU, código interno e código de barras.
- Filtros por categoria, marca, tipo, situação e estoque.
- Cadastro rápido.
- Cadastro completo.
- Edição.
- Ficha do produto.
- Inativação.
- Reativação.

Organizar a ficha do produto em seções ou abas:

- Dados gerais.
- Preços.
- Variações.
- Estoque.
- Imagens.
- Informações fiscais.
- Histórico.

Exibir somente seções aplicáveis ao tipo de produto.

Priorizar facilidade de uso para MEIs e pequenas empresas.

Não criar interfaces excessivamente complexas para produtos simples.

## 18. Importação e exportação

Implementar importação e exportação CSV.

A importação deverá possuir:

- Modelo de arquivo.
- Prévia dos registros.
- Validação.
- Identificação de duplicidades.
- Confirmação antes de persistir.
- Relatório de erros.
- Limites configuráveis de tamanho e quantidade de registros.

Permitir importação de produtos simples inicialmente.

Documentar o formato para importação futura de variações.

Não sobrescrever produtos existentes sem confirmação explícita.

Não alterar diretamente o saldo de estoque durante importações.

Quando houver estoque inicial informado, gerar movimentação própria após confirmação, garantindo idempotência.

Proteger exportações contra CSV injection.

Garantir isolamento empresarial.

## 19. Permissões

Registrar permissões conforme o padrão existente:

- products.view
- products.create
- products.update
- products.deactivate
- products.reactivate
- products.import
- products.export
- products.manage_categories
- products.manage_brands
- products.manage_units
- products.view_cost
- products.manage_cost
- products.manage_prices
- products.view_inventory
- products.adjust_inventory

Validar no backend:

- Empresa ativa.
- Vínculo autorizado.
- Módulo habilitado.
- Permissão individual.

Os valores de custo não devem ser enviados ao frontend para usuários sem autorização.

Ocultar um componente visual não é suficiente para proteger informações.

## 20. Integração com RootAdmin

Ao concluir o módulo:

- Registrar Products como implementado.
- Atualizar sua versão.
- Registrar as permissões.
- Permitir controle de disponibilidade comercial.
- Permitir ativação por empresa.
- Atualizar a navegação dinâmica.
- Preservar os dados após desativação.
- Garantir reativação segura.

Produtos não deverá exigir contratação de Clientes.

Não habilitar automaticamente para todas as empresas.

## 21. Dashboard de Produtos

Disponibilizar indicadores reais:

- Total de produtos ativos.
- Produtos inativos.
- Produtos com estoque controlado.
- Produtos abaixo do estoque mínimo.
- Produtos sem estoque.
- Valor estimado do estoque pelo custo cadastrado.

O valor de estoque deverá estar protegido pela permissão correspondente.

Identificar claramente que se trata de estimativa operacional, não de valor contábil auditado.

## 22. Seed de demonstração

Complementar o ambiente de demonstração existente.

Criar:

- Produtos simples.
- Produtos com variações.
- Serviços.
- Categorias.
- Marcas.
- Unidades de medida.
- Diferentes preços.
- Estoques iniciais.
- Produtos abaixo do estoque mínimo.
- Movimentações de demonstração.

Utilizar exclusivamente dados fictícios.

Garantir execução idempotente.

Impedir execução indevida em produção.

## 23. Critérios de aceite

1. RootAdmin consegue autorizar Products para uma empresa.
2. Menu aparece somente para empresas e usuários autorizados.
3. Cadastro de produtos simples funciona.
4. Cadastro de produtos com variações funciona.
5. Cadastro de serviços funciona.
6. Categorias, marcas e unidades são gerenciáveis.
7. Pesquisa, filtros e paginação funcionam.
8. Imagens podem ser administradas.
9. Preços e custos respeitam permissões.
10. Entradas, saídas e ajustes de estoque funcionam.
11. Movimentações preservam histórico.
12. Saldo permanece consistente diante de operações concorrentes.
13. Importação e exportação funcionam.
14. Dados de empresas diferentes permanecem isolados.
15. Usuários sem autorização não conseguem acessar custos ou modificar estoque.
16. Frontend responsivo e build aprovado.
17. Serviços internos estão preparados para Compras e Vendas.

Executar testes direcionados às funcionalidades novas e aos riscos relevantes.

Não repetir auditorias completas das etapas anteriores.

## 24. Fora do escopo

- Compras.
- Vendas.
- Financeiro.
- PDV.
- Emissão fiscal.
- Integrações com marketplaces.
- Integrações com e-commerce.
- Múltiplos depósitos.
- Lotes e números de série.
- Inventários avançados.
- Transferências entre depósitos.
- Composição e kits de produtos.
- Etiquetas.
- Cobrança real.
- Inteligência artificial.
- Deploy.

## 25. Entrega

Criar:

- docs/PRODUCTS.md
- docs/INVENTORY-CORE.md
- docs/OMNICHANNEL-03.md

Atualizar a documentação arquitetural e modular existente.

Registrar no executed.md:

- Consolidação da OMNICHANNEL-02.
- Decisões arquiteturais.
- Funcionalidades implementadas.
- Migrations.
- Arquivos modificados.
- Testes realizados.
- Pendências.
- Estado do Git.
- Parecer técnico.

Não apresentar estruturas preparadas como funcionalidades concluídas.

Não interromper a execução com relatórios intermediários, salvo impedimentos técnicos reais.

Não realizar push, deploy ou alterações nos demais sistemas.

O objetivo é concluir o segundo módulo comercial da plataforma e estabelecer a base operacional para iniciar imediatamente o módulo Compras.
