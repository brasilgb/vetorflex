# Omnichannel

Fundação Laravel para uma plataforma SaaS modular e multiempresa.

## Ambiente local

Configure MySQL 8.4, Redis e as variáveis de `.env`, depois execute:

```bash
php artisan migrate --seed
npm run dev
php artisan serve
```

Consulte `docs/ARCHITECTURE.md`, `docs/TENANCY.md` e `docs/MODULES.md` para os contratos da fundação.
