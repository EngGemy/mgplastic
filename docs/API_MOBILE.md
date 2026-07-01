# MG Plastic — Mobile API Documentation

**Version:** 1.0  
**Base URL:** `http://127.0.0.1:8000/api`  
**Interactive docs (Swagger/OpenAPI):** `http://127.0.0.1:8000/docs/api`

---

## Authentication

All protected routes require header:

```
Authorization: Bearer {sanctum_token}
Accept: application/json
Accept-Language: ar
```

### Register (Plumber / Vendor)

```http
POST /api/v1/auth/register-plumber
Content-Type: application/json

{
  "name": "محمود",
  "phone": "218912345678",
  "password": "password",
  "password_confirmation": "password",
  "role": "plumber"
}
```

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

> **Retail trader / Wholesale distributor** accounts are created by admin or parent distributor. They login with the same `/auth/login` endpoint.

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

## Settings (public)

```http
GET /api/v1/mobile/settings
```

Returns: conversion rules, withdrawal methods, app labels, terms/privacy links, iOS wallet visibility.

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
| GET | `/plumbers` | My plumbers list |
| GET | `/plumbers/{id}` | Plumber detail |
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
