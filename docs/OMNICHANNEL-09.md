# OMNICHANNEL-09

Entrega: módulo Pagamentos 1.0.0 (`PAYMENTS.md`) com Pix pela Efí, independente e integrável a Vendas, Financeiro e PDV.

## Navegação

- Menu **Pagamentos**: Visão geral, Cobranças, Nova cobrança, Conciliação, Relatórios e Configurações, conforme as permissões `payments.*`.
- RootAdmin: novo item **Pagamentos**.
- Vendas e Financeiro: seção "Cobranças Pix" na ficha da venda e da conta a receber (somente com Pagamentos contratado).
- PDV: "Gerar Pix com confirmação automática" no pagamento.

## Ajustes em recursos compartilhados

- **PDV**:
    - `PosCheckout::plan` aceita `payment_charge_id` em pagamentos Pix à vista;
    - `PosPix::claim` confirma a cobrança sob trava na finalização;
    - `pos_payments` ganhou `payment_charge_id` (único) e `is_provider_confirmed`;
    - a tela recebe `providerPix`.
- **Financeiro**: novo ouvinte `SyncPaymentOperations`, registrado no `AppServiceProvider`.
- **Vendas e Financeiro**: as fichas recebem `paymentCharges` (`ChargeDirectory`), nulo sem o módulo.
- **Infraestrutura**:
    - exceção de CSRF só para `webhooks/payments/*`;
    - comando agendado `payments:reconcile`;
    - `config/payments.php`.

## Repositórios Git

- **`.git`**: remoto `origin` (`git@github-vetorflex:brasilgb/vetorflex.git`, `main`), com 2 commits (`f6724bc` primeiro commit, `7ef80d4` Compras). O conteúdo de `7ef80d4` é igual ao do commit `8919435` do `.git-local`; a única diferença é que `.git` também versiona `correio.md`, `pnpm-lock.yaml` e `yarn.lock`.
- **`.git-local`**: histórico de desenvolvimento das etapas, sem remoto, com 8 commits até a OMNICHANNEL-08 (`408adcc`).
- As OMNICHANNEL-05 a 09 não estão no `.git` nem no GitHub.

**Proposta segura (não executada; requer autorização):**

1. Manter o `.git` como repositório oficial (é o que tem remoto).
2. Num ramo novo do `.git` (por exemplo, `omnichannel-consolidation`, a partir de `origin/main`), registrar o estado atual do diretório de trabalho como um único commit "OMNICHANNEL-04 a 09". Não reescreve o histórico publicado nem exige push forçado.
3. Revisar e enviar esse ramo por pull request, decidindo antes se `correio.md`, `pnpm-lock.yaml` e `yarn.lock` devem continuar versionados (o projeto usa `npm`/`package-lock.json`).
4. Depois do merge, arquivar o `.git-local` fora do diretório do projeto (por exemplo, `../vetorflex-git-local.bundle` via `git bundle`) em vez de apagá-lo, preservando o histórico detalhado.

Alternativa, se for importante manter os commits por etapa: importar o histórico do `.git-local` como ramo do `.git` (`git fetch .git-local main:local-history`) e reaplicar só as etapas posteriores a `8919435` com `cherry-pick` sobre `origin/main`.

## RootAdmin

O cadastro do administrador principal já foi feito pelo proprietário (OMNICHANNEL-08). Nesta etapa, o RootAdmin ganhou o controle de disponibilidade dos provedores e a consulta técnica de Pagamentos. Pagamentos está no catálogo como implementado 1.0.0, sem dependências, indisponível comercialmente até decisão do RootAdmin e sem ativação automática nas empresas.
