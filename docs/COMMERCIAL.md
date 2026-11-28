# Estrutura comercial (`app/Modules/Commercial`)

Página pública, cardápio de aplicações, cadastro com período de teste, provisionamento e gestão comercial pelo RootAdmin. Não é um módulo contratável: é a camada de plataforma que cria e administra as empresas assinantes.

## Catálogo como fonte única

- `platform_modules` guarda os dados técnicos (dependências, permissões, situação de desenvolvimento) e os dados comerciais:
    - `name`, `tagline`, `description`, `benefits`, `icon`, `category`, `sort_order`;
    - `monthly_price`, opcional: sem valor, a aplicação aparece como "valor sob consulta".
- `ApplicationCatalog`:
    - oferece no cardápio público só aplicações **implementadas e disponibilizadas** pelo RootAdmin;
    - `quote()` resolve as dependências (transitivas, em ordem topológica, recusando ciclos) e soma os valores configurados.
    - Seleção, dependências e valores são sempre recalculados no servidor: preço ou permissão enviados pelo navegador são ignorados.
- **Seeder**: `PlatformModuleSeeder` traz textos iniciais e **nenhum valor**.
    - Não sobrescreve nome, descrição, textos, valores, ordem ou disponibilidade já editados pelo RootAdmin.
    - Só atualiza os dados técnicos.

## Cadastro público

Jornada `/cadastro`: aplicações → revisão (`POST /cadastro/revisao`) → responsável → empresa e aceite dos termos.

1. `SignupService::register` (transação) cria o usuário, com e-mail ainda não verificado, e o registro `signups`.
    - O registro guarda as aplicações, a empresa, a versão dos termos, o aceite e o IP.
    - Dispara a verificação de e-mail.
2. **Idempotência**:
    - a mesma `idempotency_key` com a mesma senha retoma a sessão sem duplicar;
    - com outra senha, vale a regra de e-mail único.
    - **Limites**: 5 cadastros por minuto por IP; 10 contatos por hora por IP; o formulário de contato tem campo-armadilha contra robôs.
3. **Provisionamento**:
    - na verificação do e-mail (`ProvisionVerifiedSignup`), o `CompanyProvisioning` cria a empresa;
    - se falhar (por exemplo, uma aplicação saiu da venda), o cadastro fica `failed` e o painel tenta de novo a cada acesso, sem duplicar (chave `signup:<chave>`).
4. **Painel**: a empresa recém-criada é selecionada automaticamente. Um usuário com uma só empresa também.

## Provisionamento (`CompanyProvisioning`)

O mesmo serviço atende o cadastro público e o RootAdmin, sem regras duplicadas. Em uma transação, ele cria:

- a empresa: nome, identificador único, documento, fuso, moeda, origem `public`, `admin` ou `demo`;
- o vínculo do proprietário;
- as aplicações e dependências, na ordem, via `ModuleActivationService` (com auditoria);
- o período de teste.

As permissões iniciais são implícitas: o proprietário tem todas as permissões dos módulos habilitados. As configurações de cada módulo usam padrões próprios e nascem no primeiro uso.

- **Idempotência**: `companies.provisioning_key` é única.
- **Ampliar ou reduzir**: `addApplications` e `removeApplication`. Uma dependência de aplicação ativa não sai, e os dados são preservados.

## Período de teste (`TrialService`)

- **Situação comercial** (`companies.commercial_status`): `trial`, `active` (contratada) ou `expired`. É independente de `companies.status` (ativa ou suspensa pelo RootAdmin).
- **Vigência**: definida e conferida só no servidor (`trial_started_at`, `trial_ends_at`). Empresas anteriores à etapa ficam como contratadas.
- **Vencido o teste**:
    - os dados seguem acessíveis para consulta;
    - qualquer gravação nas rotas empresariais é recusada com orientação (`ResolveTenant`);
    - `commercial:expire-trials` marca os vencidos todo dia, mas o bloqueio vale pelo vencimento mesmo antes da rotina.
- **Contratação**:
    - não há cobrança automática;
    - o RootAdmin registra a contratação com a referência (auditado);
    - o RootAdmin prorroga o teste com justificativa (auditado).
- Ativar, desativar ou reativar aplicações **não** reinicia o teste.
- A cobrança da assinatura do VetorFlex é separada do módulo Pagamentos, que trata do que as empresas recebem dos próprios clientes. Ela ainda não existe (pendência comercial).

## RootAdmin

- **Catálogo de aplicações**: editar dados comerciais e valor; disponibilizar ou retirar da venda.
- **Empresas**:
    - cadastro com proprietário (conta existente é vinculada; conta nova recebe link para definir a senha), aplicações com dependências e dias de teste, ou já contratada;
    - filtros por situação (em teste, contratadas, teste encerrado, suspensas);
    - início e vencimento do teste e composição com valor de referência;
    - prorrogação, contratação, suspensão e reativação.
- **Contatos comerciais**: pedidos enviados pela página pública.
- **Integrações por empresa**:
    - a liberação de produção fiscal continua em Documentos fiscais;
    - Pagamentos usa a conta da própria empresa no provedor.

## Páginas públicas

- `/`: página comercial com o cardápio real, imagens da interface real (geradas do ambiente de demonstração em `public/images/landing`, exibidas só se existirem), perguntas frequentes e contato.
- `/termos` e `/privacidade`: versão `config('commercial.terms_version')`. **O texto precisa de revisão jurídica antes da publicação.**
