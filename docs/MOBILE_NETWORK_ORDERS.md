# Mobile — Network orders & notifications

API guide for **retail trader (قطاعي)** and **wholesale distributor (جملة)** apps.

**Production base:** `https://mg-plastic.com/api`  
**Local base:** `http://127.0.0.1:8000/api`  
**OpenAPI:** `GET /docs/api`

Auth on every call below:

```http
Authorization: Bearer {token}
Accept: application/json
Accept-Language: ar
Content-Type: application/json
```

| App role | Login | Middleware role |
|----------|-------|-----------------|
| قطاعي | `POST /api/v1/auth/login` | `retail_trader` |
| جملة | `POST /api/v1/auth/login` | `wholesale_distributor` |

Wrong role → **403**:

```json
{
  "status": false,
  "message": "غير مصرح — الدور غير مناسب لهذا المسار"
}
```

Envelope on success:

```json
{
  "status": true,
  "message": "…",
  "data": { }
}
```

`points_per_unit` في **الكتالوج وموارد الطلبات** دائماً `null` — اعرض إجمالي النقاط على الطلب/السطر فقط.  
استثناءات تشغيلية (رقم حقيقي): معاينة المرتجع، مخزون POS الخاص، وفحص مخزون طلب السباك.

Related: [Plumber shop products](./PLUMBER_SHOP_PRODUCTS.md) · [Invoice returns](./MOBILE_INVOICE_RETURNS.md) · [Mobile handoff](./MOBILE_API_FOR_APP.md)

---

## Channels

| `channel` | Who places | Who supplies |
|-----------|------------|--------------|
| `wholesale_to_retail` | قطاعي | جملة |
| `factory_to_wholesale` | جملة | المصنع (admin) |
| `retail_to_plumber` | سباك | قطاعي (see plumber doc) |

## Status flow

```
placed → confirmed → shipping → delivered
              ↘ reject / cancel (early only)
```

| Status | Label (ar) |
|--------|------------|
| `placed` | بانتظار التأكيد |
| `confirmed` | تم التأكيد |
| `shipping` | في الطريق |
| `delivered` | تم التسليم |
| `cancelled` | ملغي |
| `rejected` | مرفوض |

Every order payload includes:

- `timeline[]` — progress steps  
- `available_actions[]` — **use these for CTA buttons** (do not hardcode by status alone)

Possible action names:

| Action | Who | Endpoint suffix |
|--------|-----|-----------------|
| `confirm` | supplier | `POST …/confirm` |
| `ship` | supplier | `POST …/ship` |
| `receive` | **buyer only** | `POST …/receive` — تأكيد الاستلام من المستلم |
| `deliver` | ❌ removed | المورّد لا يؤكّد الاستلام؛ استخدم `receive` |
| `deliver` | supplier (جملة only) | `POST …/deliver` |
| `reject` | supplier | `POST …/reject` |
| `receive` | buyer | `POST …/receive` |
| `cancel` | buyer | `POST …/cancel` |
| `fulfill` | قطاعي on plumber orders | `POST …/fulfill` |
| `update_items` / `apply_stock` | قطاعي on plumber orders | see trader routes |

---

## App flow A — قطاعي يطلب من الجملة

1. List **linked** wholesalers (multi-network) — or link a new one:

```http
GET  /api/v1/mobile/trader/wholesalers
POST /api/v1/mobile/trader/wholesalers/link   { "code": "MG-W-…" }
```

Directory of all public shops (optional discovery):

```http
GET /api/v1/network-stores
```

→ Use shop `id` as `supplier_id`. القطاعي يتعامل مع أكثر من جملة في نفس الوقت.

2. Optional — مخزون الجملة المتاح لهذا الموزّع (مرتبط فقط):

```http
GET /api/v1/mobile/trader/wholesalers/{wholesaler_id}/stock
```

`points_per_unit` = `null` · استخدم `has_points` + `available_qty` / `available_quantity`.

3. Catalog (كل منتجات MG — بدون كمية مخزون جملة):

```http
GET /api/v1/mobile/trader/products?page=1&per_page=20&search=
```

4. Place order:

```http
POST /api/v1/mobile/trader/orders
```

```json
{
  "supplier_id": 15,
  "lines": [
    { "product_id": 42, "quantity": 10 },
    { "product_id": 7, "quantity": 3 }
  ],
  "note": "توصيل صباحاً إن أمكن"
}
```

Alias: `wholesale_distributor_id` works instead of `supplier_id`.

5. Track / act:

| Call | Meaning |
|------|---------|
| `GET /api/v1/mobile/trader/orders?scope=to_wholesaler` | My supply orders |
| `GET /api/v1/mobile/trader/orders/{id}` | Detail + `available_actions` |
| `POST …/receive` | Confirm receipt → stock + points |
| `POST …/cancel` | Cancel while `placed` / `confirmed` |

---

## App flow D — قطاعي ينفّذ طلب سباك من مخزونه

فقط على `channel = retail_to_plumber` و`available_actions` تحتوي الإجراء.

### 1) فحص المخزون مقابل بنود الطلب

```http
GET /api/v1/mobile/trader/orders/{id}/stock
```

لا body. الاستجابة:

```json
{
  "order_id": 55,
  "lines": [
    {
      "product_id": 42,
      "name": "ماسورة",
      "requested_qty": 10,
      "available_qty": 7,
      "fulfillable_qty": 7,
      "is_available": false,
      "points_per_unit": 10
    }
  ]
}
```

هنا `points_per_unit` رقم تشغيلي (ليس كتالوج).

### 2) تعديل أصناف الطلب (قبل التنفيذ)

```http
PUT /api/v1/mobile/trader/orders/{id}/items
```

```json
{
  "lines": [
    { "product_id": 42, "quantity": 7 },
    { "product_id": 9, "quantity": 2 }
  ]
}
```

مسموح فقط والحالة `placed` أو `confirmed`.

### 3) قص الكميات للمتوفر تلقائياً

```http
POST /api/v1/mobile/trader/orders/{id}/apply-stock
```

لا body — يعدّل الكميات إلى `fulfillable_qty` ويحذف البنود بصفر.

### 4) تنفيذ كامل من المخزون → فاتورة + تسليم

```http
POST /api/v1/mobile/trader/orders/{id}/fulfill
```

```json
{ "note": "تم التنفيذ من المخزون" }
```

`note` اختياري. يفشل 422 لو أي بند `is_available=false`.  
عند النجاح: تأكيد → شحن → توزيع tier 3 → `delivered` + منح نقاط السباك.

بديل متدرج بدون fulfill: `confirm` → `ship` ثم السباك `receive`.

---

## App flow B — جملة يرد على طلبات القطاعي

1. Incoming list:

```http
GET /api/v1/mobile/distributor/orders?scope=from_retail
```

Aliases: `scope=incoming` · `scope=needs_action` (open only).

2. Act as supplier (when listed in `available_actions`):

```http
POST /api/v1/mobile/distributor/orders/{id}/confirm
{ "note": "تم القبول" }

POST /api/v1/mobile/distributor/orders/{id}/ship
{
  "carrier_name": "مندوبنا",
  "tracking_number": "optional",
  "expected_delivery_at": "2026-10-20"
}

POST /api/v1/mobile/distributor/orders/{id}/deliver
```

`deliver` = wholesaler marks hand-over (stock moves to retail). Buyer can still use `receive` if the wholesaler did not deliver yet.

```http
POST /api/v1/mobile/distributor/orders/{id}/reject
{ "reason": "غير متوفر حالياً" }
```

---

## App flow C — جملة يطلب من المصنع

1. Catalog:

```http
GET /api/v1/mobile/distributor/products?page=1&per_page=20
```

2. Place (no supplier id — factory is implied):

```http
POST /api/v1/mobile/distributor/orders
```

```json
{
  "lines": [
    { "product_id": 42, "boxes": 3 },
    { "product_id": 7, "quantity": 50 }
  ],
  "note": "طلب توريد"
}
```

- كتالوج المنتجات يعيد `pieces_per_box` و `order_unit` (`box` | `piece`).
- لو `order_unit=box`: أرسل **`boxes`** (يُحوَّل داخلياً إلى قطع = `boxes × pieces_per_box`).
- لو `order_unit=piece`: أرسل **`quantity`** بالقطع.
- لو أرسلت `quantity` لمنتج له صندوق: لازم تكون من مضاعفات `pieces_per_box`.

3. Track as buyer:

| Call | Meaning |
|------|---------|
| `GET …/distributor/orders?scope=to_factory` | My factory orders (`scope=placed` same) |
| `POST …/receive` | Confirm receipt after factory ships/confirms |
| `POST …/cancel` | Cancel early |

Factory confirm/ship is done by **admin panel**, not this mobile role.

---

## Order object (mobile)

```json
{
  "id": 120,
  "order_number": "ORD-20261015-0012",
  "channel": "wholesale_to_retail",
  "channel_label": "من موزع الجملة للتاجر القطاعي",
  "channel_color": "warning",
  "status": "confirmed",
  "status_label": "تم التأكيد",
  "status_description": "وافق المورّد على الطلب ويجري تجهيزه.",
  "status_color": "info",
  "is_open": true,
  "timeline": [
    { "status": "placed", "label": "بانتظار التأكيد", "reached": true, "current": false },
    { "status": "confirmed", "label": "تم التأكيد", "reached": true, "current": true },
    { "status": "shipping", "label": "في الطريق", "reached": false, "current": false },
    { "status": "delivered", "label": "تم التسليم", "reached": false, "current": false }
  ],
  "available_actions": ["ship", "deliver", "reject"],
  "requester": { "id": 90, "name": "محل النور", "brand_name": "النور" },
  "supplier": { "id": 15, "name": "موزع طرابلس", "brand_name": "MG Tripoli" },
  "total_quantity": 13,
  "total_points": 26,
  "carrier_name": null,
  "tracking_number": null,
  "expected_delivery_at": null,
  "note": "توصيل صباحاً",
  "supplier_note": "تم القبول",
  "placed_at": "2026-10-15T12:00:00+00:00",
  "confirmed_at": "2026-10-15T12:05:00+00:00",
  "shipped_at": null,
  "delivered_at": null,
  "items": [
    {
      "product_id": 42,
      "name": "ماسورة 20",
      "quantity": 10,
      "points_per_unit": null,
      "line_points": 20
    }
  ],
  "created_at": "2026-10-15T12:00:00+00:00"
}
```

List endpoints return an **array** in `data` (not paginated). Filter with:

| Query | Values |
|-------|--------|
| `scope` | see tables above |
| `status` | `placed`, `confirmed`, … |
| `channel` | optional channel slug |

---

## Notifications

Same inbox for all roles. Prefer the shared path (works for جملة too):

```http
GET  /api/v1/mobile/notifications
GET  /api/v1/mobile/notifications/summary
GET  /api/v1/mobile/notifications/unread-count
POST /api/v1/mobile/notifications/read-all
POST /api/v1/mobile/notifications/{id}/read
POST /api/v1/mobile/notifications/{id}/unread
DELETE /api/v1/mobile/notifications/{id}
```

Aliases (same controller):

- `/api/v1/mobile/trader/notifications…`
- `/api/v1/mobile/distributor/notifications…`
- `/api/v1/mobile/plumber/notifications…`

### Query filters

| Query | Meaning |
|-------|---------|
| `filter=unread` or `unread_only=1` | Unread only |
| `filter=read` or `read_only=1` | Read only |
| `order_id=120` | Notifications for one order |
| `entity_type=order` | Order-related only |
| `status=success\|info\|warning\|danger` | Filament status color |
| `q` / `search` | Search title/body |
| `per_page` | Default `20`, max `100` |

### Notification item

```json
{
  "id": "9f3c…-uuid",
  "title": "تم تأكيد طلبك ✓",
  "body": "طلب رقم ORD-… تم تأكيده ويجري تجهيزه.",
  "status": "success",
  "status_color": "success",
  "entity_type": "order",
  "order_id": 120,
  "order_number": "ORD-20261015-0012",
  "channel": "wholesale_to_retail",
  "order_status": "confirmed",
  "is_read": false,
  "read_at": null,
  "created_at": "2026-10-15T12:05:00+00:00",
  "created_at_human": "قبل دقيقتين",
  "actions": [],
  "data": { }
}
```

**Deep link:** if `entity_type === "order"` and `order_id` is set → open order detail for the current role.

### When notifications fire

| Event | DB notify | WhatsApp |
|-------|-----------|----------|
| Order placed | Supplier (or admins if factory) | Customer + supplier arrived |
| Confirmed / shipped / rejected / delivered | Requester (+ supplier on deliver) | Requester |
| Cancelled by buyer | Supplier / admins | Supplier |

---

## Route cheat sheet

### Retail trader — `/api/v1/mobile/trader`

| Method | Path | Notes |
|--------|------|-------|
| GET | `/products` | Catalog for supply order |
| GET | `/orders` | `scope=to_wholesaler` \| `from_plumbers` \| `placed` \| `incoming` |
| POST | `/orders` | To wholesaler |
| GET | `/orders/{id}` | |
| POST | `/orders/{id}/receive` | Buyer |
| POST | `/orders/{id}/cancel` | Buyer |
| POST | `/orders/{id}/confirm\|ship\|reject\|fulfill` | Plumber channel only |
| PUT | `/orders/{id}/items` | Plumber channel |
| GET | `/orders/{id}/stock` | Plumber channel |
| POST | `/orders/{id}/apply-stock` | Plumber channel |

### Wholesale — `/api/v1/mobile/distributor`

| Method | Path | Notes |
|--------|------|-------|
| GET | `/products` | Catalog for factory order |
| GET | `/orders` | `scope=to_factory` \| `from_retail` \| `needs_action` \| `placed` \| `incoming` |
| POST | `/orders` | To factory |
| GET | `/orders/{id}` | |
| POST | `/orders/{id}/confirm` | Supplier on retail orders |
| POST | `/orders/{id}/ship` | Supplier |
| POST | `/orders/{id}/deliver` | Supplier hand-over |
| POST | `/orders/{id}/reject` | Supplier |
| POST | `/orders/{id}/receive` | Buyer of factory order |
| POST | `/orders/{id}/cancel` | Buyer of factory order |

### Stores directory

| Viewer | `GET /api/v1/network-stores` lists |
|--------|-------------------------------------|
| `retail_trader` + Bearer | wholesale shops |
| plumber / guest | retail shops |

Factory is **not** in this directory — wholesaler posts factory orders with no supplier id.

---

## UX tips

1. Drive buttons from `available_actions`, not from a local state machine.
2. After every transition, refresh the order (or use the returned `data`).
3. Poll `GET /notifications/unread-count` or listen after each order action.
4. On notification tap: `order_id` → `GET …/orders/{order_id}` for the logged-in role.
5. Do not show per-unit points; show `line_points` / `total_points` only.

---

## Errors

| HTTP | When |
|------|------|
| 401 | Missing / invalid token |
| 403 | Wrong role or not a party on the order |
| 422 | Validation or domain rule (`status: false`, optional `errors`) |

Example:

```json
{
  "status": false,
  "message": "لا يمكن التسليم قبل تأكيد أو شحن الطلب"
}
```
