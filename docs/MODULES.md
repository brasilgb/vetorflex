# Módulos

O catálogo (`platform_modules`) é distinto da autorização por empresa (`company_modules`). Um módulo em código não é automaticamente comercial, contratado nem permitido a um usuário.

Os módulos iniciais são: `customers`, `products`, `purchases` (sem dependências comerciais desde a OMNICHANNEL-08; integra-se opcionalmente a `products`, `inventory` e `finance`), `sales` (sem dependências comerciais; integra-se opcionalmente a `customers`, `products`, `inventory`, `finance` e `pos`), `finance` (independente; integra-se opcionalmente a `sales`, `purchases` e `pos`) e `pos` (PDV — Frente de Caixa; independente; integra-se opcionalmente a `customers`, `products`, `inventory`, `sales` e `finance`) e `inventory` (Estoque; independente; integra-se opcionalmente a `products`, `purchases`, `sales`, `pos` e `finance`). `products`, `purchases`, `sales`, `finance`, `pos` e `inventory` são semeados como implementados (1.0.0) e `customers` como em desenvolvimento; todos começam indisponíveis comercialmente, e o RootAdmin decide a disponibilidade e a ativação por empresa.

`ModuleActivationService` usa transação para ativar, reativar e desativar. Ele exige dependências ativas, impede a desativação de módulos com dependentes ativos, preserva os vínculos e cria auditoria. Módulos futuros devem fornecer dependências acíclicas no catálogo.

Movimentações de estoque são um domínio interno único (`Inventory`), usado por Produtos, Compras, Vendas, PDV e Estoque, com saldo por depósito e reservas por documento; nenhum módulo mantém saldo duplicado. O controle de estoque fica ativo com Estoque ou Produtos (`StockModule`); sem nenhum deles, nenhuma movimentação é criada. Contratos em `STOCK.md`, `PRODUCTS.md`, `INVENTORY-CORE.md`, `INVENTORY-COSTING.md` e `PURCHASES.md`.

Compras funciona sem Clientes, Produtos e Financeiro: sem controle de estoque, registra documentos e custos sem saldo, e cadastra itens simplificados no pedido. As permissões de cada módulo vêm do catálogo e são concedidas por usuário em `/company/members` (ver `OMNICHANNEL-04.md`). Vendas funciona sem Clientes, Produtos e Financeiro (ver `SALES.md`).

Financeiro funciona sem nenhum módulo comercial (ver `FINANCE.md`). Contratado depois de Vendas ou Compras, não importa o histórico automaticamente: a importação assistida exibe prévia por período e não presume documentos pagos. Desativá-lo preserva os dados e não afeta Vendas e Compras.

O PDV funciona sem Clientes, Produtos, Estoque, Compras, Vendas e Financeiro (ver `POS.md`). Ele usa a mesma entidade de venda de Vendas, que mostra as vendas do caixa quando está habilitado, sem segunda venda. Com Produtos, valida e baixa estoque pelo `Inventory`; com Financeiro, as baixas e transferências seguem a configuração de contas do PDV. Nenhuma dessas integrações concede telas ou permissões dos módulos não contratados, e o PDV não é ativado automaticamente nas empresas existentes.

O Estoque funciona sem Produtos, Compras, Vendas, Financeiro e PDV (ver `STOCK.md`). Contratado depois, não reproduz movimentações históricas: o saldo inicial é informado por mercadoria ou por CSV, e a data de início do controle fica registrada. Desativá-lo preserva os dados.
