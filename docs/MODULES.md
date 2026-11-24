# Módulos

O catálogo (`platform_modules`) é distinto da autorização por empresa (`company_modules`). Um módulo em código não é automaticamente comercial, contratado nem permitido a um usuário.

Os módulos iniciais são: `customers`, `products`, `purchases` (depende de `products`), `sales` (depende de `customers` e `products`) e `finance` (independente). Todos são semeados como em desenvolvimento e indisponíveis comercialmente.

`ModuleActivationService` usa transação para ativar, reativar e desativar. Ele exige dependências ativas, impede a desativação de módulos com dependentes ativos, preserva os vínculos e cria auditoria. Módulos futuros devem fornecer dependências acíclicas no catálogo.

Movimentações de estoque serão um domínio interno único quando Produtos, Compras e Vendas forem implementados; nenhum módulo manterá saldo duplicado.
