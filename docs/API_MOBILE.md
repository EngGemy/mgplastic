# MG Plastic — Mobile API Documentation

**Version:** 2.0  
**Base URL:** `http://127.0.0.1:8000/api` · Production: `https://mg-plastic.com/api`  
**Interactive docs (Swagger/OpenAPI):** `GET /docs/api`

> **الدليل الشامل المحدّث:** [MOBILE_API_COMPLETE.md](./MOBILE_API_COMPLETE.md)  
> طلبيات: [MOBILE_NETWORK_ORDERS.md](./MOBILE_NETWORK_ORDERS.md) · مرتجعات: [MOBILE_INVOICE_RETURNS.md](./MOBILE_INVOICE_RETURNS.md)

---

## Authentication

All protected routes require header:

```
Authorization: Bearer {sanctum_token}
Accept: application/json
Accept-Language: ar
```

### Register

| Role | Endpoint |
|------|----------|
| سباك | `POST /api/v1/auth/register-plumber` |
| قطاعي | `POST /api/v1/auth/register-retail` (alias `register-trader`) |
| جملة | `POST /api/v1/auth/register-store` |

### Login

```http
POST /api/v1/auth/login
{
  "phone": "218912345678",
  "password": "password"
}
```

If phone not verified → OTP sent, `token` is `null`. Then:

```http
POST /api/v1/auth/verify-otp
{
  "phone": "218912345678",
  "otp": "123456"
}
```

### Session

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/mobile/me` | Current user profile |
| POST | `/api/v1/mobile/logout` | Revoke current token |
| POST | `/api/v1/mobile/logout-all` | Revoke all tokens |

الجملة يقدر يسجّل قطاعي في شبكته من `POST /mobile/distributor/retail-traders` كمان.

---

## Profile (all roles)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/mobile/profile` | Get profile |
| PUT/PATCH/POST | `/api/v1/mobile/profile` | Update profile |

**Updatable fields:** `name`, `email`, `about_me`, `short_description`, `long_description`, `store_description`, `video_url`, `address`, `latitude`, `longitude`, `country_id`, `city_id`, `profile_photo` (multipart)

---

## Notifications (all roles)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/mobile/notifications` | Paginated list |
| GET | `/api/v1/mobile/notifications/unread-count` | Unread count |
| POST | `/api/v1/mobile/notifications/{id}/read` | Mark one read |
| POST | `/api/v1/mobile/notifications/read-all` | Mark all read |

---

## Product module

Public catalog: categories, subcategories, tags, and product filters. No token.

Full guide: [PRODUCT_MODULE.md](PRODUCT_MODULE.md)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/products/categories` | Category tree, or filter by `search` / `tag` / `parent_id` |
| GET | `/api/v1/products` | Filter products |
| GET | `/api/v1/products/category/{id}` | Products in one category |
| GET | `/api/v1/products/{id}` | One product |

---

## Settings (public)

```http
GET /api/v1/mobile/settings
```

Returns: conversion rules, withdrawal methods, app labels, support phone and WhatsApp, terms/privacy links, iOS wallet visibility.

Support numbers are the website contact phone and WhatsApp (admin → إعدادات الموقع → التواصل). Full contract: [MOBILE_SUPPORT.md](MOBILE_SUPPORT.md)

---

## Plumber API

Prefix: `/api/v1/mobile/plumber` — requires role `plumber`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/dashboard` | Wallet, pending withdrawals, stats |
| GET | `/wallet` | Balance (points + money) |
| GET | `/wallet/transactions` | Paginated ledger |
| GET | `/wallet/conversion-rules` | Points → money rules |
| POST | `/wallet/convert` | Convert points `{ "points": 500 }` |
| GET | `/withdrawals` | List withdrawal requests |
| GET | `/withdrawals/{id}` | Single withdrawal |
| POST | `/withdrawals` | Request withdrawal |
| GET | `/products` | MG catalog for a supply order. Optional `retail_trader_id` limits the list to that shop's stock |
| POST | `/orders` | Place a supply order. Quantities are checked against the shop's stock |

**Shop stock on the product list**

`GET /api/v1/mobile/plumber/products?retail_trader_id={id}&page=&search=&category_id=&tag=`

Without `retail_trader_id` the response is the full MG catalog, same fields as before.

With `retail_trader_id` the list contains only products that shop has in stock (`available_quantity` > 0). Each item keeps the existing fields and adds `available_quantity`. Pagination stays `{ items, pagination: { current_page, last_page, per_page, total } }`.

`search` and `q` both search. `tag` or `tags` filters by tag name. `category_id` and `page` / `per_page` work as before.

The shop must be an approved, active retail trader. Unknown ids return 404. An inactive or unapproved shop returns 403.

`POST /api/v1/mobile/plumber/orders` rejects a line whose quantity is above that shop's stock with 422 and a message that includes the available quantity.

```json
{
  "id": 12,
  "name": "ماسورة 160 بوصة خط ذهبي MG",
  "category_id": 3,
  "category_name": "مواسير الذهبي",
  "image_url": "https://...",
  "points_per_unit": 230.3,
  "has_points": true,
  "available_quantity": 40
}
```

**Withdrawal example:**

```json
{
  "amount_cents": 5000,
  "method": "bank_transfer",
  "details": {
    "name": "محمود",
    "iban": "LY00000000000000000000",
    "bank_name": "مصرف الجمهورية"
  }
}
```

### Legacy plumber routes (still active)

| Endpoint | Description |
|----------|-------------|
| `POST /api/v1/plumber/update-profile` | Profile (plumber-only) |
| `GET/POST/DELETE /api/v1/plumber/work-photos` | Work portfolio |
| `GET/POST /api/v1/plumber/invoices` | Invoice upload |
| `GET /api/v1/plumber/invoices/received` | Received distributions |

---

## Retail Trader API (تاجر التجزئة)

Prefix: `/api/v1/mobile/trader` — requires role `retail_trader`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/dashboard` | Stock summary, plumbers count, wallet |
| GET | `/plumbers` | Plumbers linked to this trader (`parent_distributor_id`) |
| POST | `/plumbers/link` | Link an existing plumber by `code` (MG-P-…) or `phone`. 201 if new, 200 if already yours, 422 if linked to another trader |
| GET | `/plumbers/{id}` | Plumber detail, only if he belongs to this trader |
| GET | `/pos/stock` | POS inventory (same as trader panel) |
| POST | `/pos/checkout` | Sell points to plumber |

**POS checkout example:**

```json
{
  "plumber_id": 12,
  "lines": [
    { "product_id": 3, "quantity": 2 },
    { "product_id": 5, "quantity": 1 }
  ]
}
```

### Shared network routes

| Endpoint | Description |
|----------|-------------|
| `GET/PUT /api/v1/my-store` | Store profile + media |
| `GET/POST /api/v1/distributions` | Distributions |

---

## Wholesale Distributor API (موزع الجملة)

Prefix: `/api/v1/mobile/distributor` — requires role `wholesale_distributor`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/dashboard` | Stock, retail traders count |
| GET | `/retail-traders` | My retail traders |
| GET | `/retail-traders/{id}` | Trader detail |
| GET | `/pos/stock` | Wholesale inventory |
| POST | `/pos/checkout` | Sell to retail trader |

**POS checkout example:**

```json
{
  "retail_trader_id": 8,
  "lines": [
    { "product_id": 3, "quantity": 10 }
  ]
}
```

---

## Response format

**Success:**

```json
{
  "status": true,
  "message": "OK",
  "data": { }
}
```

**Error:**

```json
{
  "status": false,
  "message": "Error description",
  "errors": { }
}
```

---

## OpenAPI / Swagger

- **UI:** `/docs/api`
- **JSON spec:** `/docs/api.json` (Scramble auto-generated from routes)

Export to file:

```bash
php artisan scramble:export
```

Output: `api.json` in project root.

---

## Demo accounts

| Email | Role | Password |
|-------|------|----------|
| superadmin@mgplastic.com | admin | password |
| wholesaler@mgplastic.com | wholesale_distributor | password |
| retailer@mgplastic.com | retail_trader | password |

Plumbers: register via `/auth/register-plumber` or use demo seeder.

---

## Architecture notes

- **Services reused from admin panels:** `NetworkInventoryService`, `PlumberDistributionPosService`, `RetailDistributionPosService`, `DistributionService`
- **Auth:** Laravel Sanctum bearer tokens
- **OTP:** Marsol SMS (Libya) or local OTP for dev
- **Notifications:** Laravel database notifications (Filament-compatible format)
