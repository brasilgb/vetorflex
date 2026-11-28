# OMNICHANNEL-07

Entrega: módulo PDV — Frente de Caixa 1.0.0 (`POS.md`), comercializável sozinho.

## Navegação

Menu **PDV**: Frente de caixa, Sessões de caixa, Vendas do caixa, Relatórios, Terminais e Configurações, conforme as permissões `pos.*`. A frente de caixa (`/pos/sessions/{id}/register`) é uma tela dedicada, em tela cheia, com atalhos de teclado e alternativas por toque.

## Ajustes em recursos compartilhados

- `QuickSaleService::register` e `SaleOrderService::create` aceitam um `SaleChannel` (origem, referência e regra de venda sem identificação), definido pelo servidor, e uma etapa `beforeConfirm` entre o cálculo do total e a confirmação. Sem canal, o comportamento de Vendas é o mesmo de antes.
- `SaleOrderService::schedulePayment` registra o plano de pagamentos combinados calculado pelo PDV.
- `sale_orders.origin_reference` guarda a referência legível do canal (terminal e sessão), exibida na lista e no detalhe de Vendas, que ganhou o filtro por origem.
- `PaymentCondition::Combined` (fora das opções dos formulários) e a forma de pagamento vale (`PaymentMethod::Voucher`).
- Financeiro: ouvinte `SyncPosOperations` e `FinanceAccountDirectory` (contas ativas para a configuração do PDV), sem leitura de tabelas do PDV.
- Os testes de demonstração de Vendas passaram a contar devoluções somente da "Empresa Demonstração", porque o `PosDemoSeeder` cria devoluções em outras empresas.

## Correções

- A primeira via do comprovante era sempre recusada a operadores sem `pos.reprint`. A comparação da situação da sessão usava texto, mas o Eloquent devolve o enum. Corrigido e coberto por teste.
- Tipos ajustados para o PHPStan nos serviços e controladores do PDV (listas, credenciais e linhas de relatório normalizadas como texto) e em `useHttp` da frente de caixa.

## Catálogo

`pos` é registrado como implementado (1.0.0), sem dependências, com integrações opcionais com Clientes, Produtos, Vendas e Financeiro. Como os demais, começa indisponível comercialmente e não é ativado nas empresas existentes; o RootAdmin decide a disponibilidade e a habilitação por empresa. As funcionalidades do RootAdmin da OMNICHANNEL-06 foram preservadas.
