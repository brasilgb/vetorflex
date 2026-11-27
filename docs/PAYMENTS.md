# Módulo Pagamentos (`payments`) 1.0.0

Cobranças Pix emitidas pela conta da própria empresa no provedor, com link e QR Code, confirmação por consulta ao provedor (webhook e conciliação periódica), cancelamento, devolução, histórico, relatórios e CSV. Independente: não exige Financeiro, Vendas nem PDV.

## Independência e isolamento

- Sem dependências comerciais (`dependencies: []`). Integra-se opcionalmente a Clientes (pagador), Vendas, Financeiro e PDV.
- Pagadores são pessoas do domínio compartilhado (`PartyService`), sem cadastro paralelo.
- Toda tabela tem `company_id`, com chaves compostas `(company_id, id)`. A empresa vem do contexto do usuário, e no webhook somente do token secreto da conta; nada na requisição escolhe a empresa.
- Os pagamentos caem na conta da empresa no provedor. Credenciais e recebimentos das empresas não se misturam com a cobrança das assinaturas da plataforma (fora do escopo).

## Estrutura

`app/Modules/Payments`: `Data`, `Enums`, `Events`, `Jobs`, `Models`, `Providers`, `Services`, `Support`. Controladores em `app/Http/Controllers/Payments`, rotas em `routes/payments.php` (`/payments`) e `POST /webhooks/payments/{provider}/{token}[/pix]`, configuração em `config/payments.php`, telas em `resources/js/pages/payments`.

| Tabela | Conteúdo |
|---|---|
| `payment_providers` | Provedores implementados e a disponibilidade decidida pelo RootAdmin. |
| `payment_provider_accounts` | Conta da empresa por provedor e ambiente: credenciais e token do webhook criptografados (`encrypted`), chave Pix, formas, validade do Pix, conta financeira, situação e última falha (sem segredos). |
| `payment_charges` | Cobranças: valores `decimal(15,2)`, situação interna, situação do provedor, pagador, origem, txid, Pix copia e cola, QR Code, link, idempotência e `active_origin_key` (uma cobrança aberta por origem). |
| `payment_charge_events` | Histórico imutável da cobrança. |
| `payment_transactions` | Pagamentos confirmados pelo provedor, únicos por identificador (`endToEndId`). Bruto, taxa, líquido e devolvido. |
| `payment_refunds` | Devoluções solicitadas e o resultado informado pelo provedor. |
| `payment_webhook_events` | Notificações recebidas, gravadas antes do processamento (conteúdo idêntico uma vez por conta). |
| `pos_payments` (colunas novas) | `payment_charge_id` (único) e `is_provider_confirmed`. |

## Estados

Os estados internos são independentes dos estados do provedor: Rascunho, Pendente, Processando, Pago, Parcialmente pago, Vencido, Cancelado, Expirado, Estornado e Falha.

- **Pago / Parcialmente pago / Estornado**: calculados a partir dos pagamentos e devoluções confirmados.
- **Cancelado**: cancelamento no provedor ou remoção informada por ele.
- **Expirado**: validade do Pix vencida sem pagamento.
- **Vencido**: pendente depois do vencimento (conciliação).
- **Falha**: o provedor recusou a emissão. A origem é liberada e a falha fica registrada.
- **Pagamento tardio**: uma cobrança expirada ou cancelada que recebe pagamento ainda registra o valor recebido.

## Provedores

Contrato `PaymentProvider` com capacidades declaradas (`ProviderCapabilities`); as telas só oferecem o que estiver declarado. Nenhum dado de cartão é recebido ou armazenado.

**Efí (implementado, somente Pix)**, conforme a documentação oficial (dev.efipay.com.br), consultada em 27/11/2026:

- **Ambientes**: `https://pix.api.efipay.com.br` (produção) e `https://pix-h.api.efipay.com.br` (homologação).
- **Autenticação**: OAuth2 `client_credentials` em `POST /oauth/token`, com credenciais em HTTP Basic e o certificado da conta (P12 ou PEM) em todas as requisições (mTLS). O certificado é decifrado num arquivo temporário (0600) só durante a requisição. O token fica em cache até perto de expirar.
- **Cobrança imediata**: `PUT /v2/cob/:txid`. O txid tem 32 caracteres alfanuméricos e é gravado antes da emissão; repetir a emissão nunca cria outra cobrança (409 é tratado com consulta).
- **QR Code e link**: `GET /v2/loc/:id/qrcode` (`qrcode`, `imagemQrcode`, `linkVisualizacao`). O link de visualização só funciona em produção, segundo a Efí.
- **Consulta**: `GET /v2/cob/:txid` (`pix[]` com `endToEndId` e `devolucoes`).
- **Cancelamento**: `PATCH /v2/cob/:txid` com `REMOVIDA_PELO_USUARIO_RECEBEDOR`.
- **Devolução**: `PUT /v2/pix/:e2eId/devolucao/:id` (`EM_PROCESSAMENTO`, `DEVOLVIDO`, `NAO_REALIZADO`).
- **Webhook**: `PUT /v2/webhook/:chave` com `webhookUrl`. A URL leva `?ignorar=`, e a rota também aceita o sufixo `/pix`.
- **Teste de conexão**: autenticação e `GET /v2/cob` do último dia.
- **Taxas**: a consulta documentada não informa tarifa, então a taxa fica vazia; nunca é estimada.

Boleto e cartão (API de Cobranças da Efí) não foram implementados e não são declarados. O Sicredi não foi implementado: entra no `ProviderRegistry` somente quando houver integração real.

## Webhooks e processamento

1. `POST /webhooks/payments/efi/{token}`: a conta é identificada pelo hash do token (48 caracteres aleatórios). Token desconhecido dá 404. Com `PAYMENTS_WEBHOOK_ENFORCE_IPS` (ligado por padrão em produção), só aceita os IPs configurados (a Efí documenta `34.193.116.226`). A rota fica fora do CSRF, com limite de 120 por minuto, e responde rápido.
2. A notificação é gravada (`payment_webhook_events`); conteúdo idêntico é gravado uma só vez.
3. `ProcessPaymentWebhook` (fila; 5 tentativas com espera de 30 s, 2, 10 e 30 min) consulta no provedor cada cobrança citada **desta conta** e aplica os fatos. O conteúdo do webhook nunca é usado como prova de pagamento.
4. Idempotência e ordem: pagamentos são únicos pelo `endToEndId` e a situação é recalculada a partir dos fatos, por isso eventos repetidos ou fora de ordem não duplicam nem regridem a situação.
5. Falhas ficam registradas e podem ser reprocessadas por quem tem `payments.reprocess`.
6. A conciliação periódica (`payments:reconcile`, a cada 15 min, sem sobreposição) consulta as cobranças em aberto dos últimos 60 dias e marca as vencidas. Funciona mesmo sem webhook registrado.

**mTLS na Efí**: a Efí exige TLS mútuo no servidor que recebe o webhook (ela recusa o registro sem ele). Isso é configurado no servidor web, fora da aplicação: aceitar o certificado de cliente da Efí com a cadeia pública `https://certificados.efipay.com.br/webhooks/certificate-chain-prod.crt` (produção) ou `...-homolog.crt` (homologação), com TLS 1.2 ou superior. Sem isso, o registro do webhook falha e a confirmação depende da conciliação periódica.

## Integrações

- **Vendas**:
  - cobrança a partir de uma venda confirmada, pelo saldo devido (total menos o já recebido por cobranças);
  - uma cobrança aberta por venda;
  - a ficha da venda mostra as cobranças e o botão "Gerar cobrança do saldo", só com Pagamentos contratado e permissão.
  - Vendas funciona igual sem Pagamentos.
- **Financeiro** (`SyncPaymentOperations`, ouvinte do Financeiro):
  - cobrança a partir de conta a receber (saldo em aberto);
  - com pagamento confirmado, baixa os títulos da venda ou o título de origem, na conta financeira da conta do provedor, com chave `payment-transaction:{id}:title:{título}`, sem baixa duplicada;
  - na devolução confirmada, estorna essas baixas e baixa de novo o valor que restou;
  - sem conta financeira configurada, nada é baixado;
  - cobranças avulsas não criam títulos;
  - cobranças do PDV não passam por aqui (a baixa é da integração do PDV), para não duplicar receita.
- **PDV** (`PosPix`, `PosPixController`):
  - Pix dinâmico gerado no caixa, com terminal, sessão e operador nos metadados, um Pix aberto por carrinho e consulta ao provedor a cada 3 s enquanto aguarda;
  - a venda só aceita `payment_charge_id` se a cobrança for do PDV desta empresa, estiver **paga** segundo o provedor, tiver o mesmo valor e não tiver sido usada (coluna única);
  - o pagamento fica com `is_provider_confirmed`; gerar o QR Code não confirma nada;
  - expiração, falha e cancelamento liberam a linha; Pix pago depois de o atendimento ser abandonado aparece na conciliação para devolução ou conferência;
  - sem Pagamentos, o PDV segue com pagamentos manuais.

## Telas e permissões

- **Menu Pagamentos**: Visão geral, Cobranças, Nova cobrança, Conciliação, Relatórios e Configurações. O ambiente de homologação é indicado em destaque.
- **Visão geral**: recebido, pendente e vencido; emitidas e pagas; taxa de recebimento; distribuição por forma, provedor e origem; últimas cobranças; falhas; bruto, devolvido, taxas e líquido quando houver.
- **Filtros**: período, situação, provedor e origem.
- **Permissões**: `payments.view`, `payments.create`, `payments.cancel`, `payments.refund`, `payments.view_reports`, `payments.export`, `payments.manage_providers`, `payments.reconcile` e `payments.reprocess`. Operações sensíveis são auditadas (criação, cancelamento, devolução, configuração, teste, registro de webhook, conciliação, reprocessamento e exportações).
- **RootAdmin** (`/rootadmin/payments`):
  - disponibilidade de cada provedor;
  - indicadores operacionais agregados (somente contagens, sem valores das empresas);
  - integrações com falha;
  - últimos eventos técnicos, sem conteúdo nem credenciais.
  - A habilitação por empresa segue no catálogo de módulos.

## Demonstração

`PaymentsDemoSeeder` (local e testing, idempotente) usa a Efí **simulada** (`Database\Seeders\Support\SimulatedEfi`, com `Http::fake`); nenhuma requisição sai do processo. Empresas:

- "Estúdio Somente Pagamentos";
- "Loja Pagamentos + Vendas";
- "Consultoria Pagamentos + Financeiro";
- "Café Pagamentos + PDV".

Nesses ambientes, o seeder torna a Efí disponível.

## Limitações

- Somente Pix (Efí). Boleto, cartão, Sicredi e outros provedores não foram implementados.
- A integração real depende das credenciais e do certificado da empresa na Efí, de homologação no ambiente de teste da Efí e de mTLS no servidor para o webhook. Nenhuma chamada real foi feita nesta etapa.
- A tarifa do Pix não é informada pela consulta documentada; taxas e líquido ficam vazios.
- A segunda via é a impressão da página da cobrança (QR Code e copia e cola), não um PDF próprio.
- Devolução de cobrança de venda não altera a venda; o acerto comercial segue em Vendas.
