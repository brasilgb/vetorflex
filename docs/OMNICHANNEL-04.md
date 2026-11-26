# OMNICHANNEL-04

Entrega: módulo Compras 1.0.0 (`PURCHASES.md`), fornecedores sobre o domínio compartilhado de pessoas (`SUPPLIERS.md`), custo médio ponderado móvel (`INVENTORY-COSTING.md`) e tela compartilhada de permissões individuais.

## Navegação

Menu **Compras**: Visão geral, Pedidos de compra, Nova compra (compra rápida), Recebimentos, Fornecedores e Devoluções, exibido conforme as permissões. Menu **Usuários e permissões** para proprietário e administradores.

## Permissões individuais (`/company/members`)

Atende todos os módulos contratados (hoje Clientes, Produtos e Compras), a partir do catálogo de permissões de cada módulo (`MemberPermissionService`).

- Lista usuários, papéis e permissões por módulo habilitado, e as últimas alterações (auditoria `company.permissions_changed`, com concedidas e revogadas).
- O proprietário administra administradores e operadores; o administrador, apenas operadores.
- Ninguém concede ou revoga permissões que não possui, nem altera o próprio acesso ou o de um proprietário. Permissões fora do alcance do responsável (inclusive de módulos desativados) são preservadas.
- Papéis, situação do vínculo e o indicador RootAdmin não são alterados por esta tela.

Resolve a pendência "não há tela para conceder permissões individuais" da OMNICHANNEL-03. Convites de novos usuários e troca de papel continuam fora do escopo.

## Componentes compartilhados criados

- `Core\Services\SequenceService`: numeração por empresa para documentos.
- `Core\Support\CsvFormat`: leitura e escrita de CSV, extraída de `ProductCsv` sem mudar seu comportamento.
- `Core\Documents\PdfWriter` e `CommercialDocument`: PDF de documentos comerciais, para Vendas.
- `Parties\Services\PartyService` e `Parties\Concerns\ValidatesParty`: gravação e validação da pessoa compartilhada. Clientes ainda usa sua própria gravação (ver pendências).
- `PartyFactory` e `SupplierProfileFactory`.

## Pendências registradas

| Pendência | Impacto |
|---|---|
| Interações no navegador não foram testadas (sem Chrome no ambiente) | Diálogos de recebimento e devolução, geração de parcelas, abas e responsividade exigem homologação manual, inclusive em celular. As telas foram verificadas por resposta do servidor |
| Clientes não usa `PartyService` e recusa documento já cadastrado | O vínculo cliente/fornecedor só é feito a partir de Fornecedores; Clientes mantém as pendências da OMNICHANNEL-02 |
| Dados cadastrais da empresa compradora (documento, endereço) não têm tela | O PDF exibe apenas o nome, salvo se `companies.settings` for preenchido |
| Importação de fornecedores síncrona, até 500 linhas | Volumes maiores exigem fila |
| Teste de concorrência feito em MariaDB 11.8 local, não em MySQL 8.4 | Repetir no MySQL 8.4 do `compose.yaml` antes da produção |
| Imutabilidade de recebimentos e do histórico de custo garantida pela aplicação | SQL direto ainda conseguiria alterá-los |
| Obrigações financeiras apenas como contrato | Contas a pagar na OMNICHANNEL-06 |
