# Arquitetura Omnichannel

A aplicação é um monólito modular Laravel. Os domínios em `app/Modules` delimitam responsabilidades sem carregar módulos dinamicamente.

- `Tenancy`: empresas e associação de usuários.
- `ModuleManager`: catálogo e autorizações de módulos por empresa.
- `Core`: auditoria de operações relevantes.
- `Identity`, `RootAdmin`, `Customers`, `Products`, `Purchases`, `Sales`, `Finance`, `Billing`, `Integrations` e `Inventory`: limites reservados para evolução, sem diretórios vazios artificiais.

O banco é compartilhado. Toda entidade empresarial futura deve ter `company_id`, índices que comecem por ele quando aplicável, e validação do contexto de empresa antes de consultar ou alterar dados.

Datas são persistidas em UTC pelo Laravel e a empresa mantém seu timezone de apresentação. Moeda padrão: BRL.
