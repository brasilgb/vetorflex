# OMNICHANNEL-10 — Resultado

Data: 27/11/2026. Local: `gateway/vetorflex/`. Correio: SHA-256 `26ba468d8fac…`, com a OMNICHANNEL-10 (seções 1 a 22) e o complemento obrigatório de reaproveitamento do VetorOS (seções 1 a 7). Detalhes do módulo em `docs/FISCAL.md`; resumo da etapa em `docs/OMNICHANNEL-10.md`.

## Consolidação da etapa anterior

- O `correio.md` mudou desde a etapa anterior (passou da OMNICHANNEL-09 para a OMNICHANNEL-10).
- A OMNICHANNEL-09 foi consolidada no commit `9bd0657` (`feat: add payments module with efi pix integration`) do `.git-local` (`main`), antes de qualquer alteração desta etapa.
- A OMNICHANNEL-10 segue sem commit, conforme a convenção das etapas: ela será consolidada no início da próxima.
- Sem push e sem deploy.

## Funcionalidades implementadas

**Documentos Fiscais 1.0.0** (`fiscal`): independente e comercializável sozinho. Funciona sem Clientes, Produtos, Vendas, PDV e Financeiro.

- **Arquitetura de provedores**:
    - contrato `FiscalProvider` com capacidades declaradas e `FiscalProviderRegistry`;
    - disponibilidade, tipos liberados e ambiente da conta da plataforma decididos pelo RootAdmin (`fiscal_providers`);
    - um tipo só é oferecido se estiver liberado na plataforma **e** implementado pelo provedor.
- **Spedy (API v1)**, adaptada do VetorOS:
    - cadastro da empresa como emissora e envio do certificado A1;
    - emissão de NFS-e, NF-e e NFC-e;
    - consulta, cancelamento, XML e PDF;
    - webhook assinado (Standard Webhooks).
- **Configuração fiscal por empresa**:
    - emitente (razão social, CNPJ, inscrições, endereço, código IBGE) e regime tributário;
    - ambiente, tipos habilitados e séries;
    - padrões da NFS-e (item da LC 116, ISS, tipo de tributação, NFS-e Nacional ou municipal);
    - padrões de mercadorias (unidade, origem, CFOP, CST/CSOSN, PIS, COFINS);
    - ID e CSC da NFC-e;
    - confirmação tributária pelo responsável, desfeita ao alterar dados tributários;
    - painel de pendências por tipo.
- **Emissão avulsa**:
    - rascunho (salvar nunca transmite) e edição;
    - pré-visualização com pendências de configuração e dados que o provedor recusaria;
    - emissão só por ação explícita.
- **Cadastro fiscal de itens**:
    - página "Dados fiscais dos itens" no catálogo compartilhado: NCM, CEST, origem, CFOP, CST/CSOSN, PIS, COFINS, unidade tributável, LC 116 e código municipal;
    - validação só de formato, com filtro de incompletos;
    - nenhum código sugerido ou completado.
- **Central de documentos**:
    - filtros por tipo, situação, origem, período, número, chave, destinatário e CPF/CNPJ;
    - estados internos, motivo de rejeição e histórico imutável;
    - consulta ao provedor, reenvio e cancelamento (justificativa de 15 a 255 caracteres);
    - XML e PDF protegidos, cópia da chave de acesso e CSV.
- **Integrações opcionais** (só com o módulo contratado e sem efeito comercial):
    - Vendas: NF-e, ou NFS-e quando só há serviços;
    - PDV: NFC-e, distinta do comprovante não fiscal;
    - Financeiro: NFS-e de conta a receber, sem baixa.
    - Cada ficha de origem mostra os seus documentos. Há um documento ativo por origem e tipo.
- **Processamento assíncrono**:
    - jobs `TransmitFiscalDocument`, `StoreFiscalDocumentFiles` e `ProcessFiscalWebhook`;
    - `integrationId` estável, com consulta pelo identificador antes de reenviar;
    - situação que só avança;
    - comando `fiscal:reconcile` a cada 5 minutos;
    - consulta de pendentes e reprocessamento de notificações pelo painel (`fiscal.reprocess`).
- **Telas**: painel fiscal (indicadores do mês, prontidão por tipo, documentos que pedem atenção, notificações com falha), menu, 10 permissões, auditoria e relatório por tipo e situação (CSV).
- **RootAdmin**:
    - provedor, tipos liberados e ambiente sandbox ou produção;
    - chave da empresa titular, somente escrita;
    - teste de conexão e registro do webhook com guarda do segredo;
    - liberação de produção por empresa;
    - indicadores agregados, só contagens.
- **Demonstração**: `FiscalDemoSeeder` com a Spedy simulada, em quatro cenários:
    - somente fiscal: autorizada, rejeitada, cancelada e um rascunho;
    - fiscal + Vendas: NF-e;
    - fiscal + PDV: NFC-e;
    - fiscal + Financeiro: NFS-e sem baixa.
- **Revisão da Efí**: o registro do webhook voltou a enviar `x-skip-mtls-checking: true`, prática da integração em produção do VetorOS. A confirmação segue só por consulta à API. `PAYMENTS.md`, o texto de ajuda e um teste foram atualizados.

## Documentos efetivamente suportados

| Documento         | Situação                                                                                                                                                                                                                                   |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| NFS-e             | Implementada pela Spedy (`service-invoices`), com padrão NFS-e Nacional (ADN) ou municipal. Homologação no ambiente "simulação" da Spedy. A cobertura por município é da Spedy: um município sem integração resulta em rejeição explícita. |
| NF-e (modelo 55)  | Implementada (`product-invoices`), com destinatário com CPF/CNPJ e endereço. CST de ICMS só 40, 41, 50 e 60 no Regime Normal; CSOSN no Simples.                                                                                            |
| NFC-e (modelo 65) | Implementada (`consumer-invoices`), com ID e CSC da SEFAZ. Consumidor identificado só com documento válido.                                                                                                                                |

**Somente simulado nesta etapa**: todas as emissões, consultas, cancelamentos, XML, PDF e webhooks. Eles foram exercitados contra `SimulatedSpedy` (testes e demonstração), sem nenhuma requisição externa. Nenhuma emissão nem cobrança real foi feita.

**Não disponível**:

- contingência: não declarada; uma nota em contingência aparece como "Processando";
- carta de correção (CC-e);
- inutilização de numeração;
- notas recebidas (manifestação);
- reenvio de e-mail pelo provedor;
- campos IBS/CBS da Reforma Tributária;
- CST de ICMS com destaque e PIS/COFINS com alíquota;
- outros provedores.

## Dependências de homologação

- Conta Spedy de sandbox ("Plano Desenvolvedor") e de produção, com a chave da empresa titular de cada ambiente, informada no RootAdmin.
- Webhook registrado pelo RootAdmin.
- Empresa autorizada com certificado A1 real, inscrições e credenciamento: SEFAZ para NF-e e NFC-e, com CSC; prefeitura para NFS-e, conforme a cobertura da Spedy.
- Códigos fiscais e alíquotas confirmados pelo contador.
- Liberação de produção por empresa, após homologação acompanhada.
- Pontos da documentação da Spedy não confirmados nesta leitura, a validar na homologação:
    - corpo exato da criação de NF-e/NFC-e: os nomes vêm da integração em produção do VetorOS;
    - corpo de `POST /webhooks`;
    - caminhos de cancelamento, XML e PDF da NFS-e, que seguem o padrão das demais;
    - status HTTP de criação duplicada;
    - envelope de erro.

## Migrations

`2026_12_01_100000_create_fiscal_tables` (aditiva, executada no MariaDB local):

- `fiscal_providers`: disponibilidade, tipos, ambiente e credenciais da plataforma, criptografadas.
- `fiscal_settings`: configuração por empresa. Chave da emissora e CSC criptografados; do certificado, só titular e validade.
- `fiscal_documents`:
    - `integration_id` único e `idempotency_key` único por empresa;
    - índices por situação, origem e referência do provedor.
- `fiscal_document_events`: histórico imutável, com FK composta `(company_id, fiscal_document_id)`.
- `fiscal_webhook_events`: único por `(provider, event_id)`.
- `products`: `cfop`, `icms_situation`, `pis_situation`, `cofins_situation`, `service_code`, `service_city_code` e `tax_unit`, todos opcionais.

Os nomes de índice e FK compostos são explícitos (até 64 caracteres).

## Decisões arquiteturais

- **O documento fiscal não é venda**: tem itens, destinatário e totais próprios e um vínculo opcional com a origem. Não publica eventos de efeito comercial; Financeiro, Estoque, Caixa e Vendas não reagem a ele.
- **Origens apenas lidas**: `FiscalOrigins` lê origens só com o módulo habilitado e da empresa ativa. As fichas recebem `fiscalDocuments` (`FiscalDirectory`), nulo sem o módulo.
- **Contas na Spedy**: uma conta de plataforma, com a chave da titular, e uma chave por empresa emissora. O webhook é de conta: o documento é localizado pela referência ou pelo `integrationId`, e o CNPJ do emitente é conferido.
- **Transmissão idempotente**:
    - só quem tira o documento de "Aguardando" transmite;
    - o `integrationId` é gravado na criação e enviado em toda transmissão;
    - falha transitória (sem resposta, 429, 5xx) vira "Falha de comunicação", consultada antes de qualquer reenvio;
    - 400 vira rejeição corrigível.
- **Confirmação por consulta**: o webhook nunca aplica o conteúdo recebido; a situação vem de nova consulta ao provedor.
- **Arquivos**: disco privado `fiscal`, segregado por empresa, com SHA-256. Um arquivo divergente é obtido de novo no provedor. Os downloads são auditados e não há URL pública.
- **Segurança das credenciais**: nada vai às telas nem aos logs. Erros de certificado não registram o corpo da resposta, e o certificado e a senha nunca são persistidos.
- **Sem microsserviços**: nenhuma dependência do VetorOS em execução.

## Testes realizados

- `tests/Feature/FiscalDocumentTest.php` (34 testes):
    - independência comercial, ativação e permissões individuais;
    - isolamento por empresa (404);
    - rascunho sem transmissão e idempotente;
    - bloqueios de configuração e de produção;
    - NFS-e autorizada com payload conferido;
    - XML protegido e refeito quando adulterado; download exige permissão;
    - rejeição corrigida e reenviada com o mesmo `integrationId`, sem segunda nota;
    - timeout e 503 com consulta antes do reenvio; 400 tratado como rejeição;
    - processamento concluído por consulta e regressão de estado ignorada;
    - conciliação agendada;
    - cancelamento (justificativa, recusa e homologação);
    - NF-e bloqueada sem NCM e depois emitida com CSOSN;
    - webhook (assinatura inválida, horário expirado, deduplicação, CNPJ divergente);
    - Vendas: NF-e sem alterar venda, estoque e financeiro, e unicidade por origem; exige o módulo Vendas;
    - PDV: NFC-e sem destinatário e sem movimento financeiro;
    - Financeiro: NFS-e sem baixa;
    - desativação preserva os documentos; documentos transmitidos não podem ser excluídos;
    - configurações sem credenciais e com reconfirmação;
    - cadastro da emissora com chave criptografada; certificado direto ao provedor, sem gravar arquivo nem senha;
    - RootAdmin com chave somente escrita e liberação de produção;
    - renderização das 7 telas e CSV.
- `tests/Feature/FiscalDemoSeederTest.php`: os quatro cenários, sem duplicar ao repetir.
- `tests/Feature/PaymentChargeTest.php`: registro do webhook da Efí com `x-skip-mtls-checking`.
- **Regressão completa**: 537 aprovados e 2 ignorados (4340 asserções).
- **Análise e build**: PHPStan com 0 erros no projeto inteiro; Pint; `tsc` sem erros; `vp check` com 0 erros (5 avisos anteriores, fora deste módulo); `npm run build` concluído; migrate e seed no MariaDB local.
- **Formatação**: `vp check --fix` reformatou 39 arquivos de etapas anteriores (documentos Markdown, `compose.yaml` e telas de Clientes e Financeiro). As mudanças são só de formatação, necessárias para que `npm run check` passe, e não alteram comportamento.

## Limitações

- Frete e outras despesas da venda não entram automaticamente no documento; a tela avisa.
- Não há emissão automática no PDV: a NFC-e é preparada a partir do atendimento finalizado.
- O compartilhamento se limita à cópia da chave e ao download autenticado. Não há envio por e-mail nem link público.
- Tributos calculados: só os CSTs sem destaque (herança do VetorOS). Não há motor tributário (fora do escopo, seção 21).
- A conciliação depende do agendador (`schedule:run`) e de um worker de fila em execução.

## Pendências

- Homologação real com uma empresa autorizada (ver as dependências acima).
- Validar na homologação os pontos não confirmados da documentação da Spedy.
- Avaliar, conforme a demanda: CC-e, inutilização, contingência offline da NFC-e, notas recebidas, IBS/CBS e envio de e-mail.

## Estado dos repositórios Git

- **`.git` (oficial)**:
    - remoto `origin` = `git@github-vetorflex:brasilgb/vetorflex.git`;
    - `main` = `origin/main` = `7ef80d4`, sem alterações;
    - ramos locais preparados, nunca publicados:
        - `local-history` = `9bd0657`: histórico completo do `.git-local`, importado com `fetch`;
        - `omnichannel-consolidation` = `5386463`: merge de `origin/main` com `local-history`, com a árvore de `local-history`.
    - Equivalência verificada:
        - árvore idêntica a `local-history`;
        - em relação a `origin/main`: 467 arquivos, removendo só `correio.md`, `pnpm-lock.yaml` e `yarn.lock`;
        - histórico preservado, sem reescrita.
- **`.git-local`**: `main` = `9bd0657` (OMNICHANNEL-01 a 09). OMNICHANNEL-10 pendente de commit.
- **Gerenciador de pacotes**: npm (`package-lock.json` e scripts do Composer). Proposta: manter só `package-lock.json` e deixar de versionar `pnpm-lock.yaml` e `yarn.lock`.
- **Arquivos sensíveis**: nenhum segredo versionado nos dois históricos (`.env.example` só com valores de exemplo). `correio.md` deve deixar de ser versionado.
- **Backups** em `../vetorflex-backups/2026-11-27-omnichannel-10/` (diretório 700; arquivos 600, exceto `SHA256SUMS`, que é 644):
    - `git-oficial.bundle` e `git-local.bundle`, ambos verificados;
    - `nao-versionados.tar.gz`;
    - estado e diff do diretório do `.git`;
    - `SHA256SUMS`.

**Comandos de consolidação (aguardam autorização expressa; nada foi executado além da preparação local):**

```bash
# 1. Na próxima etapa: consolidar a OMNICHANNEL-10 no .git-local (convenção atual)
git --git-dir=.git-local --work-tree=. add -A -- . ':!correio.md' ':!pnpm-lock.yaml' ':!yarn.lock'
git --git-dir=.git-local --work-tree=. commit -m "feat: add fiscal documents module with spedy integration"

# 2. Atualizar o ramo de consolidação no repositório oficial (sem reescrever o publicado)
git --git-dir=.git fetch ./.git-local main:local-history
SHA=$(git --git-dir=.git commit-tree 'local-history^{tree}' -p origin/main -p local-history -m "chore: consolidate omnichannel stages 01-10 from local history")
git --git-dir=.git branch -f omnichannel-consolidation "$SHA"
git --git-dir=.git diff --stat local-history omnichannel-consolidation   # deve ser vazio

# 3. Publicação (somente com autorização do proprietário): ramo novo + pull request, sem --force
git --git-dir=.git push origin omnichannel-consolidation

# 4. Depois do merge: arquivar (não apagar) o .git-local
git --git-dir=.git-local bundle create ../vetorflex-backups/git-local-final.bundle --all
```

## Reaproveitamento do VetorOS

**Local**: `gateway/vetoros`. Foi usado só para leitura. `git -C ../vetoros status` está limpo, sem nenhuma alteração (último commit `b39229f0`). **O VetorOS não foi modificado.** Nenhum `.env`, credencial, certificado, token, dado fiscal ou financeiro foi copiado. Não há dependência de banco, endpoint, credencial ou arquivo entre os sistemas.

**Arquivos analisados**:

- `app/Services/Fiscal/Spedy/`: `SpedyClient`, `SpedyCompanyService`, `SpedyException`, `SpedyInvoiceState`, `SpedyPayloadBuilder`, `SpedyPlatformConfig`;
- `app/Services/Fiscal/NativeFiscalService.php`;
- `app/Http/Controllers/Integration/SpedyWebhookController.php`;
- `app/Jobs/StoreFiscalDocumentFiles.php`;
- `app/Console/Commands/SyncSpedyFiscalDocuments.php`;
- `app/Models/App/FiscalDocument.php` e `FiscalSetting.php`;
- `config/services.php`, `config/filesystems.php`, `routes/api.php`, `routes/console.php`;
- `app/Services/Payments/Efi/EfiPixClient.php` e `EfiChargesClient.php`, `app/Http/Controllers/EfiWebhookController.php`.

A documentação oficial (docs.spedy.com.br, incluindo a OpenAPI parcial) foi lida para conferir URLs, autenticação, estados, idempotência, webhooks e limites.

**Componentes reaproveitados**: a lógica, adaptada aos padrões do VetorFlex.

- **Cliente HTTP**:
    - URLs sandbox e produção, `X-Api-Key`;
    - caminhos por modelo, `integrationId` e busca de CNPJ paginada;
    - envio multipart do certificado só por HTTPS e sem redirecionamento;
    - retentativa em 429;
    - mensagens seguras, sem o corpo de erros de certificado.
- **Mapa de estados e ordem de precedência**, para ignorar regressões, e a montagem da mensagem de rejeição "[código] mensagem".
- **Payload**:
    - limites de campo (80, 60, 120, 100, 10 e 150 caracteres);
    - mapa de regimes, `settings` por modelo (simulação para NFS-e em homologação) e `issueType`;
    - CFOP interestadual (5 → 6) e trio tributável espelhado;
    - CSTs suportados, mapa de pagamentos, ISS de 0 a 5% e local IBGE.
- **Cadastro da emissora**: chave gravada antes de qualquer outra chamada, bloqueio de CNPJ duplicado e tratamento de vínculo de outro ambiente.
- **Certificado**: aceito só com validade vigente informada pelo provedor.
- **Webhook**: Standard Webhooks (HMAC, tolerância de 5 minutos, `hash_equals`), deduplicação por `webhook-id` e conferência de CNPJ.
- **Fluxo de emissão**:
    - reserva sob trava;
    - bloqueio de reenvio de documento em processamento ou autorizado;
    - rejeitado ou falha reenviado com o mesmo `integrationId`;
    - resumo gravado antes do envio;
    - erro transitório × definitivo;
    - localização por `integrationId`;
    - conciliação (2 e 30 minutos).
- **Arquivos**: disco privado, caminho por empresa, tipo e mês, SHA-256 e retentativas [60, 300, 900, 3600].
- **Efí**: `x-skip-mtls-checking: true` no registro do webhook.

**Componentes adaptados**:

- a configuração da plataforma vem do RootAdmin, criptografada, em vez de `.env`/`config/services.php`;
- o banco por tenant virou multiempresa com `company_id` e FK composta;
- a emissão a partir da ordem de serviço virou um documento independente, com origens opcionais;
- o mapeamento de estados foi ampliado: "Aguardando", "Cancelamento solicitado" e "Falha de comunicação";
- `failed` passou a "Falha de comunicação" com reenvio seguro;
- validações devolvidas como lista de pendências da tela;
- valores calculados com `Brick\Math`.

**Funcionalidades adicionais identificadas no VetorOS ou na Spedy** (não implementadas):

- API de pedidos (`/orders`, com `autoIssueMode`);
- CC-e e inutilização;
- notas recebidas e manifestação;
- reenvio de e-mail;
- IBS/CBS;
- contingência offline da NFC-e;
- `NativeFiscalService`, que emite diretamente na SEFAZ sem provedor;
- cobranças da Efí (boleto, cartão e assinaturas), usadas só no faturamento SaaS do VetorOS e fora do escopo (seção 21).

**Diferenças arquiteturais**:

- monólito multiempresa com módulos comercialmente independentes, em vez de sistema por tenant centrado em ordens de serviço;
- contrato de provedor com capacidades declaradas;
- permissões por módulo e por usuário;
- documento fiscal sem efeito comercial.

**Ainda depende de desenvolvimento**: os itens da lista acima e a homologação real.

**Justificativa das reimplementações**: o modelo de dados e os serviços do VetorOS dependem do banco por tenant, da ordem de serviço e de configuração em `.env`. Copiá-los acoplaria o VetorFlex a outro domínio e quebraria a independência entre os módulos. Por isso a lógica comprovada em produção foi portada, e a estrutura foi reescrita nos padrões do VetorFlex.

A OMNICHANNEL-11 não foi iniciada.
