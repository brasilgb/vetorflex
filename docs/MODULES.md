# Módulos

O catálogo (`platform_modules`) é distinto da autorização por empresa (`company_modules`). Um módulo em código não é automaticamente comercial, contratado nem permitido a um usuário.

Os módulos iniciais são: `customers`, `products`, `purchases` (depende de `products`), `sales` (depende de `customers` e `products`) e `finance` (independente). `products` e `purchases` são semeados como implementados (1.0.0) e os demais como em desenvolvimento; todos começam indisponíveis comercialmente, e o RootAdmin decide a disponibilidade e a ativação por empresa.

`ModuleActivationService` usa transação para ativar, reativar e desativar. Ele exige dependências ativas, impede a desativação de módulos com dependentes ativos, preserva os vínculos e cria auditoria. Módulos futuros devem fornecer dependências acíclicas no catálogo.

Movimentações de estoque são um domínio interno único (`Inventory`), usado por Produtos, Compras e pelas futuras Vendas; nenhum módulo mantém saldo duplicado. Contratos em `PRODUCTS.md`, `INVENTORY-CORE.md`, `INVENTORY-COSTING.md` e `PURCHASES.md`.

Compras funciona sem Clientes e sem Financeiro (`optional_integrations: finance`). As permissões de cada módulo vêm do catálogo e são concedidas por usuário em `/company/members` (ver `OMNICHANNEL-04.md`).
