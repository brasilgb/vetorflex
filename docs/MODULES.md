# Módulos

O catálogo (`platform_modules`) é distinto da autorização por empresa (`company_modules`). Um módulo em código não é automaticamente comercial, contratado nem permitido a um usuário.

Os módulos iniciais são: `customers`, `products`, `purchases` (depende de `products`), `sales` (sem dependências comerciais; integra-se opcionalmente a `customers`, `products`, `finance` e `pos`), `finance` (independente; integra-se opcionalmente a `sales`, `purchases` e `pos`) e `pos` (PDV — Frente de Caixa; independente; integra-se opcionalmente a `customers`, `products`, `sales` e `finance`). `products`, `purchases`, `sales`, `finance` e `pos` são semeados como implementados (1.0.0) e `customers` como em desenvolvimento; todos começam indisponíveis comercialmente, e o RootAdmin decide a disponibilidade e a ativação por empresa.

`ModuleActivationService` usa transação para ativar, reativar e desativar. Ele exige dependências ativas, impede a desativação de módulos com dependentes ativos, preserva os vínculos e cria auditoria. Módulos futuros devem fornecer dependências acíclicas no catálogo.

Movimentações de estoque são um domínio interno único (`Inventory`), usado por Produtos, Compras e Vendas, com reservas por documento; nenhum módulo mantém saldo duplicado. Contratos em `PRODUCTS.md`, `INVENTORY-CORE.md`, `INVENTORY-COSTING.md` e `PURCHASES.md`.

Compras funciona sem Clientes e sem Financeiro (`optional_integrations: finance`). As permissões de cada módulo vêm do catálogo e são concedidas por usuário em `/company/members` (ver `OMNICHANNEL-04.md`). Vendas funciona sem Clientes, Produtos e Financeiro (ver `SALES.md`).

Financeiro funciona sem nenhum módulo comercial (ver `FINANCE.md`). Contratado depois de Vendas ou Compras, não importa o histórico automaticamente: a importação assistida exibe prévia por período e não presume documentos pagos. Desativá-lo preserva os dados e não afeta Vendas e Compras.

O PDV funciona sem Clientes, Produtos, Estoque, Compras, Vendas e Financeiro (ver `POS.md`). Ele usa a mesma entidade de venda de Vendas, que mostra as vendas do caixa quando está habilitado, sem segunda venda. Com Produtos, valida e baixa estoque pelo `Inventory`; com Financeiro, as baixas e transferências seguem a configuração de contas do PDV. Nenhuma dessas integrações concede telas ou permissões dos módulos não contratados, e o PDV não é ativado automaticamente nas empresas existentes.
