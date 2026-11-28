# OMNICHANNEL-10

Entrega: o módulo Documentos Fiscais 1.0.0 (`FISCAL.md`), com NFS-e, NF-e e NFC-e pela Spedy. A integração foi reaproveitada do VetorOS. O módulo é independente e integra-se opcionalmente a Vendas, PDV e Financeiro. Esta etapa também preparou a consolidação dos repositórios Git.

## Navegação

- **Menu Documentos fiscais**: Visão geral, Documentos, Novo documento, Dados fiscais dos itens, Relatórios e Configurações, conforme as permissões `fiscal.*`.
- **RootAdmin**: novo item Documentos fiscais, com provedor, tipos liberados, chave da plataforma, teste, registro do webhook e liberação de produção por empresa.
- **Vendas, PDV e Financeiro**: seção "Documentos fiscais" na ficha da venda, do atendimento e da conta a receber, só com o módulo contratado.

## Migração

`2026_12_01_100000_create_fiscal_tables`, aditiva:

- tabelas `fiscal_providers`, `fiscal_settings`, `fiscal_documents`, `fiscal_document_events` (FK composta por empresa) e `fiscal_webhook_events`;
- colunas fiscais opcionais em `products`: `cfop`, `icms_situation`, `pis_situation`, `cofins_situation`, `service_code`, `service_city_code` e `tax_unit`. NCM, CEST e origem já existiam.

## Ajustes em recursos compartilhados

- **Vendas, PDV e Financeiro**: as fichas recebem `fiscalDocuments` (`FiscalDirectory`), nulo sem o módulo. Nenhuma regra desses módulos foi alterada.
- **Infraestrutura**:
    - disco privado `fiscal` (`config/filesystems.php`);
    - `config/fiscal.php`;
    - exceção de CSRF só para `webhooks/fiscal/*`;
    - comando agendado `fiscal:reconcile`.
- **Pagamentos (revisão da Efí)**:
    - o registro do webhook volta a enviar `x-skip-mtls-checking: true`, como na integração em produção do VetorOS;
    - a confirmação continua vindo só da consulta à API;
    - texto de ajuda e `PAYMENTS.md` atualizados, com teste.

## Reaproveitamento do VetorOS

O VetorOS foi usado só como referência de leitura: nenhum arquivo foi alterado e não há dependência entre os sistemas (banco, endpoints, credenciais ou arquivos). O detalhamento está em `executed.md`. Resumo:

- **Reaproveitado e adaptado**:
    - cliente HTTP da Spedy (duas chaves, limite de 429, mensagens seguras, erro de certificado sem corpo);
    - montagem dos DTOs com os limites de campo comprovados em produção;
    - mapa e ordem de estados;
    - cadastro da emissora com a chave gravada antes de qualquer outra chamada;
    - envio do certificado;
    - verificação Standard Webhooks e conferência de CNPJ;
    - `integrationId` estável, localização por ele após timeout e conciliação;
    - armazenamento privado com SHA-256 e retentativas.
- **Reimplementado**: o modelo de dados (multiempresa com `company_id` e FK composta, em vez de banco por tenant), o documento fiscal independente da venda e a arquitetura de provedores com capacidades. Motivos: o VetorFlex é um monólito multiempresa com módulos independentes, e o VetorOS emite a partir da ordem de serviço.

## Repositórios Git

Situação e comandos: ver `executed.md`. Nada foi publicado: não houve push, reescrita de histórico nem remoção do `.git-local`.
