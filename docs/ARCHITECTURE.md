# Arquitetura Omnichannel

A aplicação é um monólito modular Laravel. Os domínios em `app/Modules` delimitam responsabilidades sem carregar módulos dinamicamente.

- `Tenancy`: empresas e associação de usuários.
- `ModuleManager`: catálogo e autorizações de módulos por empresa.
- `Core`: auditoria, numeração por empresa, CSV, documentos comerciais em PDF e condições de pagamento (`Core\Commerce`).
- `Customers` e `Parties`: clientes e domínio compartilhado de pessoas.
- `Products`: catálogo e unidades comercializáveis (`PRODUCTS.md`).
- `Inventory`: único ponto de escrita de saldo e custo médio (`INVENTORY-CORE.md`, `INVENTORY-COSTING.md`).
- `Purchases`: fornecedores, pedidos, recebimentos, devoluções e indicadores (`PURCHASES.md`, `SUPPLIERS.md`).
- `Sales`: orçamentos, vendas, atendimentos, devoluções e indicadores (`SALES.md`). Integra-se aos demais por contratos públicos e eventos após o commit.
- `Identity`, `RootAdmin`, `Finance`, `Billing` e `Integrations`: limites reservados para evolução, sem diretórios vazios artificiais.

O banco é compartilhado. Toda entidade empresarial futura deve ter `company_id`, índices que comecem por ele quando aplicável, e validação do contexto de empresa antes de consultar ou alterar dados.

Datas são persistidas em UTC pelo Laravel e a empresa mantém seu timezone de apresentação. Moeda padrão: BRL.
