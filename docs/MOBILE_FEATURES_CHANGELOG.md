# MG Plastic — Mobile & Panel features changelog

**Date:** 2026-10-19  
**Audience:** React Native + admin ops  
**Base API:** `https://mg-plastic.com/api/v1/mobile`

This file summarizes **all recent backend features** the mobile app should wire: banners, product groups, promotions, featured wholesalers, low-stock alerts (panel), factory=warehouse stock, فواتيري / returns security, points scrubbing, and biometric login.

Related deep-dives:

- [MOBILE_BIOMETRIC_RN.md](./MOBILE_BIOMETRIC_RN.md) — biometric RN handoff  
- [MOBILE_COLLECTIONS_BANNERS_INVOICES.md](./MOBILE_COLLECTIONS_BANNERS_INVOICES.md) — collections / banners / فواتيري  
- [MOBILE_FEATURED_AND_PROMOS.md](./MOBILE_FEATURED_AND_PROMOS.md) — featured + promotions + return guard  
- [MOBILE_INVOICE_RETURNS.md](./MOBILE_INVOICE_RETURNS.md) — returns API  

---

## 0) Quick route map (new / updated)

| Feature | Method | Path |
|---------|--------|------|
| App settings (+ biometric flag) | GET | `/settings` |
| Featured wholesalers | GET | `/featured-wholesalers` |
| MG product ads | GET | `/product-promotions` · `/product-promotions/{id}` |
| Product collections | GET | `/product-collections` · `/product-collections/{id}` · `?kind=` |
| Promo banners | GET | `/promo-banners?section=between_plumbers_stores` |
| Factory catalog | GET | `/distributor/products` |
| Warehouse stock (= factory catalog) | GET | `/distributor/warehouses/{id}/stock` |
| Order sources | GET | `/distributor/order-sources` |
| فواتيري (جملة) | GET | `/distributor/my-invoices` · `/distributor/invoices?scope=` |
| فواتيري (قطاعي) | GET | `/trader/my-invoices` · `/trader/invoices` |
| Biometric register | POST | `/biometric/register` (auth) |
| Biometric challenge | POST | `/biometric/challenge` |
| Biometric verify | POST | `/biometric/verify` |
| Biometric devices | GET/DELETE | `/biometric/devices` · `/biometric/devices/{id}` |

---

## 1) Promo banners (بين السباكين والمتاجر)

**Admin:** المحتوى → بانرات دعائية → [create](https://mg-plastic.com/mg-cp/promo-banners/create)

```http
GET /api/v1/mobile/promo-banners?section=between_plumbers_stores
```

Each item:

```json
{
  "id": 1,
  "title": "…",
  "subtitle": "…",
  "image_url": "https://…",
  "section": "between_plumbers_stores",
  "link": {
    "type": "product_collection|product_promotion|product|url|none",
    "id": 12,
    "url": null,
    "path": "/api/v1/mobile/product-collections/12"
  },
  "sort_order": 0
}
```

**RN:** render carousel between Plumbers and Stores sections; on press navigate by `link.type`.

---

## 2) Product collections (مجموعات المنتجات)

**Admin:** المحتوى → مجموعات المنتجات  
Kinds: `featured` · `new` · `important` · `custom`

```http
GET /api/v1/mobile/product-collections
GET /api/v1/mobile/product-collections?kind=featured
GET /api/v1/mobile/product-collections/{id}
```

Show detail includes `products[]` (same catalog fields; `points_per_unit` always `null`).

---

## 3) MG product promotions (دعاية منتجات)

**Admin:** المحتوى → دعاية منتجات MG  
Media: `image` · `video` · `comparison`

```http
GET /api/v1/mobile/product-promotions
GET /api/v1/mobile/product-promotions/{id}
```

Fields: `title`, `subtitle`, `body`, `media_type`, `image_url`, `comparison_image_url`, `video_url`, `product_id`, `product_url`.

---

## 4) Featured wholesalers (أهم الموزعين)

**Admin:** Users → wholesale → toggle **أهم الموزعين** + sort

```http
GET /api/v1/mobile/featured-wholesalers
```

```json
{
  "items": [
    { "id": 12, "name": "…", "logo": "https://…", "logo_url": "https://…", "featured_sort": 1 }
  ]
}
```

---

## 5) Factory = warehouse stock

Central warehouse and factory share the **same product catalog** in the API.

```http
GET /distributor/warehouses/{id}/stock
```

- `same_as_factory: true`
- Same products as `GET /distributor/products`
- `available_qty`: number if slotted stock exists, else `null` (treat like factory listing)

`GET /distributor/order-sources` includes `catalog_url` and `same_inventory_as_factory` on warehouse rows.

---

## 6) Low-stock quantity warning (لوحة التحكم)

**Panel only** (not a mobile public feed): widget on Admin / Distributor / Trader / Warehouse dashboards.

- Title: **تنبيه للكميات القليلة**
- Products with `available_qty ≤ 5`
- Popup on load + dismissible list

Mobile can later consume the same idea from POS stock endpoints if needed.

---

## 7) فواتيري (My invoices) + returns

### Wholesale

```http
GET /distributor/my-invoices
GET /distributor/invoices?scope=all|sales|purchases
```

| bucket | Meaning | Who requests return |
|--------|---------|---------------------|
| `purchases` | Incoming from factory/warehouse | **Wholesale** |
| `sales` | Outgoing to retail | **Retail only** (wholesale approves) |

Purchase return:

```http
GET  /distributor/invoices/{id}/return-preview
POST /distributor/invoices/{id}/returns
# or distribution paths
GET  /distributor/distributions/{id}/return-preview
POST /distributor/distributions/{id}/returns
```

### Retail

```http
GET /trader/my-invoices
GET /trader/invoices
```

Retail requests return on purchases from wholesale; wholesale approves.

### Security rules (important)

| Action | Allowed |
|--------|---------|
| Request return | **Lower party only** (plumber → retail, retail → wholesale, wholesale → factory/warehouse) |
| Approve / reject | **Original seller only** (`to_user`) |
| Self-approve | **Blocked** (`from_user` and `created_by` cannot decide) |
| Upper invents return | **403** (e.g. retail cannot POST plumber return; wholesale cannot invent retail return) |

Audited in `decision_audit` + `security_audit_logs`.

---

## 8) Points scrubbing

Mobile APIs hide per-product points:

- `points_per_unit` → `null`
- Line `points_value` on returns → `null`
- Show **totals only**: order `total_points`, wallet `balance_points`, return `total_points`

Use `has_points` boolean when you need a badge.

---

## 9) Biometric verification

See full RN guide: [MOBILE_BIOMETRIC_RN.md](./MOBILE_BIOMETRIC_RN.md)

Short path:

1. Password login  
2. `POST /biometric/register` → store `unlock_token` in SecureStore behind Face ID  
3. Later: `challenge` → `verify` → new Sanctum `token`

Settings:

```json
"biometric": {
  "enabled": true,
  "challenge_ttl_seconds": 120,
  "methods": ["unlock_token", "public_key_signature"]
}
```

---

## 10) Admin panel screens (content)

| Screen | Path |
|--------|------|
| دعاية منتجات MG | `/mg-cp/product-promotions` |
| بانرات دعائية | `/mg-cp/promo-banners` |
| مجموعات المنتجات | `/mg-cp/product-collections` |
| أهم الموزعين | User edit → toggle |

Create/edit forms for promotions and banners were redesigned with sections, media type buttons, and publish sidebar.

---

## 11) Deploy checklist (server)

```bash
php artisan migrate --force
php artisan route:clear
php artisan config:clear
```

Confirm:

```bash
php artisan route:list --path=biometric
php artisan route:list --path=promo-banners
php artisan route:list --path=product-collections
```

---

## 12) RN implementation priority

1. **Home feed:** featured wholesalers + promo banners (`between_plumbers_stores`) + product collections  
2. **فواتيري** tabs for wholesale/retail + return actions respecting `can_return` / `available_actions`  
3. **Supply catalog:** factory products ≡ warehouse stock UI  
4. **Biometric** unlock after first password login  
5. Never display per-unit product points  

---

*End of changelog — keep this file as the mobile source of truth for this release batch.*
