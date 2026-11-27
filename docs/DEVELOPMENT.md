# Desenvolvimento local

Execute `docker compose up -d mysql redis worker` para iniciar MySQL 8.4, Redis e o worker. As portas externas padrão são 33076 e 36379 para não conflitar com serviços locais; os dados usam volumes nomeados e os serviços possuem healthchecks.

Após os serviços ficarem saudáveis, execute `php artisan migrate --seed`. O Docker Desktop/integração WSL é necessário para iniciar os containers neste ambiente.

## Dados de demonstração

`php artisan db:seed --no-interaction` cria a empresa **Empresa Demonstração**, habilita o módulo Clientes e adiciona o acesso `demo@vetornew.test` com a senha `password` (somente para desenvolvimento). A empresa já contém dois clientes de exemplo.

O menu permite selecionar a empresa ativa. Cada rota de Clientes exige a empresa ativa, o módulo habilitado e a permissão correspondente do vínculo do usuário.

O seed também habilita o módulo Produtos na empresa de demonstração e cria 8 itens fictícios com categorias, marcas, variações e movimentações (`ProductDemoSeeder`, somente em `local` e `testing`). Repetir o seed não duplica itens nem movimentações.

`PurchaseDemoSeeder` (somente `local` e `testing`) habilita Compras e cria, pelos serviços reais, 4 fornecedores (um deles também cliente), itens com mais de um fornecedor, pedidos em rascunho, confirmado, parcialmente recebido e recebido, frete e descontos, compra parcelada, devolução e compra rápida. Estoque e custo médio mudam somente pelos recebimentos e devoluções. Repetir o seed, inclusive em outro dia, não duplica documentos.
