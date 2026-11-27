# OMNICHANNEL-09 — Resultado

Data: 27/11/2026. Local: `gateway/vetorflex/`. Correio: SHA-256 `dfb04e55…`, seções 1 a 18. Detalhes do módulo em `docs/PAYMENTS.md`; resumo da etapa e análise dos repositórios em `docs/OMNICHANNEL-09.md`.

## Consolidação da etapa anterior

- O `correio.md` mudou desde a etapa anterior (passou da OMNICHANNEL-08 para a OMNICHANNEL-09).
- A OMNICHANNEL-08 foi consolidada no commit `408adcc` (`feat: add stock module with warehouses, transfers and counts`) do `.git-local` (`main`), antes de qualquer alteração desta etapa.
- Não havia alterações concorrentes além do `correio.md`. Sem push, sem deploy, e `.git`/`.git-local` não foram unificados.

## Funcionalidades implementadas

**Pagamentos 1.0.0** (`payments`, independente, comercializável sozinho):

- **Central de cobranças**:
  - criação avulsa, de venda ou de conta a receber;
  - pagador existente ou cadastro simplificado por `PartyService`;
  - descrição, valor, vencimento, referência interna, origem e histórico imutável;
  - listagem com filtros e detalhe;
  - emissão, consulta ao provedor, cancelamento e devolução;
  - QR Code, Pix copia e cola e link de pagamento, que podem ser copiados para compartilhar;
  - segunda via por impressão e exportação CSV.
- **Estados internos** independentes do provedor: rascunho, pendente, processando, pago, parcialmente pago, vencido, cancelado, expirado, estornado e falha.
- **Arquitetura de provedores**: contrato `PaymentProvider` com capacidades declaradas; as telas mostram só o que o provedor implementa. A **Efí (Pix)** foi implementada conforme a documentação oficial vigente:
  - OAuth2 com mTLS (certificado P12 ou PEM);
  - cobrança imediata com txid próprio;
  - QR Code e link;
  - consulta, remoção e devolução;
  - registro de webhook.
- **Configurações por empresa**:
  - provedor e ambiente (homologação ou produção);
  - credenciais e certificado criptografados, nunca devolvidos à tela;
  - chave Pix, formas habilitadas, validade do Pix e conta financeira;
  - teste de conexão, situação da integração e registro do webhook;
  - instruções passo a passo e última falha, sem segredos.
- **Webhooks**:
  - a empresa é identificada pelo token secreto da conta;
  - IPs de origem conferidos opcionalmente;
  - notificação persistida antes de processar e processamento por fila com retentativas;
  - idempotência, tolerância a eventos repetidos e fora de ordem;
  - confirmação sempre por consulta ao provedor;
  - registro de falhas e reprocessamento autorizado;
  - conciliação periódica a cada 15 minutos.
- **Visão geral** com indicadores reais:
  - recebido, pendente e vencido;
  - emitidas, pagas e taxa de recebimento;
  - por forma, provedor e origem;
  - últimas cobranças e falhas;
  - bruto, devolvido, taxas e líquido quando informados;
  - filtros por período, provedor e origem.
- **Telas**: conciliação, relatórios por dia com CSV de recebimentos e configurações.
- **Permissões e auditoria**: 9 permissões `payments.*`; operações sensíveis auditadas.
- **Integrações opcionais**:
  - **Vendas**: cobrança do saldo devido, uma cobrança aberta por venda e cobranças visíveis na ficha da venda.
  - **Financeiro**: cobrança de conta a receber, baixa somente após confirmação, sem baixa duplicada, e estorno compensatório nas devoluções.
  - **PDV**: Pix dinâmico com QR Code e confirmação do provedor; distinção entre pagamento manual e confirmado; tratamento de expiração, cancelamento e pagamento tardio.
- **RootAdmin**:
  - disponibilidade dos provedores;
  - indicadores agregados, só contagens;
  - integrações com falha;
  - eventos técnicos sem conteúdo nem credenciais.

## Efetivamente operacional

- **Funciona hoje**, de ponta a ponta com a Efí simulada nos testes e na demonstração:
  - fluxo completo da cobrança: criação, emissão, confirmação, cancelamento e devolução;
  - webhooks, conciliação e integrações com Vendas, Financeiro e PDV;
  - telas, permissões, CSV e RootAdmin.
- **Depende de credenciais e homologação externa**:
  - comunicação real com a Efí: Client ID, Client Secret e certificado da empresa, aplicação com os escopos de Pix na conta Efí, e teste em homologação;
  - registro do webhook: exige mTLS configurado no servidor web com a cadeia de certificados da Efí;
  - sem webhook, a conciliação periódica confirma os pagamentos.
- **Chamadas reais**: nenhuma chamada financeira real foi feita nesta etapa.

## Decisões arquiteturais

- O webhook só indica o que consultar. A situação vem da consulta ao provedor, e pagamentos são únicos pelo `endToEndId`, por isso repetição ou ordem invertida não duplicam nem regridem a situação.
- O txid é gravado antes da emissão, então repetir a emissão nunca cria outra cobrança no provedor (409 é tratado com consulta).
- `active_origin_key` único por empresa garante uma cobrança aberta por venda, título ou carrinho do PDV.
- Financeiro só pelo ouvinte do próprio Financeiro (`SyncPaymentOperations`), com chaves determinísticas. Cobranças do PDV ficam fora desse caminho, porque o PDV já baixa pela sua integração: não há receita duplicada.
- Pagamentos tardios (depois de expirada ou cancelada) são registrados, porque o dinheiro foi recebido. Pix do PDV pago sem venda concluída aparece na conciliação.
- Desativar o módulo preserva os dados, e os webhooks continuam registrando pagamentos reais.
- Valores com `Brick\Math` e `decimal(15,2)`, sem ponto flutuante; no PDV, as diferenças são calculadas em inteiros.

## Migrations

- `2026_11_30_100000_create_payments_tables`, aditiva:
  - tabelas `payment_providers`, `payment_provider_accounts`, `payment_charges`, `payment_charge_events`, `payment_transactions`, `payment_refunds` e `payment_webhook_events`;
  - colunas `payment_charge_id` e `is_provider_confirmed` em `pos_payments`;
  - nomes de chave explícitos, dentro do limite do MySQL/MariaDB.
- Aplicada ao banco local `new` (MariaDB) sem erro na primeira tentativa.
- Rodados `PlatformModuleSeeder` e `PaymentsDemoSeeder`, este duas vezes sem duplicar: 6 cobranças, 4 pagamentos, 1 Pix confirmado no PDV.

## Arquivos relevantes

- **Backend novo**:
  - `app/Modules/Payments`:
    - provedores: `EfiProvider`, `PaymentProvider`, `ProviderRegistry`, `ProviderException`;
    - serviços: `ChargeService`, `ChargeOrigins`, `ChargeQueries`, `ChargeDirectory`, `ProviderAccountService`, `WebhookService`, `PaymentsModule`;
    - fila, eventos e apoio: `ProcessPaymentWebhook`, `PaymentReceived`, `PaymentRefundConfirmed`, `PaymentPresenter`, modelos e enums;
  - `app/Http/Controllers/Payments/*`, `app/Http/Controllers/Pos/PosPixController.php`, `app/Http/Controllers/RootAdmin/PaymentsAdminController.php`;
  - `app/Modules/Pos/Services/PosPix.php`, `app/Modules/Finance/Listeners/SyncPaymentOperations.php`;
  - `routes/payments.php`, `config/payments.php`;
  - `database/seeders/PaymentsDemoSeeder.php`, `database/seeders/Support/SimulatedEfi.php`.
- **Backend alterado**:
  - `PosCheckout`, `PosPayment`, `PosCartRequest`, `PosRegisterController`, `routes/pos.php`;
  - `SaleOrderController`, `FinanceTitleController`;
  - `AppServiceProvider`, `bootstrap/app.php` (CSRF), `routes/web.php`, `routes/console.php`;
  - `PlatformModuleSeeder`, `DatabaseSeeder`.
- **Front-end**:
  - `pages/payments/{dashboard,reconciliation,reports,settings}.tsx`, `pages/payments/charges/{index,create,show}.tsx`, `pages/root-admin/payments.tsx`;
  - `components/payments/*`, `components/pos/pix-confirmation.tsx`, `components/pos/payment-dialog.tsx`, `pages/pos/register.tsx`;
  - `pages/sales/orders/show.tsx`, `pages/finance/titles/show.tsx`, `app-sidebar.tsx`, `types/payments.ts`.
- **Testes**:
  - novos: `PaymentChargeTest`, `PaymentIntegrationTest`, `PaymentsDemoSeederTest`;
  - alterado: `tests/Pest.php` (auxiliares e Efí simulada com `Http::fake` e `preventStrayRequests`).
- **Documentação**:
  - novos: `PAYMENTS.md` e `OMNICHANNEL-09.md`;
  - atualizados: `MODULES.md` e `ARCHITECTURE.md`.

## Testes executados

- **Pagamentos**: 30 testes, 295 asserções. Cobrem:
  - Pagamentos independente, isolamento empresarial e permissões;
  - idempotência da criação e emissão sem duplicar no provedor;
  - webhooks duplicados e fora de ordem;
  - webhook sem prova não confirma, token inválido e IP;
  - confirmação, cancelamento e devolução;
  - integrações com Vendas, Financeiro e PDV;
  - ausência de baixa e receita duplicadas;
  - credenciais criptografadas e fora da tela;
  - disponibilidade do provedor, desativação e reativação;
  - conciliação, reprocessamento, CSV, telas, RootAdmin e demonstração.
- **Regressão completa**: **501 aprovados, 2 ignorados, 0 falhas** (503 testes, 3.998 asserções).
- **Verificações**:
  - `npm run build` aprovado;
  - `tsc --noEmit` sem erros;
  - PHPStan do projeto inteiro com 0 erro;
  - Pint aplicado;
  - `vp check` sem avisos nos arquivos desta etapa.
- **Chamadas externas**: nenhuma chamada externa nos testes (`Http::preventStrayRequests`).

## Limitações

- Somente Pix (Efí). Boleto, cartão, Sicredi e outros provedores não foram implementados (sem integrações fictícias).
- A consulta documentada da Efí não informa a tarifa do Pix: taxas e líquido ficam vazios.
- O link de visualização da Efí só funciona em produção.
- A segunda via é a impressão da página da cobrança, não um PDF próprio.
- Devolução de cobrança de venda não altera a venda; o acerto comercial segue em Vendas.
- Não houve teste em navegador (não há Chrome no ambiente).

## Pendências

1. Homologação real com uma conta Efí da empresa (credenciais, certificado e escopos) e teste das cobranças de até R$ 10,00, que a Efí confirma automaticamente em homologação.
2. Configuração de mTLS no servidor web para o webhook da Efí (cadeia de certificados da Efí) e definição de `PAYMENTS_WEBHOOK_ENFORCE_IPS` em produção.
3. Fila com worker em execução contínua em produção, além do agendador (`schedule:run`) para a conciliação.
4. Homologação visual em navegador, inclusive no celular e no PDV.
5. Decisão sobre a consolidação dos repositórios Git (proposta abaixo).

## Estado dos dois repositórios Git

- **`.git`**:
  - remoto `origin` em `git@github-vetorflex:brasilgb/vetorflex.git`, ramo `main`;
  - 2 commits: `f6724bc` e `7ef80d4`;
  - o conteúdo de `7ef80d4` é igual ao do `8919435` do `.git-local`, mais `correio.md`, `pnpm-lock.yaml` e `yarn.lock`;
  - as OMNICHANNEL-05 a 09 não estão nele.
- **`.git-local`**:
  - histórico das etapas, sem remoto: 8 commits até `408adcc` (OMNICHANNEL-08);
  - a OMNICHANNEL-09 está no diretório de trabalho, **sem commit**.
- **Proposta (não executada)**: manter o `.git` como oficial e registrar o estado atual num ramo novo a partir de `origin/main`, num único commit, sem reescrever histórico nem push forçado. Depois, revisar por pull request, decidir sobre os arquivos de lock e `correio.md`, e arquivar o `.git-local` como bundle fora do projeto. Detalhes e a alternativa com `cherry-pick` em `docs/OMNICHANNEL-09.md`.

## Critérios (§3 a §18)

| Seção | Situação |
|---|---|
| 2. Consolidação e Git | Atendido (commit `408adcc`; divergência documentada com proposta) |
| 3. Princípios | Atendido |
| 4. Central de pagamentos | Atendido |
| 5. Provedores | Atendido (Efí Pix real conforme documentação; nenhum provedor fictício) |
| 6. Configurações por empresa | Atendido |
| 7. Vendas | Atendido |
| 8. Financeiro | Atendido (taxas registradas somente quando o provedor informar) |
| 9. PDV | Atendido |
| 10. Webhooks e fila | Atendido (mTLS depende do servidor web) |
| 11. Dashboard | Atendido |
| 12. Experiência | Atendido por build e renderização; sem homologação em navegador |
| 13. RootAdmin | Atendido |
| 14. Permissões | Atendido |
| 15. Demonstração | Atendido (provedor simulado) |
| 16. Testes | Atendido |
| 18. Documentação e entrega | Atendido |

## Parecer técnico

A OMNICHANNEL-09 está implementada e verificada por testes automatizados (com a Efí simulada), build, PHPStan e migração real no MariaDB. A operação real depende de credenciais e homologação na Efí e de mTLS no servidor para o webhook. A OMNICHANNEL-10 não foi iniciada.
