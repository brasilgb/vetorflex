# OMNICHANNEL-01 — Execução

## Resumo do desenvolvimento

Foi criada a fundação inicial do Omnichannel como monólito modular Laravel: empresas, associação de usuários a empresas, RootAdmin, catálogo central de módulos, autorização por empresa, auditoria e ativação transacional de módulos.

## Decisões arquiteturais

- Banco compartilhado, com empresas em `companies` e vínculos em `company_user`.
- Contexto empresarial explícito pela sessão (`active_company_id`) e middleware `ResolveTenant`.
- Catálogo de plataforma separado da autorização por empresa (`platform_modules` e `company_modules`).
- Ativação/desativação em transação, preservando vínculos e histórico de auditoria.
- Dependências: Compras depende de Produtos; Vendas depende de Clientes e Produtos; Financeiro é independente.
- RootAdmin é identificado por `users.is_root_admin` e é redirecionado para `/rootadmin` após autenticação.

## Arquivos criados ou modificados

- Migrations para acesso RootAdmin, empresas, vínculos, módulos e auditoria.
- Models e serviços em `app/Modules` para Tenancy, ModuleManager e Core.
- Middleware de resolução de tenant, policy de empresa, requests e controllers RootAdmin.
- Seeder `PlatformModuleSeeder` e páginas Inertia do painel RootAdmin.
- Testes de ativação, isolamento de tenant e login RootAdmin.
- `docs/ARCHITECTURE.md`, `docs/TENANCY.md`, `docs/MODULES.md`, `docs/OMNICHANNEL-01.md`, `README.md` e `.env.example`.

## Migrations executadas

Em ambiente local, executadas com sucesso:

- `2026_11_24_134337_add_platform_access_to_users_table`
- `2026_11_24_134338_create_companies_table`
- `2026_11_24_134339_create_company_user_table`
- `2026_11_24_134340_create_platform_modules_table`
- `2026_11_24_134341_create_company_modules_table`
- `2026_11_24_134342_create_audit_logs_table`

O seeder registrou `customers`, `products`, `purchases`, `sales` e `finance` como módulos em desenvolvimento e não disponíveis comercialmente.

## Funcionalidades entregues

- Cadastro administrativo inicial de empresas.
- Vínculos multiempresa de usuários e papéis base no vínculo.
- Isolamento de contexto de tenant no middleware e policy de acesso à empresa.
- Catálogo central e páginas iniciais do RootAdmin.
- Validação de dependências, reativação sem perda de histórico e bloqueio de desativação com dependentes ativos.
- Registro de auditoria para criação de empresa e mudança de módulos.
- Recuperação de senha, verificação de e-mail, CSRF e rate limit permanecem fornecidos pela configuração Fortify existente.

## Testes realizados

- `vendor/bin/pint --format agent` — aprovado.
- `php artisan test --compact` — 29 aprovados, 2 ignorados, 73 assertions.
- `npm run build` — aprovado.
- `php artisan migrate --seed --no-interaction` — aprovado.
- `php artisan route:list --path=rootadmin` — 4 rotas confirmadas.

## Problemas encontrados

- O gerador de model criou inicialmente os models em namespace incompatível com a estrutura modular solicitada; eles foram realocados para `app/Modules` e a factory foi vinculada explicitamente ao model.

## Pendências

- Implementar as funcionalidades comerciais dos módulos Clientes, Produtos, Compras, Vendas e Financeiro.
- Construir seletor de empresa, navegação dinâmica por módulo, gestão completa de usuários/permissões e as telas operacionais do painel empresarial.
- Criar Docker/Compose para MySQL 8.4 e Redis, pois a infraestrutura não existia no projeto.
- Implementar o domínio de movimentação de estoque quando Compras e Vendas forem desenvolvidos.
- Definir e implementar contratos de integrações externas quando entrarem no escopo.

## Parecer técnico

A fundação está funcional para iniciar os módulos comerciais sem acoplar sua disponibilidade ao código instalado. O isolamento deve ser aplicado obrigatoriamente às futuras entidades empresariais com `company_id`, middleware e policies.

## Estado do Git

O repositório pai (`/home/anderson/projects/laravel/infra-abrasil`) reporta `gateway/vetornew/` como diretório não rastreado. Não houve push, deploy ou alteração nos demais sistemas Vetor.
