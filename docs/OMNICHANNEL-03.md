# OMNICHANNEL-03

Entrega: módulo Produtos 1.0.0 (`PRODUCTS.md`), núcleo de movimentação de mercadorias (`INVENTORY-CORE.md`) e autorização de módulos pelo RootAdmin.

## RootAdmin

- `/rootadmin/companies`: ativa e desativa módulos por empresa. Desativar bloqueia o acesso e preserva os dados.
- `/rootadmin/modules`: disponibilidade comercial, restrita a módulos implementados. Não ativa o módulo em nenhuma empresa.
- O seed do catálogo não altera mais a disponibilidade comercial definida pelo RootAdmin.
- Corrigido o erro 500 de `/rootadmin/companies`, pendente da OMNICHANNEL-02.

## Pendências registradas

| Pendência | Impacto |
|---|---|
| Interações no navegador não foram testadas (sem Chrome no ambiente) | Diálogos, upload, abas e leitor de código precisam de homologação manual, inclusive em celular |
| Não há tela para conceder permissões individuais a um usuário | Operadores recebem permissões apenas pelo vínculo `company_user.permissions`; proprietários têm todas |
| Importação síncrona, limitada a 500 registros | Volumes maiores exigem fila e worker |
| Imutabilidade das movimentações só no model | Ver `INVENTORY-CORE.md` |
| Demais pendências da OMNICHANNEL-02 | Ver `OMNICHANNEL-02.md` |
