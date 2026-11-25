# OMNICHANNEL-03 — Resultado

Data: 25/11/2026. Local: `gateway/vetorflex/` (antes `gateway/vetornew/`, caminho ainda citado no correio).

## Comparação do correio.md

`correio.md` mudou: SHA-256 `5edc3ba9…` (25/11 15:52), contra `203f948b…` da execução anterior, que terminava na seção 17. As seções 1 a 16 são idênticas byte a byte; o restante da 17 e as seções 18 a 25 são novos e foram executados.

## Consolidação da OMNICHANNEL-02

Já estava no commit local `9dd241e`, com o seeder de demonstração restrito a `local` e `testing` e a proteção contra CSV injection. Nesta etapa foi corrigido o erro 500 de `/rootadmin/companies`, que impedia o critério de aceite 1.

## Decisões arquiteturais

- `product_variants` é a unidade comercializável única; produto simples e serviço têm exatamente uma.
- `InventoryService` é o único ponto de escrita de saldo, com bloqueio da unidade comercializável, movimentações imutáveis e idempotência por empresa.
- Permissões iguais à seção 19: imagens usam `products.update` e estorno usa `products.adjust_inventory`. `manage_cost` exige `view_cost`.
- Importação em duas etapas, com nova avaliação na confirmação; sobrescrever exige marcar linha a linha, e a linha é recusada se o produto correspondente mudou.
- Disponibilidade comercial e ativação por empresa são independentes; o seed não desfaz mais a decisão do RootAdmin.

## Funcionalidades implementadas

- Catálogo: produtos simples, com variações e serviços; categorias hierárquicas, marcas, unidades, SKU, GTIN, preços com histórico, imagens.
- Estoque: inicial, entrada, saída, ajuste, estorno, mínimo e histórico.
- Interface: lista com filtros (inclusive estoque), cadastro rápido e completo, ficha em abas conforme o tipo, inativação e reativação.
- Indicadores na lista, com valor estimado do estoque protegido por `view_inventory` e `view_cost`.
- Importação CSV (modelo, prévia, validação, duplicidades, confirmação, relatório, limites configuráveis) e exportação CSV.
- RootAdmin: ativação por empresa, disponibilidade comercial e menu próprio.
- Seed: 8 itens, 11 unidades comercializáveis e 8 movimentações fictícias, idempotente.
- Contratos internos para Compras e Vendas (`ProductCatalog` e `InventoryService`).

Preparado, **não implementado**: importação de variações, estoque negativo configurável, custo médio, reserva e integrações com canais (apenas documentados).

## Migrations

`2026_11_25_141844` (catálogo), `141845` (variações), `141846` (estoque) e `160000` (importações). Aplicadas no MySQL local `new`, sem remover dados.

## Arquivos modificados

- Backend: `app/Modules/{Products,Inventory,Tenancy/Concerns}`, `app/Modules/ModuleManager/Models/PlatformModule.php`, `app/Http/Controllers/{Products,Inventory,RootAdmin}`, `app/Http/Requests/{Products,Inventory,RootAdmin}`, `app/Http/Middleware/{EnsureCompanyRecords,HandleInertiaRequests}.php`, `CompanyPolicy`, `bootstrap/app.php`, `config/products.php`, `routes/{products,web,console}.php`, factories e seeders.
- Front-end: `resources/js/pages/{products,root-admin}`, `resources/js/components` (menu, `products/` e componentes comuns), `hooks/use-tenant.ts`, `lib/{decimal,uuid}.ts`, `types/`.
- Testes: `Product{Management,Catalog,Variant,Image,Import,Export}Test`, `InventoryMovementTest`, `CompanyModuleTest`, `tests/Pest.php`.
- Documentação: `PRODUCTS.md`, `INVENTORY-CORE.md`, `OMNICHANNEL-03.md` (novos); `ARCHITECTURE.md`, `MODULES.md`, `DEVELOPMENT.md`, `OMNICHANNEL-02.md`.

Três testes tiveram a expectativa alterada: estorno deixou de ter permissão própria (seção 19), o filtro `low_stock` virou `stock`, e o seed passou de 2 para 8 itens. Outro foi corrigido por contar as unidades depois que a factory criava mais uma.

## Testes realizados

| Verificação | Resultado |
|---|---|
| `php artisan test --compact` (SQLite) | 212 aprovados, 2 ignorados, 0 falhas |
| Mesma suíte em MySQL (banco temporário) | 212 aprovados, 2 ignorados, 0 falhas |
| 20 saídas simultâneas de 1, saldo 10 (MySQL) | 10 aceitas, 10 recusadas, saldo 0 |
| 12 entradas simultâneas com a mesma chave | 1 movimentação |
| 12 estornos simultâneos do mesmo lançamento | 1 estorno, 11 recusados |
| Migrations do zero e seed repetido (MySQL) | Aprovados, sem duplicar |
| `npm run build`, Pint, lint dos arquivos novos | Aprovados |
| PHPStan | 26 apontamentos antigos (eram 27); nenhum em Products ou Inventory |
| `tsc` | 12 erros antigos; nenhum nos arquivos novos |
| Servidor local com a conta demo | 11 URLs do módulo com 200 e conteúdo conferido por SSR; RootAdmin negado (403) à conta demo |

O banco temporário foi criado e removido por esta execução.

## Pendências

1. **Navegador não testado** (sem Chrome no ambiente): diálogos, abas, upload, leitor de código e responsividade em celular exigem homologação manual. As telas do RootAdmin e a prévia da importação foram cobertas por testes de servidor, não renderizadas.
2. Não há tela para conceder permissões individuais a operadores.
3. Importação síncrona, até 500 registros; a limpeza de prévias depende do agendador ativo.
4. Imutabilidade das movimentações garantida no model, não no banco.
5. Demais pendências em `docs/OMNICHANNEL-02.md`.

## Estado do Git

- Histórico `.git-local`, branch `main`. OMNICHANNEL-03 consolidada em commit local, após autorização do responsável, sobre `9dd241e` (OMNICHANNEL-02).
- Não versionados e fora desta etapa: `correio.md`, `pnpm-lock.yaml`, `yarn.lock`.
- Sem push, deploy ou alteração em outros sistemas.

## Parecer técnico

Critérios de aceite 1 a 15 e 17 atendidos e verificados no servidor. No 16, o build foi aprovado, mas a responsividade não foi verificada. O núcleo de estoque está apto a receber o módulo Compras; recomenda-se a homologação manual no navegador antes de liberar a clientes.
