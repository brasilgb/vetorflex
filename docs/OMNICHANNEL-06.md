# OMNICHANNEL-06

Entrega: módulo Financeiro 1.0.0 (`FINANCE.md`) e painel administrativo geral da plataforma (RootAdmin).

## Navegação

Menu **Financeiro**: Visão geral, Contas a receber, Contas a pagar, Movimentações, Contas financeiras, Transferências, Fluxo de caixa, Recorrências, Conciliação, Relatórios e Configurações, conforme as permissões. A importação assistida fica em Configurações.

Menu **RootAdmin**: Visão geral, Empresas, Usuários, Catálogo de módulos, Administradores, Auditoria e Configurações.

## Ajustes em recursos compartilhados

- Vendas e Compras passaram a publicar eventos usados pelo Financeiro: `SaleBalanceClosed`, `SaleFulfillmentReversed`, `PurchaseConfirmed`, `PurchaseCancelled`, `PurchaseReturnRegistered`, `PurchaseBalanceClosed` e `PurchaseReceiptReversed`.
- Os contratos ganharam a consulta por período para a importação (`SaleReceivableSource::receivablesBetween`, `PurchaseObligationSource::obligationsBetween`) e campos de leitura (`closedBalanceValue`, `isBalanceClosed`, datas e nomes). Nenhuma regra comercial mudou.
- `AuditLog` ganhou as relações `user` e `company`.
- O teste do catálogo usava Financeiro como exemplo de módulo em desenvolvimento; passou a usar Clientes.

## RootAdmin

- **Cadastro**: `php artisan rootadmin:create` (interativo). Pede nome, e-mail, senha e confirmação. A senha precisa de no mínimo 8 caracteres, sem outras exigências (pode ser só números), e é gravada com hash. E-mail já cadastrado é recusado: o comando nunca eleva usuários existentes. Não há cadastro público nem credenciais em código ou seeders. A criação é auditada (`root_admin.created`).
- **Acesso**: login único em `/login`. O RootAdmin vai para `/rootadmin`; os demais usuários, para `/dashboard` (`LoginResponse`). O login é limitado a 5 tentativas por minuto por e-mail e IP. A troca de senha fica em Configurações › Segurança.
- **Proteção**: todas as rotas `/rootadmin` passam pelo middleware `root.admin` (`EnsureRootAdmin`, 403 para usuários empresariais). O RootAdmin não precisa de empresa nem de módulos. As rotas empresariais continuam exigindo vínculo ativo com a empresa, e o RootAdmin não ganha acesso empresarial por elas.
- **Áreas**:
  - Visão geral: contagens reais de empresas, usuários, administradores e empresas por módulo, além da atividade recente.
  - Empresas: cadastro, edição, suspensão e reativação com motivo, ativação de módulos com dependências e integrações e consulta de usuários.
  - Usuários: consulta com vínculos e papéis.
  - Catálogo: disponibilidade comercial.
  - Administradores: revogação com motivo, nunca do último ativo, com trava de concorrência.
  - Auditoria: filtros por evento, empresa e período.
  - Configurações: consulta da configuração em uso, sem segredos.
- **Auditoria**: `company.created`, `company.updated`, `company.suspended`, `company.reactivated`, `module.activated`, `module.deactivated`, `module.availability_changed`, `root_admin.created` e `root_admin.revoked`.
- **Pendências**: configurações da plataforma editáveis em tela, cadastro de administradores pelo painel, bloqueio individual de usuários e indicadores de faturamento (dependem do módulo Billing).

## Correção de compatibilidade

A migration do Financeiro gerava um índice de 65 caracteres (`finance_movements_company_id_finance_account_id_occurred_at_index`), acima do limite de 64 do MySQL e do MariaDB. O SQLite dos testes não tem esse limite. O índice passou a ter nome explícito.
