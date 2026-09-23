# CUPAD Laravel Migration

This directory is the Laravel migration of the existing CUPAD procedural PHP system.

## Migration principles

- Existing CUPAD MySQL database: `cupadnam_db`
- Existing domain tables are reused; this project does not replace the CUPAD schema with a generic Laravel schema.
- Existing roles are preserved: `admin`, `tm`, `am`, `bm`, `co`, `dzm`, `zm`, and `client`.
- Savings source of truth is `savings.balance`.
- `saving_collections` remains transaction/history data.
- `saving_balances` is synchronized as a compatibility balance cache.
- Mobile API paths are being kept compatible with the existing CUPAD mobile client.
- Laravel authentication does not require browser geolocation, so localhost login works when location permission is disabled.

## Local XAMPP setup

1. Make sure PHP 8.2+, MySQL and Composer are available.
2. Copy `.env.example` to `.env`.
3. Set the existing CUPAD database credentials in `.env`.
4. From this directory run:

```bash
composer install
php artisan key:generate
php artisan storage:link
php artisan optimize:clear
```

5. Do **not** run `php artisan migrate:fresh` against the existing CUPAD database.
6. Start the Laravel application with Apache under XAMPP or use:

```bash
php artisan serve
```

## API compatibility

Current migrated endpoints include:

- `POST /api/auth/login`
- `GET /api/me`
- `POST|PUT /api/profile`
- `GET /api/dashboard/stats`
- `GET /api/activities`
- `GET /api/clients`
- `GET /api/clients/{id}`
- `GET /api/clients/{id}/portfolio`
- `POST /api/clients/register`
- `POST /api/savings/collect`
- `POST /api/savings/withdraw`
- `POST /api/loans/collect`
- `POST /api/loans/disburse`
- `GET /api/combined/union-data`
- `POST /api/combined/save-client`

The migration is incremental. The original procedural PHP system remains the reference implementation until each CUPAD module has been migrated and verified.
