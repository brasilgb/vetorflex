# Tenancy

Uma empresa possui `slug` único, status, timezone, moeda e configurações. Usuários se associam pela tabela `company_user`, que armazena o papel (`owner`, `administrator` ou `operator`) e o estado do vínculo.

`ResolveTenant` lê `active_company_id` da sessão, confirma que o usuário possui vínculo ativo e que a empresa está ativa, e expõe a empresa no request. Rotas empresariais devem usar esse middleware. Policies ainda são obrigatórias para recursos individuais.

O RootAdmin é identificado por `users.is_root_admin`; ele não recebe acesso implícito a dados de uma empresa por esse campo.
