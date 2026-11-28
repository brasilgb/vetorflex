# OMNICHANNEL-11

Entrega: estrutura comercial e operacional do VetorFlex.

- página pública com o cardápio real;
- cadastro com verificação de e-mail e 14 dias de teste;
- provisionamento único (público e RootAdmin);
- RootAdmin comercial;
- painel do assinante;
- identidade visual;
- páginas de acesso em português;
- guia de implantação e rollback.

Detalhes em `COMMERCIAL.md` e `DEPLOYMENT.md`. Nenhum módulo operacional novo foi criado.

## Navegação

- **Público**: `/` (página comercial), `/cadastro` (jornada de 4 passos), `/termos` e `/privacidade`. O contato é enviado por `POST /contato`.
- **Acesso**:
    - login, recuperação de senha, nova senha e verificação de e-mail, todos em português;
    - painel lateral da marca, com estados de carregamento e mensagens claras.
- **Assinante**:
    - **Painel**: boas-vindas, empresa ativa, aplicações habilitadas com indicadores reais e "Como começar", e situação do teste.
    - **Aplicações da empresa**: incluir ou remover, só pelo proprietário.
    - A seleção da empresa é automática quando há uma só.
- **RootAdmin**:
    - "Catálogo de aplicações", com dados comerciais e valor;
    - "Empresas", com cadastro completo, filtros, teste e contratação;
    - "Contatos comerciais".

## Migração

`2026_12_02_100000_add_commercial_onboarding`, aditiva:

- `platform_modules`: `tagline`, `benefits` e `monthly_price`;
- `companies`: `document`, `commercial_status` (padrão `active` para as empresas existentes), `trial_started_at`, `trial_ends_at`, `contracted_at`, `origin` e `provisioning_key` (única);
- tabelas `signups` e `contact_requests`.

## Ajustes em recursos compartilhados

- **Fortify**: o cadastro padrão foi desativado (substituído por `/cadastro`), a verificação de e-mail foi ativada (`User` implementa `MustVerifyEmail`) e há limites para cadastro e contato.
- **`ResolveTenant`**: recusa gravações quando o teste venceu; a consulta continua liberada.
- **RootAdmin**: ativar um módulo numa empresa inclui as dependências automaticamente (`CompanyModuleController`).
- **Conta**: o único proprietário de uma empresa não consegue excluir a própria conta.
- **Traduções**: `lang/pt_BR` (validação, autenticação, senhas). `APP_LOCALE=pt_BR`, com fallback para o inglês do framework. `APP_NAME=VetorFlex`.
- **Spedy**: mapeamento de `debitCard` e `check`, e códigos SPD transitórios mantêm o documento em processamento.
- **`.gitignore`**: `correio.md`, `executed.md`, `pnpm-lock.yaml` e `yarn.lock`. `executed.md` deixa de ser versionado; o histórico é preservado.
