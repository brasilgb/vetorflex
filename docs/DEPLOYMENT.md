# Implantação e rollback

Guia para publicar o VetorFlex em produção. Nenhum deploy foi executado até a OMNICHANNEL-11; publicar exige
autorização do proprietário.

## Requisitos

- **PHP 8.4** com as extensões: `pdo_mysql`, `mbstring`, `bcmath`, `intl`, `gd`, `zip`, `openssl`, `curl`, `fileinfo` e `redis` (phpredis) ou `predis`.
- **Banco**: MySQL 8.4 (alvo de produção) ou MariaDB 11.
    - As migrations rodam no MariaDB 11.8 local.
    - Nesta etapa não foi possível validar no MySQL 8.4 (ver "Validação no MySQL 8.4").
- **Redis 7**: filas, cache e sessão em produção.
- **Node 20+**: só para gerar os assets (`npm ci && npm run build`). O servidor não precisa de Node em execução.
- **Servidor web**: Nginx ou Caddy com HTTPS, apontando para `public/`.

## Variáveis de ambiente (produção)

| Variável                                                   | Valor recomendado                                             | Observação                                                                                                                      |
| ---------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `APP_ENV` / `APP_DEBUG`                                    | `production` / `false`                                        | Nunca `true` em produção.                                                                                                       |
| `APP_URL`                                                  | `https://<domínio>`                                           | Usado nos links de e-mail (verificação, redefinição de senha) e nas URLs de webhook.                                            |
| `APP_KEY`                                                  | `php artisan key:generate --show` (uma vez)                   | Criptografa credenciais, CSC e chaves de integração. **Perder a chave torna esses dados ilegíveis**; guarde-a fora do servidor. |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE`                       | `pt_BR` / `en`                                                |                                                                                                                                 |
| `DB_*`                                                     | Usuário próprio da aplicação, sem privilégios de superusuário |                                                                                                                                 |
| `QUEUE_CONNECTION`                                         | `redis`                                                       | Transmissão fiscal, webhooks e arquivos rodam na fila.                                                                          |
| `CACHE_STORE` / `SESSION_DRIVER`                           | `redis`                                                       |                                                                                                                                 |
| `SESSION_SECURE_COOKIE`                                    | `true`                                                        | Exige HTTPS.                                                                                                                    |
| `MAIL_*`                                                   | SMTP transacional com SPF, DKIM e DMARC no domínio            | **Obrigatório**: sem e-mail, o cadastro público não conclui (verificação) e a redefinição de senha não funciona.                |
| `COMMERCIAL_CONTACT_EMAIL` / `COMMERCIAL_CONTACT_WHATSAPP` | Canais comerciais reais                                       | Sem eles, a página pública mostra apenas o formulário.                                                                          |
| `COMMERCIAL_TRIAL_DAYS`                                    | `14`                                                          |                                                                                                                                 |
| `FISCAL_STORAGE_ROOT`                                      | Volume persistente com backup                                 | XML e PDF fiscais (guarda obrigatória).                                                                                         |

Credenciais de provedores (Efí, Spedy) **não** ficam no `.env`:

- as da plataforma são informadas no RootAdmin;
- as das empresas, nas telas de configuração.

Todas são guardadas criptografadas com a `APP_KEY`.

## Processos

- **Web**: PHP-FPM atrás do servidor web.
- **Fila**: `php artisan queue:work redis --tries=3 --timeout=90 --sleep=1`, sob Supervisor ou systemd, com reinício automático.
    - Mantenha ao menos 1 processo.
    - Depois de cada deploy, rode `php artisan queue:restart`.
- **Agendador**: `* * * * * php /caminho/artisan schedule:run >> /dev/null 2>&1`. Tarefas agendadas:
    - `finance:generate-recurrences` (diária);
    - `payments:reconcile` (15 em 15 minutos);
    - `fiscal:reconcile` (5 em 5 minutos);
    - `commercial:expire-trials` (diária, 00:10);
    - limpezas das etapas anteriores.
- **Health check**: `GET /up` responde 200 quando a aplicação inicializa.
    - Monitore também a fila: idade do job mais antigo e a tabela `failed_jobs`.
    - Monitore o último `schedule:run`.

### Docker Compose

`compose.yaml` serve ao desenvolvimento local e não está pronto para produção:

- o serviço `worker` usa `php:8.4-cli` puro, sem `pdo_mysql` nem a extensão do Redis;
- não há serviço de agendador.

Antes de usar contêineres em produção:

- crie uma imagem própria com as extensões acima;
- acrescente um serviço `scheduler` (`php artisan schedule:work`) e o servidor web.

Isso não foi feito nesta etapa porque não havia Docker disponível para validar.

## HTTPS e domínio

- Certificado TLS válido (Let's Encrypt ou equivalente) e redirecionamento de HTTP para HTTPS.
- Cabeçalhos recomendados: HSTS, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`.
- **Webhooks**: precisam de HTTPS público.
    - Pagamentos: `https://<domínio>/webhooks/payments/efi/<token>`, registrado pela tela de configurações da empresa.
    - Fiscal: `https://<domínio>/webhooks/fiscal/spedy`, registrado no RootAdmin.

## Primeira implantação

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan key:generate                    # somente na primeira vez
php artisan migrate --force
php artisan db:seed --class=PlatformModuleSeeder --force   # catálogo; não cria dados de demonstração
php artisan rootadmin:create                # primeiro administrador da plataforma
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
```

- Os seeders de demonstração (`DatabaseSeeder`) recusam-se a rodar fora de `local` e `testing`. Não os execute em produção.
- No RootAdmin, antes de abrir a venda:
    - preencha valores e textos do catálogo;
    - disponibilize as aplicações;
    - configure os provedores (sandbox até a homologação).

## Atualização (deploy)

1. Faça o backup (ver abaixo) e anote o commit em produção.
2. Ative o modo de manutenção: `php artisan down --retry=60`.
3. Atualize o código, rode `composer install --no-dev -o` e `npm ci && npm run build`.
4. `php artisan migrate --force`.
5. `php artisan optimize` e `php artisan queue:restart`.
6. `php artisan up`. Confira `/up`, o login, a fila e o log.

As migrations das etapas são aditivas. Quando alguma exigir janela, a etapa informa.

## Backups e recuperação

- **Banco**: dump diário consistente, retido por 30 dias, com uma cópia fora do servidor.
    - MySQL: `mysqldump --single-transaction --routines --triggers`.
    - O dump também é a base do rollback de migrations.
- **Arquivos**:
    - `storage/app/private` (anexos, imagens e documentos fiscais);
    - o volume de `FISCAL_STORAGE_ROOT`.
    - Documentos fiscais têm guarda legal: não os descarte.
- **`APP_KEY` e `.env`**: em cofre separado. Sem a `APP_KEY`, credenciais e certificados criptografados são irrecuperáveis.
- **Teste de restauração**: restaure periodicamente num ambiente separado e confira login, uma empresa e um documento fiscal.

## Rollback

1. `php artisan down`.
2. Volte o código para o commit anterior e reconstrua os assets (`npm ci && npm run build`).
3. **Banco**:
    - se a versão nova só acrescentou colunas e tabelas (caso das etapas até aqui), o código anterior convive com elas e o rollback de schema é desnecessário;
    - se houver incompatibilidade, restaure o dump feito antes do deploy;
    - use `php artisan migrate:rollback --step=N` apenas para migrations sem dados novos, pois remover colunas apaga dados.
4. `php artisan optimize:clear && php artisan optimize && php artisan queue:restart`, depois `php artisan up`.
5. Documentos fiscais transmitidos e cobranças emitidas durante a versão revertida continuam válidos nos provedores. A conciliação (`fiscal:reconcile`, `payments:reconcile`) atualiza a situação após o retorno.

## Logs

- Canal `daily` (ou `stack` com `daily` e um serviço externo), com retenção de 14 a 30 dias.
- Os logs não contêm credenciais, CSC, certificados nem payloads completos (garantido pelos módulos).
- **Auditoria de negócio**: tabela `audit_logs`, consultável no RootAdmin.

## Segurança e isolamento (checklist)

- [ ] `APP_DEBUG=false`, HTTPS e cookies seguros.
- [ ] Usuário de banco sem privilégios administrativos.
- [ ] RootAdmin com senha forte; administradores revisados.
- [ ] O isolamento entre empresas é garantido por `company_id` + `tenant.records` e coberto pela suíte de testes.
- [ ] Permissões e dependências dos módulos: o catálogo é a fonte e o backend valida tudo.
- [ ] Rate limiting ativo: login 5/min, cadastro 5/min, contato 10/h, webhooks.
- [ ] Emissão fiscal em produção somente após homologação e liberação por empresa no RootAdmin.

## Validação no MySQL 8.4

Ainda pendente: o ambiente desta etapa não tem Docker nem MySQL 8.4 (só MariaDB 11.8). Para validar:

```bash
docker compose up -d mysql redis
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33076 DB_DATABASE=omnichannel DB_USERNAME=omnichannel DB_PASSWORD=secret \
  php artisan migrate:fresh --seed --force
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33076 DB_DATABASE=omnichannel_test DB_USERNAME=root DB_PASSWORD=root \
  php artisan test --parallel
```

Pontos de atenção:

- nomes de índices e FKs explícitos (limite de 64 caracteres, já respeitado);
- colunas JSON;
- `decimal` com `BigDecimal`;
- `whereJsonContains`;
- ordenação de colação `utf8mb4_0900_ai_ci`, diferente da `utf8mb4_unicode_ci`/`uca1400` do MariaDB.
