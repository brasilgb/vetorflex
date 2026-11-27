# Documentos Fiscais (`fiscal`)

Central de NFS-e, NF-e e NFC-e da empresa, emitidas por um provedor fiscal liberado na plataforma. O módulo é independente: funciona sem Clientes, Produtos, Vendas, PDV e Financeiro e integra-se a eles somente quando estão contratados.

**O documento fiscal não é uma venda.** Emitir, rejeitar ou cancelar um documento não cria nem altera venda, recebimento, baixa, título, estoque ou caixa.

## Provedor

- Contrato `App\Modules\Fiscal\Providers\FiscalProvider`, com capacidades declaradas (`FiscalCapabilities`). Os módulos comerciais nunca dependem de um provedor específico.
- `FiscalProviderRegistry` reúne os provedores implementados. A disponibilidade e os tipos liberados (`fiscal_providers`) são decididos pelo RootAdmin, e o tipo também precisa estar implementado pelo provedor.
- Implementado: **Spedy** (API v1), adaptado da integração em produção do VetorOS (ver `OMNICHANNEL-10.md`).
    - Declara NFS-e, NF-e e NFC-e, consulta, cancelamento, XML, PDF, webhooks, cadastro do emitente e envio de certificado.
    - Não declara contingência nem carta de correção. Uma nota em contingência no provedor aparece como "Processando" até a autorização.
    - A cobertura de cada município e UF é da Spedy. Quando não há cobertura, o documento volta como rejeição explícita; nada é presumido.

### Conta da plataforma e da empresa

- **Plataforma**: a chave da empresa titular da conta Spedy, informada no RootAdmin.
    - É somente escrita, fica criptografada e nunca é exibida.
    - Sandbox e produção são contas separadas: trocar de ambiente exige a chave do novo ambiente.
- **Empresa**: o cadastro como emissora (`POST /companies`) devolve a chave da emissora uma única vez.
    - Ela é gravada criptografada antes de qualquer outra chamada.
    - Se o CNPJ já existir na conta, o cadastro é bloqueado com orientação, porque a chave não pode ser recuperada.
- **Certificado A1**: enviado direto ao provedor (multipart). Só o titular e a validade ficam gravados; o arquivo e a senha nunca são persistidos nem registrados em log.
- **CSC da NFC-e**: criptografado e nunca devolvido às telas.

## Configuração fiscal (por empresa)

- **Emitente**: razão social (até 80), CNPJ, inscrições, endereço, código IBGE, regime tributário (Simples, excesso de sublimite, MEI, Regime Normal).
- **Emissão**: ambiente (homologação ou produção), tipos habilitados e série por tipo.
- **Padrões da NFS-e**: item da LC 116, alíquota de ISS (0–5%), tipo de tributação e padrão de emissão (NFS-e Nacional/ADN ou do município).
- **Padrões de mercadorias**: unidade, unidade tributável, origem, CFOP interno, CST/CSOSN do ICMS, CST de PIS e COFINS.
- **Confirmação tributária**: um responsável confirma os dados, preferencialmente o contador. Alterar regime, padrões ou município desfaz a confirmação.

### Pendências que bloqueiam a emissão (`FiscalSettingsService::blockers`)

- Provedor indisponível ou sem chave na plataforma.
- Tipo não liberado na plataforma.
- Tipo não habilitado na empresa.
- Produção com a plataforma em sandbox, ou produção não liberada pelo RootAdmin.
- Emitente não cadastrado no provedor.
- Certificado ausente ou vencido.
- Dados tributários não confirmados.
- NFS-e sem tipo de tributação.
- NFC-e sem ID e CSC.

Nenhum código fiscal é preenchido por aproximação.

- O que falta aparece como pendência em linguagem de negócio.
- As limitações herdadas do VetorOS valem aqui:
    - CST de ICMS com destaque (00, 10, 20…) não é suportado no Regime Normal; apenas 40, 41, 50 e 60;
    - PIS e COFINS com alíquota (CST 01–03) não são suportados.

## Ciclo do documento

1. **Rascunho**: avulso ou a partir de uma origem. Salvar nunca transmite. A criação é idempotente (`idempotency_key`).
2. **Pré-visualização**: lista as pendências de configuração e os dados que o provedor recusaria, sem transmitir.
3. **Emissão** (`issue`): valida, muda para "Aguardando processamento" e coloca o job `TransmitFiscalDocument` na fila.
4. **Transmissão**: só quem tira o documento de "Aguardando" transmite. O `integration_id` (UUID gravado na criação) vai em todo envio.
    - Na Spedy, um reenvio atualiza a mesma nota e nunca cria outra.
    - O resumo da solicitação é gravado sem dados pessoais do destinatário.
5. **Resultado**:
    - autorizado (número, série, chave, protocolo);
    - rejeitado ou denegado (motivo "[código] mensagem");
    - falha de comunicação (sem resposta, 429 ou 5xx);
    - recusa do provedor (400), tratada como rejeição corrigível.
6. **Reenvio**: rejeitado (após correção) ou falha de comunicação.
    - Antes de reenviar após falha, o provedor é consultado pelo `integration_id`.
    - Se a nota já chegou, a situação é aplicada e nada é reenviado.
7. **Cancelamento**: somente de autorizados, com justificativa de 15 a 255 caracteres.
    - Recusado: volta a "Autorizado", com histórico.
    - Sem resposta: fica "Cancelamento solicitado" até a consulta.
8. **Arquivos**: autorizados e cancelados têm XML e PDF gravados pelo job `StoreFiscalDocumentFiles`, com 5 tentativas espaçadas.

### Estados

Rascunho, Aguardando processamento, Processando, Autorizado, Rejeitado, Denegado, Cancelamento solicitado, Cancelado e Falha de comunicação.

- A situação só avança, pela ordem em `FiscalStatus::rank`. Retornos antigos ou fora de ordem são ignorados, exceto Rejeitado → Processando (reenvio).
- Somente "Autorizado" é apresentado como documento válido.
- Homologação é sempre sinalizada como "sem validade fiscal".
- Documentos transmitidos não podem ser excluídos (trava no modelo). Só um rascunho nunca transmitido pode ser excluído.
- O histórico (`fiscal_document_events`) é imutável.

## Webhooks e conciliação

- **Rota**: `POST /webhooks/fiscal/{provider}`, sem sessão e sem CSRF, com limite de 240 por minuto.
- **Autenticação (Standard Webhooks)**:
    - assinatura HMAC-SHA256 de `{webhook-id}.{webhook-timestamp}.{corpo}` com o segredo `whsec_`;
    - tolerância de 5 minutos;
    - comparação em tempo constante;
    - assinatura inválida responde 401.
- **Gravação**:
    - cada evento é gravado uma vez por `webhook-id`;
    - um CNPJ do emitente diferente do cadastro da empresa faz o evento ser ignorado.
- **Processamento** (`ProcessFiscalWebhook`): a situação aplicada vem sempre de nova consulta ao provedor com a chave da empresa dona do documento. O conteúdo da notificação nunca é aplicado diretamente.
- **Registro**: o webhook (`invoice.status_changed`) e o segredo são registrados pelo RootAdmin, que guarda o segredo criptografado.
- **Conciliação**: `fiscal:reconcile`, a cada 5 minutos.
    - Consulta documentos pendentes sem atualização há mais de 2 minutos.
    - Recoloca na fila os que ficaram "Aguardando" por mais de 10 minutos.
    - Marca como falha de comunicação os envios não localizados no provedor após 30 minutos (`fiscal.unconfirmed_after_minutes`).
    - Quem tem `fiscal.reprocess` também pode consultar pendentes e reprocessar notificações com falha pelo painel.

## Armazenamento e segurança

- **Disco privado** `fiscal` (`storage/app/private/fiscal`, ignorado pelo Git).
    - Caminho: `{empresa}/{tipo}/{ano}/{mês}/{documento}-{situação}.{xml|pdf}`.
    - Cada arquivo tem o SHA-256 gravado.
- **Entrega**: somente pela rota `fiscal/documents/{id}/files/{xml|pdf}`.
    - Exige o módulo, `fiscal.download` e a empresa do documento.
    - Resposta com `Cache-Control: private, no-store`.
    - O download fica auditado.
- **Integridade**: arquivo ausente ou com hash divergente é obtido de novo no provedor antes da entrega. Não há URL pública.
- **Chaves**: credenciais, CSC e certificado nunca vão às telas nem aos logs. Erros de certificado não registram o corpo da resposta.

## Integrações opcionais (somente com o módulo contratado)

- **Vendas**: "Preparar documento fiscal" na ficha da venda confirmada.
    - Sugere NF-e, ou NFS-e quando só há serviços.
    - Copia itens, rateio de desconto (`net_total`), destinatário e os dados fiscais já cadastrados no produto.
    - Frete e outras despesas não entram automaticamente; a tela avisa.
- **PDV**: NFC-e de um atendimento finalizado, distinta do comprovante não fiscal.
    - Não há emissão automática no caixa.
    - O consumidor só é identificado com CPF ou CNPJ válido.
- **Financeiro**: NFS-e a partir de uma conta a receber, sem baixa.
- **Outras origens**: Produtos, Clientes e Pessoas fornecem só dados de leitura.
- **Unicidade**: um documento ativo por origem e tipo. O rejeitado deve ser corrigido e reenviado; cancelado e denegado liberam um novo.

## Dados fiscais dos itens

- A página "Dados fiscais dos itens" edita, no catálogo compartilhado, os campos NCM, CEST, origem, CFOP, CST/CSOSN, PIS, COFINS, unidade tributável, item da LC 116 e código municipal.
- Só o formato é validado. O filtro "incompletos" ajuda a revisão.

## Permissões

| Permissão                | Uso                                                  |
| ------------------------ | ---------------------------------------------------- |
| `fiscal.view`            | Painel, central e itens                              |
| `fiscal.create_draft`    | Criar rascunhos                                      |
| `fiscal.edit`            | Editar ou excluir rascunhos e dados fiscais de itens |
| `fiscal.issue`           | Transmitir                                           |
| `fiscal.cancel`          | Cancelar                                             |
| `fiscal.download`        | Baixar XML e PDF                                     |
| `fiscal.export`          | CSV da central e do relatório                        |
| `fiscal.manage_settings` | Configuração, cadastro no provedor e certificado     |
| `fiscal.reprocess`       | Consultar pendentes e reprocessar notificações       |
| `fiscal.view_reports`    | Relatórios                                           |

## Demonstração

- `FiscalDemoSeeder` roda só em local e testing, com a Spedy simulada (`Database\Seeders\Support\SimulatedSpedy`). Nenhuma requisição sai do processo.
- Cenários:
    - somente fiscal: NFS-e autorizada, rejeitada, cancelada e um rascunho;
    - fiscal + Vendas (NF-e);
    - fiscal + PDV (NFC-e);
    - fiscal + Financeiro (NFS-e sem baixa).
- CNPJs, chaves e números são fictícios. Repetir o seed não duplica.

## Dependências de homologação real

- Conta Spedy (sandbox: "Plano Desenvolvedor"), com a chave da empresa titular de cada ambiente.
- Webhook registrado pelo RootAdmin.
- Empresa autorizada, com certificado A1 real, inscrições e credenciamento na SEFAZ (NF-e e NFC-e, com CSC) e na prefeitura (NFS-e), conforme a cobertura da Spedy.
- Códigos e alíquotas confirmados pelo contador.
- Liberação de produção pelo RootAdmin, após a homologação acompanhada.

Nenhuma emissão real foi feita nesta etapa.
