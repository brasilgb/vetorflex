# Arquitetura Omnichannel

A aplicação é um monólito modular Laravel. Os domínios em `app/Modules` delimitam responsabilidades sem carregar módulos dinamicamente.

- `Tenancy`: empresas e associação de usuários.
- `ModuleManager`: catálogo e autorizações de módulos por empresa.
- `Core`: auditoria, numeração por empresa, CSV, documentos comerciais em PDF e condições de pagamento (`Core\Commerce`).
- `Customers` e `Parties`: clientes e domínio compartilhado de pessoas.
- `Products`: catálogo e unidades comercializáveis (`PRODUCTS.md`).
- `Inventory`: único ponto de escrita de saldo (total e por depósito) e custo médio (`INVENTORY-CORE.md`, `INVENTORY-COSTING.md`). É também o módulo comercial Estoque (`inventory`, `STOCK.md`): depósitos, transferências, inventários, alertas e relatórios, com controle ativado por Estoque ou Produtos (`StockModule`).
- `Purchases`: fornecedores, pedidos, recebimentos, devoluções e indicadores (`PURCHASES.md`, `SUPPLIERS.md`).
- `Sales`: orçamentos, vendas, atendimentos, devoluções e indicadores (`SALES.md`). Integra-se aos demais por contratos públicos e eventos após o commit.
- `Finance`: contas a pagar e a receber, baixas, livro de movimentações, transferências, recorrências, fluxo de caixa, conciliação e relatórios (`FINANCE.md`). Independente: consome `SaleReceivableSource` e `PurchaseObligationSource` por ouvintes que só agem com o módulo habilitado.
- `Pos`: frente de caixa com terminais, sessões, pagamentos combinados, sangrias, suprimentos, devoluções e comprovantes não fiscais (`POS.md`). Independente: registra a venda pelo serviço comercial compartilhado de Vendas (`QuickSaleService` com `SaleChannel`), sem tabela de venda própria. Publica `PosSaleCompleted`, `PosRefundIssued` e `PosCashMovementRecorded`, consumidos opcionalmente pelo Financeiro.
- `Payments`: cobranças Pix pela conta da empresa no provedor (`PAYMENTS.md`). Contrato `PaymentProvider` com capacidades declaradas (Efí implementada); webhooks gravados e processados por fila, sempre confirmados por consulta ao provedor; publica `PaymentReceived` e `PaymentRefundConfirmed`, consumidos opcionalmente pelo Financeiro. O PDV usa o serviço para o Pix dinâmico confirmado.
- `Fiscal`: Documentos Fiscais (`FISCAL.md`). Contrato `FiscalProvider` com capacidades declaradas (Spedy implementada, adaptada do VetorOS); emissão assíncrona com identificador de integração estável, webhooks assinados confirmados por consulta, conciliação periódica e XML/PDF em disco privado com SHA-256. Não publica eventos de efeito comercial: o documento fiscal não é venda nem movimento financeiro. Lê origens de Vendas, PDV e Financeiro só com o módulo correspondente habilitado (`FiscalOrigins`), e as fichas desses módulos mostram os documentos por `FiscalDirectory`.
- `Commercial`: camada de plataforma (não contratável) com o cardápio a partir do catálogo, o cadastro público com verificação de e-mail, o provisionamento único de empresas (`CompanyProvisioning`), o período de teste (`TrialService`, aplicado em `ResolveTenant`), o painel do assinante e os contatos comerciais (`COMMERCIAL.md`). Lê indicadores dos módulos somente por consultas de leitura por `company_id`.
- RootAdmin: painel da plataforma em `app/Http/Controllers/RootAdmin`, protegido pelo middleware `root.admin`, sem domínio próprio. Administradores são cadastrados por `rootadmin:create` (`OMNICHANNEL-06.md`).
- `Identity`, `Billing` e `Integrations`: limites reservados para evolução, sem diretórios vazios artificiais.

Regra de negócio compartilhada e autorização comercial são separadas: os serviços de domínio (por exemplo, `QuickSaleService`, `SaleItems`, `SaleReturnService`, `SaleCustomers`) não verificam a contratação de módulos; quem verifica são as rotas (`module.permission:<módulo>,<permissão>`). Um canal como o PDV usa esses serviços sem conceder as interfaces do módulo de origem. A origem da venda (`SaleChannel`) é sempre definida pelo servidor.

Notificações externas (webhooks) nunca definem o contexto empresarial: a conta é identificada por um token secreto próprio (Pagamentos) ou pela assinatura da conta da plataforma, com o documento localizado pela referência do provedor e o CNPJ conferido (Documentos Fiscais). O conteúdo é gravado antes de processar, e o fato é confirmado por consulta ao provedor.

Integrações entre módulos seguem um padrão único: o módulo de origem publica eventos após o commit e expõe contratos de leitura; o consumidor grava apenas nas próprias tabelas, com chaves idempotentes, e falhas dele não interrompem a origem.

O banco é compartilhado. Toda entidade empresarial futura deve ter `company_id`, índices que comecem por ele quando aplicável, e validação do contexto de empresa antes de consultar ou alterar dados.

Datas são persistidas em UTC pelo Laravel e a empresa mantém seu timezone de apresentação. Moeda padrão: BRL.
