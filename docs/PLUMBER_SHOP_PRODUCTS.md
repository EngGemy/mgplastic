# Plumber supply order — shop stock

The plumber picks a shop, then sees only the products that shop has in stock.

**Choice:** Option A. Same endpoint, new query filter. No new path.

**Production base:** `https://mg-plastic.com/api`  
**Local base:** `http://127.0.0.1:8000/api`

Auth on every call below:

```http
Authorization: Bearer {plumber_token}
Accept: application/json
Accept-Language: ar
```

Role must be `plumber`. Any other role gets **403** and:

```json
{
  "status": false,
  "message": "غير مصرح — الدور غير مناسب لهذا المسار"
}
```

`name`, `description`, and `category_name` follow `Accept-Language` (`ar` or `en`).

---

## App flow

1. Plumber picks a shop from `GET /api/v1/network-stores`. Use that store’s `id` as `retail_trader_id`.
2. Load products with that id:

```http
GET /api/v1/mobile/plumber/products?retail_trader_id={id}&page=1&search=&category_id=&tag=
```

3. Let the plumber set a quantity up to `available_quantity`.
4. Submit the same shop id on the order:

```http
POST /api/v1/mobile/plumber/orders
```

Do not call the product list without `retail_trader_id` on the new-order screen. Without it the API still returns the full MG catalog, and `available_quantity` is omitted.

---

## 1. Products for the selected shop

```http
GET /api/v1/mobile/plumber/products?retail_trader_id=92&page=1&per_page=20
```

### Query

| Query | Required | Meaning |
|-------|----------|---------|
| `retail_trader_id` | yes on the order screen | Shop user id. Only products with available quantity greater than 0 |
| `page` | no | Page number. Default `1` |
| `per_page` | no | Page size. Default `20`, max `100` |
| `search` or `q` | no | Name, description, category, or tag contains this text |
| `category_id` | no | Product category id |
| `tag` or `tags` | no | Tag name. Several tags: comma, Arabic comma `،`, or repeated `tags[]`. Any match is enough |
| `has_points` | no | `1` only products with points. `0` only products without points |

### Success

Same wrapper as today. Each item keeps the old fields and adds `available_quantity` (integer). Category stays flat: `category_id` and `category_name`. There is no nested `category` object. `points_per_unit` is always `null` for network apps (plumber / trader) — show order/invoice totals only; the per-unit rate is admin-only.

```json
{
  "status": true,
  "message": "كتالوج منتجات MG",
  "data": {
    "items": [
      {
        "id": 12,
        "name": "ماسورة 160 بوصة خط ذهبي MG",
        "description": "",
        "image": "products/pipe.jpg",
        "image_url": "https://mg-plastic.com/storage/products/pipe.jpg",
        "points_per_unit": null,
        "has_points": true,
        "category_id": 3,
        "category_name": "مواسير الذهبي",
        "available_quantity": 40
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 20,
      "total": 1
    }
  }
}
```

A shop with no stock returns `items: []` and `pagination.total: 0`. That is success, not an error.

### Shop errors

| Status | When | `message` |
|--------|------|-----------|
| 404 | Id is missing, or the user is not a retail trader | `المحل غير موجود` |
| 403 | Retail trader exists but is not approved or not active | `لا يمكنك الطلب من هذا المحل` |

```json
{
  "status": false,
  "message": "المحل غير موجود"
}
```

### Without `retail_trader_id`

Unchanged. Full MG catalog, same fields, no `available_quantity`. Other screens that already call this endpoint can stay as they are.

---

## 2. Place the order

```http
POST /api/v1/mobile/plumber/orders
Content-Type: application/json
```

```json
{
  "retail_trader_id": 92,
  "note": "",
  "lines": [
    { "product_id": 12, "quantity": 2 }
  ]
}
```

| Field | Required | Meaning |
|-------|----------|---------|
| `retail_trader_id` | yes | Same shop id used on the product list |
| `lines` | yes | At least one line |
| `lines.*.product_id` | yes | Product id from the shop list |
| `lines.*.quantity` | yes | Integer, minimum `1`, and not above `available_quantity` |
| `note` | no | Up to 2000 characters |

### Success — 201

```json
{
  "status": true,
  "message": "تم إرسال الطلب للتاجر القطاعي",
  "data": { }
}
```

`data` is the order resource (id, order number, status, lines).

### Stock errors — 422

The server checks every line against that shop’s stock before the order is saved. Show `message` to the plumber.

Quantity above stock:

```json
{
  "status": false,
  "message": "الكمية المطلوبة من «ماسورة 160 بوصة خط ذهبي MG» (50) تتجاوز المتوفر في المخزن (40)"
}
```

Product not in that shop’s stock:

```json
{
  "status": false,
  "message": "المنتج «ماسورة 160 بوصة خط ذهبي MG» غير متوفر في مخزونك"
}
```

Invalid body (`message` is generic, field errors are in `errors`):

```json
{
  "status": false,
  "message": "بيانات غير صالحة",
  "errors": {
    "retail_trader_id": ["The retail trader id field is required."]
  }
}
```

Cap the quantity stepper at `available_quantity`. The 422 is the backup if stock changed between the list and the submit.
