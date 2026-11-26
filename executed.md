# OMNICHANNEL-06 — Resultado

Data: 26/11/2026. Local: `gateway/vetorflex/`. Correio: SHA-256 `ed288873…`, seções 1 a 30. Detalhes do módulo em `docs/FINANCE.md`; resumo da etapa (inclusive RootAdmin) em `docs/OMNICHANNEL-06.md`.

## Consolidação da etapa anterior

- A OMNICHANNEL-05 está consolidada no commit `b54c891` (`feat: add sales module and inventory reservations`) do `.git-local` (`main`).
- A estrutura `.git` / `.git-local` não foi unificada nem alterada. Não houve push nem deploy nesta etapa.
- Parte do Financeiro (serviços, rotas, ouvintes, duas telas) já estava no diretório de trabalho no início desta sessão. Esse trabalho foi preservado, revisado por testes e concluído.

## Funcionalidades implementadas

**Financeiro 1.0.0** (independente, comercializável sozinho):

- Visão geral: previsto, realizado e vencido separados; filtros por período e conta; 12 meses; projeção de 30 dias; próximos vencimentos; despesas por categoria; saldo por conta.
- Contas financeiras: seis tipos, saldo calculado pelo livro e ajuste de saldo justificado e com permissão.
- Categorias e subcategorias, com sugestões iniciais editáveis e inativáveis.
- Contas a receber e a pagar:
  - lançamento manual, parcelamento e pessoa opcional (cadastro simplificado em `Parties`);
  - baixas integrais e parciais com juros, multa e desconto;
  - histórico, anexos, cancelamento, estorno e renegociação rastreável.
- Livro de movimentações (entrada, saída, transferência, ajuste e estorno), somente leitura, com exportação CSV.
- Transferências entre contas, fora do resultado, com estorno integral.
- Recorrências semanais, mensais, trimestrais, semestrais e anuais: comando agendado idempotente, sem baixa automática.
- Fluxo de caixa realizado e previsto, diário, semanal ou mensal, sem dupla contagem.
- Conciliação manual por conta e período; desfazer exige motivo.
- Oito relatórios com totais, CSV e PDF; caixa ou competência no relatório de receitas e despesas.
- Integrações opcionais com Vendas e Compras, inclusive cancelamentos, devoluções, encerramento de saldo e restituições.
- Importação assistida para ativação posterior, com prévia, seleção e baixa externa.
- 19 permissões `finance.*`; menu Financeiro com 11 itens conforme as permissões.

**RootAdmin** (§30):

- Comando `rootadmin:create`.
- Middleware `root.admin` em todas as rotas `/rootadmin`.
- Painel com visão geral real, empresas (cadastro, edição, suspensão e reativação), usuários, catálogo, administradores (revogação protegida), auditoria e configurações em consulta.

## Decisões arquiteturais

- Estrutura de títulos, baixas e livro com valores `decimal(15,2)` e `Brick\Math`. As chaves estrangeiras compostas `(company_id, id)` impedem vínculo entre empresas no banco.
- "Vencido" é derivado do vencimento e do saldo em aberto, nunca gravado.
- Integrações comerciais por eventos após o commit e contratos de leitura. O Financeiro só grava as próprias tabelas.
  - Reduções do valor devido ajustam apenas saldos em aberto.
  - Excesso já liquidado vira título de restituição explícito.
  - Falhas não interrompem Vendas e Compras.
- Venda parcialmente atendida: o título segue pelo total até o encerramento do saldo, quando o não atendido é ajustado, das últimas parcelas para as primeiras. O estorno do atendimento reconcilia novamente.
- A importação assistida valida a seleção inteira antes de gravar e roda numa transação única. **Correção desta sessão:** antes, importava parcialmente quando um item da seleção era inválido.
- RootAdmin sem domínio próprio: controladores em `RootAdmin`, cadastro só por console, revogação com trava e sem remover o último administrador.
- O catálogo de módulos registra Financeiro como implementado 1.0.0, sem dependências e com integrações opcionais. Continua indisponível comercialmente até decisão do RootAdmin e não é ativado automaticamente.

## Migrations

- `2026_11_27_100000_create_finance_tables`: 11 tabelas, aditiva.
- **Correção:** um índice tinha nome de 65 caracteres e falhava no MySQL/MariaDB. Passou a ter nome explícito (`finance_movements_account_occurred_index`).
- Aplicada ao banco local `new` (MariaDB 11.8). A primeira tentativa, com o nome antigo, criou dez tabelas e falhou. Essas tabelas, criadas nesta sessão e com dados de demonstração gerados minutos antes, foram removidas e a migration foi reaplicada sem erro.
- Rodados `PlatformModuleSeeder` e `FinanceDemoSeeder` (este duas vezes, sem duplicar: 25 títulos, 8 baixas, 10 movimentações).

## Arquivos relevantes

- **Backend novo**:
  - `app/Modules/Finance` (completado);
  - `app/Http/Controllers/Finance`, `app/Http/Requests/Finance`;
  - `routes/finance.php`, `config/finance.php`;
  - `database/seeders/FinanceDemoSeeder.php`;
  - `app/Console/Commands/CreateRootAdmin.php`, `app/Http/Middleware/EnsureRootAdmin.php`;
  - `app/Http/Controllers/RootAdmin/{PlatformOverview,PlatformUser,Administrator,AuditLog,PlatformSettings}Controller.php`.
- **Backend alterado**:
  - `RootAdmin/CompanyController` (edição e situação);
  - `AuditLog` (relações), `FinanceImportService` (atomicidade);
  - `PlatformModuleSeeder`, `DatabaseSeeder`;
  - `routes/web.php`, `bootstrap/app.php`;
  - eventos e contratos de Vendas e Compras (`docs/OMNICHANNEL-06.md`).
- **Front-end**:
  - `pages/finance/{dashboard,accounts,movements,transfers,cash-flow,recurrences,reconciliation,reports,settings,import}.tsx`;
  - `pages/root-admin/{overview,users,administrators,audit,settings}.tsx`, `companies.tsx` (reescrita);
  - `types/platform.ts`, `app-sidebar.tsx`.
- **Testes novos**: `FinanceTitleTest`, `FinanceCashTest`, `FinanceIntegrationTest`, `FinancePagesTest`, `FinanceDemoSeederTest`, `RootAdminPanelTest`. **Alterados**: `tests/Pest.php` (auxiliares) e `CompanyModuleTest` (Clientes como exemplo de módulo em desenvolvimento).
- **Documentação**: `FINANCE.md` e `OMNICHANNEL-06.md` (novos); `ARCHITECTURE.md`, `MODULES.md` e `SALES.md` (atualizados).

## Testes realizados

| Verificação | Resultado |
|---|---|
| `php artisan test --compact` (SQLite), regressão final | 394 aprovados, 2 ignorados, 0 falhas (76 testes novos) |
| Filtro `Finance` / `RootAdmin` | 82 aprovados |
| Concorrência de baixas no MariaDB 11.8 (banco temporário, removido ao final) | 6 processos simultâneos de R$ 60 num título de R$ 100: 1 aceito, 5 recusados. 6 processos com a mesma chave: 1 baixa, a mesma devolvida aos 6 |
| `npm run build` | Aprovado |
| `tsc` | 19 erros, todos em arquivos do starter kit não alterados; nenhum nos arquivos desta etapa |
| Pint | Aprovado |
| PHPStan | 20 apontamentos antigos (o mesmo número da etapa anterior); nenhum nos arquivos desta etapa |
| Migration e seed duplo no banco local | Aprovados, sem duplicidade |

Os testes cobrem:

- **Operação e cálculo**: operação só com Financeiro; parcelas com centavos na última; baixas parciais e integrais com juros, multa e desconto; limite ao saldo em aberto; idempotência e reutilização de chave.
- **Histórico**: estorno compensatório; cancelamento que preserva o liquidado; vencido derivado; renegociação.
- **Segurança**: isolamento entre empresas (404 e conta de outra empresa); permissões por operação e tipo; permissões de Vendas sem acesso ao Financeiro; anexos (tipo, isolamento e cabeçalhos).
- **Contas e caixa**: contas e saldo calculado; ajuste com permissão e justificativa; transferências fora do resultado e estorno; recorrências idempotentes e encerradas; conciliação; fluxo de caixa sem dupla contagem; visão geral previsto × realizado × vencido.
- **Integrações**: rascunho sem título; confirmação única; cancelamento com restituição; Vendas sem Financeiro; compra sem pagamento no recebimento; cancelamento de compra; importação assistida (prévia, rejeição atômica, idempotência e baixa externa); desativação do módulo.
- **Telas e saídas**: todas as telas; oito relatórios em CSV e PDF; CSV contra fórmulas; seed idempotente.
- **RootAdmin**: comando (sucesso, senha só numérica de 8 dígitos, e-mail existente, senha com menos de 8 caracteres e confirmação); redirecionamento após login; 403 para usuários empresariais; painel sem empresa; contagens reais; ciclo da empresa com auditoria; último administrador; nenhum acesso empresarial sem vínculo.

## RootAdmin — entrega

- **Cadastro inicial**: **pendente do proprietário.** O banco local não tem RootAdmin, e as credenciais não foram definidas por mim, como pede o correio. Para criar, execute `php artisan rootadmin:create` no terminal do projeto; o comando pede nome, e-mail e senha (mínimo de 8 caracteres; pode ser só números).
- **Novos administradores**: o mesmo comando. O painel mostra a instrução e permite revogar acessos.
- **URL**: `/login` e depois `/rootadmin` (no ambiente local, a `APP_URL` do `.env` seguida de `/rootadmin`).
- **Funcionalidades disponíveis**:
  - visão geral com contagens reais;
  - empresas: cadastrar, editar, suspender e reativar, ativar e desativar módulos com dependências e integrações, consultar usuários;
  - usuários e vínculos;
  - catálogo e disponibilidade comercial;
  - administradores;
  - logs e auditoria;
  - configurações em consulta;
  - troca de senha em Configurações › Segurança.
- **Pendentes**: configurações editáveis em tela, cadastro de administrador pelo painel, bloqueio individual de usuários e indicadores de faturamento (dependem de Billing).
- **Redirecionamento após login**: verificado por teste. O RootAdmin vai para `/rootadmin` e o usuário empresarial, para `/dashboard`.

## Limitações

- Não houve teste em navegador (não há Chrome no ambiente). Telas, diálogos e responsividade foram verificados só por build, tipos e testes de servidor.
- A concorrência foi medida no MariaDB 11.8 local, não no MySQL 8.4.
- Sem integração bancária, OFX, Pix ou boletos (fora do escopo); a estrutura de conciliação já tem `external_reference`.
- Por conta, o fluxo de caixa mostra só o realizado, porque títulos em aberto não têm conta definida.
- Créditos de cliente não são compensados automaticamente: a restituição gera um título a pagar para decisão manual.
- A edição de título não permite trocar o tipo; parcelas já geradas não são reprogramadas em bloco (use a renegociação).

## Pendências

1. Cadastro do RootAdmin pelo proprietário (`php artisan rootadmin:create`).
2. Homologação visual e de usabilidade em navegador, inclusive no celular.
3. Verificação de concorrência no MySQL 8.4.
4. Pendências anteriores: Compras ainda depende comercialmente de Produtos; Clientes ainda não usa `PartyService`; a configuração de venda sem identificação ainda não tem tela.

## Estado do Git

- `.git-local` (`main`): OMNICHANNEL-05 em `b54c891`. Tudo da OMNICHANNEL-06 está no diretório de trabalho, **sem commit**.
- `.git`: último commit `7ef80d4`. As OMNICHANNEL-05 e 06 estão sem commit ali.
- Não versionados, como antes: `correio.md`, `pnpm-lock.yaml` e `yarn.lock`. Sem push, sem deploy e sem alteração em outros sistemas.

## Critérios de aceite

| # | Critério | Situação |
|---|---|---|
| 1 | Financeiro funciona isoladamente | Atendido (teste só com Financeiro) |
| 2 | Contas a pagar e receber operacionais | Atendido |
| 3 | Baixas integrais e parciais | Atendido |
| 4 | Estornos preservam o histórico | Atendido |
| 5 | Saldos consistentes | Atendido (livro, sem saldo gravado) |
| 6 | Transferências sem duplicar receitas e despesas | Atendido |
| 7 | Fluxo realizado e previsto | Atendido |
| 8 | Recorrências idempotentes | Atendido |
| 9 | Integrações opcionais | Atendido (Vendas e Compras sem Financeiro) |
| 10 | Cancelamentos e devoluções tratados | Atendido (ajustes e restituições) |
| 11 | RootAdmin controla a habilitação | Atendido |
| 12 | Permissões e isolamento | Atendido |
| 13 | Relatórios com dados reais | Atendido |
| 14 | Frontend funcional | Atendido por build e renderização; sem homologação em navegador |
| 15 | Testes e build aprovados | Atendido |
| 16 | Documentação atualizada | Atendido |

## Parecer técnico

Os critérios 1 a 16 e a entrega do §30 estão atendidos e verificados por testes automatizados, build e migração real no MariaDB. Ficam duas ressalvas: as telas não foram observadas em navegador, e o cadastro do primeiro RootAdmin depende do proprietário. A próxima etapa (OMNICHANNEL-07) não foi iniciada.
