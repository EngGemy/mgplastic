# MG Plastic — Laravel Admin + Mobile API

Laravel 12 + Filament 3 application for wholesale distribution, points, and mobile apps.

| Area | URL (local) |
|------|-------------|
| Admin panel | `/admin` |
| API docs (Scramble) | `/docs/api` |
| Mobile API guide | `docs/API_MOBILE.md` |

## Requirements

- PHP 8.2+
- Composer
- Node.js 20+
- MySQL / MariaDB

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
npm ci && npm run build
php artisan serve
```

## Deployment

Production: **mg-plastic.com** on cPanel (`mgplasti`).

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** for GitHub Actions + cPanel setup.

Push to `main` → CI tests → auto-deploy to server.

## Demo logins

| Email | Role | Password |
|-------|------|----------|
| superadmin@mgplastic.com | Admin | password |
| wholesaler@mgplastic.com | Distributor | password |
| retailer@mgplastic.com | Retail trader | password |
