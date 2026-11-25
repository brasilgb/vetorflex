# OMNICHANNEL-02 — Resultado consolidado

## Comparação e execução

`correio.md` não existia no histórico Git disponível e foi identificado como uma nova instrução para a OMNICHANNEL-02. A execução foi realizada neste diretório, sem push ou deploy.

## Funcionalidades implementadas

- Painel compartilhado com seletor de empresa ativa e navegação dinâmica: Clientes aparece somente quando o módulo está habilitado para a empresa.
- Escopo empresarial aplicado em todas as rotas do módulo Clientes. Registros de outra empresa retornam 404.
- Middleware de módulo/permissão: cada operação exige módulo `customers` habilitado e a permissão individual correspondente; proprietários recebem as permissões do módulo habilitado.
- Cadastro, listagem, ficha e edição de clientes funcionais no backend e Inertia/React.
- Domínio compartilhado de pessoas preservado: uma `Party` recebe o papel `customer` em `party_roles`, permitindo papéis futuros, como fornecedor, sem duplicar a pessoa.
- Perfil comercial, endereços e contatos são persistidos junto ao cliente, em transação.
- Pesquisa por nome, nome fantasia, documento ou e-mail; filtros por tipo/situação; paginação com filtros preservados.
- Inativação e reativação sem exclusão física.
- Validação de CPF/CNPJ e prevenção de documento duplicado dentro da empresa.
- Exportação CSV e importação CSV com linhas duplicadas, sem nome ou documento inválido ignoradas; cabeçalhos com BOM e colunas excedentes são tolerados, com relatório persistido.
- Seed idempotente de demonstração: empresa, módulo Clientes habilitado, usuário `demo@vetornew.test` (senha `password`) e dois clientes de exemplo.
- Documentação de desenvolvimento atualizada com o seed e as regras de acesso.

## Validações executadas

- `vendor/bin/pint --dirty --format agent` — aprovado.
- `php artisan test --compact` — 33 testes aprovados, 84 assertions e 2 testes condicionais ignorados.
- `php artisan db:seed --no-interaction` — seed de demonstração executado no banco local.
- `php artisan route:list --path=customers` — 8 rotas confirmadas.
- `npm run build` — aprovado.

## Pendência de homologação

- Redis e worker não foram iniciados, pois a importação atual é síncrona e não os requer. Devem ser homologados quando forem necessários para filas assíncronas.
