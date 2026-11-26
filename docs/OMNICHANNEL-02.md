# OMNICHANNEL-02

Entrega: painel empresarial com seletor de empresa, navegação dinâmica por módulo, permissões individuais no backend, módulo Clientes e domínio compartilhado de pessoas (`Party`).

## Ajustes de consolidação

- `CustomerDemoSeeder` (conta com senha conhecida) só é executado nos ambientes `local` e `testing`, inclusive quando chamado diretamente com `--class`.
- A exportação de clientes neutraliza fórmulas (`=`, `+`, `-`, `@`, tabulação e quebras de linha) com apóstrofo, por `App\Modules\Core\Support\CsvCell`. A importação remove esse apóstrofo, preservando o valor original. Novas exportações devem usar a mesma classe.

## Pendências registradas

| Pendência | Impacto |
|---|---|
| `RootAdmin\CompanyController::index` chamava `$this->authorize()` sem `AuthorizesRequests` | Resolvida na OMNICHANNEL-03 |
| `customers.view_sensitive` e `customers.manage_credit` estão no catálogo, mas não são aplicadas na ficha nem na edição | Quem tem `customers.view` vê documento e crédito |
| Páginas de Clientes (as de RootAdmin foram corrigidas na OMNICHANNEL-03) envolvem o conteúdo em `AppLayout`, que já é o layout padrão de `app.tsx` | Menu lateral renderizado em duplicidade |
| Clientes usa `with('success')`, que o front-end não lê (o padrão é `Inertia::flash('toast')`) | Confirmações não aparecem ao usuário |
| Edição de cliente regrava endereços e contatos por completo | Campos não exibidos no formulário podem ser perdidos |
| `AddressType` diverge dos tipos aceitos em `StoreCustomerRequest` (`shipping`, `other`) | Esses tipos falham ao serem lidos |
| `PartyFactory` é referenciada por `Party`, mas não existia | Resolvida na OMNICHANNEL-04 |
| `compose.yaml` usa `php:8.4-cli` sem extensões no worker | Worker não é funcional como está |
| 20 apontamentos do PHPStan (26 antes da OMNICHANNEL-04), 12 do `tsc` e 8 arquivos fora do formato do `vp check` | `composer ci:check` não passa |
| `yarn.lock` e `pnpm-lock.yaml` coexistem com o `package-lock.json` versionado | Definir um único gerenciador; os dois primeiros não foram versionados |
