# MG Plastic — Complete Mobile API Guide

دليل موحّد لمطوّر الموبايل: دخول، شبكة، منتجات، طلبيات، مرتجعات، إشعارات.

**Production:** `https://mg-plastic.com/api`  
**Local:** `http://127.0.0.1:8000/api`  
**OpenAPI:** `GET /docs/api`

```http
Authorization: Bearer {token}
Accept: application/json
Accept-Language: ar
Content-Type: application/json
```

Envelope:

```json
{ "status": true, "message": "…", "data": { } }
```

Error:

```json
{ "status": false, "message": "…", "errors": { } }
```

| Role | Login role value | Mobile prefix |
|------|------------------|---------------|
| سباك | `plumber` | `/api/v1/mobile/plumber` |
| قطاعي | `retail_trader` | `/api/v1/mobile/trader` |
| جملة | `wholesale_distributor` | `/api/v1/mobile/distributor` |

Wrong role → **403**.

**دليل التسليم للموبايل (مفضّل):** [MOBILE_API_FOR_APP.md](./MOBILE_API_FOR_APP.md)

Guides مفصّلة: [Orders](./MOBILE_NETWORK_ORDERS.md) · [Returns](./MOBILE_INVOICE_RETURNS.md) · [Plumber stock](./PLUMBER_SHOP_PRODUCTS.md) · [Support](./MOBILE_SUPPORT.md)

---

## 1. Auth (دخول وتسجيل)

### Login (كل الأدوار)

```http
POST /api/v1/auth/login
{ "phone": "0912…", "password": "******" }
```

Success → `token` (Sanctum). إذا الهاتف غير موثّق → OTP و `token` = null.

### Register

| Who | Endpoint |
|-----|----------|
| قطاعي | `POST /api/v1/auth/register-retail` (alias: `register-trader`) |
| سباك | `POST /api/v1/auth/register-plumber` |
| جملة | **Login فقط** — الحساب من الأدمن (لا تستخدم `register-store` في التطبيق) |

ثم: `POST /api/v1/auth/verify-otp` · `resend-otp` · `forgot-password/*`

### Session

| Method | Path |
|--------|------|
| GET | `/api/v1/mobile/me` |
| POST | `/api/v1/mobile/logout` |
| POST | `/api/v1/mobile/logout-all` |
| GET/PUT | `/api/v1/mobile/profile` |
| GET | `/api/v1/mobile/settings` |

---

## 2. Product kinds (مهم للتطبيق)

| `product_kind` | `is_mg` | `has_points` | الاستخدام |
|----------------|---------|--------------|-----------|
| `mg_points` | true | true | منتج MG بنقاط ولاء |
| `mg_no_points` | true | false | منتج MG **يُطلب بدون نقاط** |
| `shop` | false | false | منتج المحل (`my_product`) للعرض |

- في الكتالوج الشبكي ومورد الطلب: `points_per_unit` دائماً `null` — اعرض `has_points` / إجمالي النقاط على الطلب فقط.
- استثناء: معاينة المرتجع + مخزون POS + `orders/{id}/stock` ترجع رقماً تشغيلياً.
- فلتر: `?has_points=1` أو `0`.
- مخزون جملة مرتبط للقطاعي: `GET /trader/wholesalers/{id}/stock` (`points_per_unit: null` + `available_qty`).

---

## 3. Retail trader (قطاعي)

Prefix: `/api/v1/mobile/trader` — Bearer + role `retail_trader`

### تدفق التطبيق

1. Login / register-retail  
2. عرض محلات الجملة: `GET /api/v1/network-stores`  
3. مخزون جملة مرتبط: `GET /trader/wholesalers/{id}/stock`  
4. كتالوج MG للطلب: `GET /trader/products`  
5. منتجاته للعرض (مش MG): `GET /trader/my-products`  
6. طلب من جملة: `POST /trader/orders`  
7. سباكين شبكته: `GET/POST /trader/plumbers`  
8. تنفيذ طلبات السباكين (`stock` / `items` / `apply-stock` / `fulfill`) + مرتجعات (`distributions`/`invoices` قوائم) + إشعارات  

### منتجات

| Method | Path | Notes |
|--------|------|-------|
| GET | `/products` | كتالوج MG للطلب من الجملة |
| GET/POST/PUT/DELETE | `/my-products` | منتجات المحل للعرض بدون نقاط |
| GET | `/api/v1/my-store/my-products` | نفس my-products (مسار قديم) |
| GET | `/api/v1/network-stores/{id}` | محل جملة/قطاعي + `my_products` للغير |

### شبكة السباكين

| Method | Path |
|--------|------|
| GET | `/plumbers?search=&page=` |
| POST | `/plumbers/link` `{ "code" }` أو `{ "phone" }` |
| GET | `/plumbers/{id}` |

### طلبيات

| Method | Path | Notes |
|--------|------|-------|
| GET | `/orders?scope=to_wholesaler\|from_plumbers` | |
| POST | `/orders` | إلى الجملة — يحتاج `supplier_id` + `lines` |
| GET | `/orders/{id}` | فيه `available_actions` |
| POST | `/orders/{id}/receive` · `/cancel` | كمشتري من الجملة |
| POST | `/orders/{id}/confirm\|ship\|reject` | لطلبات السباكين |
| GET | `/orders/{id}/stock` | فحص مخزون طلب سباك |
| PUT | `/orders/{id}/items` | `{ lines:[{product_id,quantity}] }` |
| POST | `/orders/{id}/apply-stock` | لا body |
| POST | `/orders/{id}/fulfill` | `{ note? }` |

```json
POST /orders
{
  "supplier_id": 15,
  "lines": [{ "product_id": 42, "quantity": 10 }],
  "note": "…"
}
```

تفاصيل bodies: [MOBILE_NETWORK_ORDERS.md — Flow D](./MOBILE_NETWORK_ORDERS.md)

### مخزون / POS

| Method | Path |
|--------|------|
| GET | `/wholesalers/{id}/stock` | مخزون الجملة المرتبطة |
| GET | `/pos/stock` | مخزونك |
| POST | `/pos/checkout` | `{ plumber_id, lines[] }` |

### مرتجعات

| Method | Path |
|--------|------|
| GET | `/distributions` | قائمة اختيار tier 3 |
| GET | `/invoices` | قائمة اختيار tier 2 |
| GET | `/returns` |
| GET/POST | `/distributions/{id}/return-preview` · `/returns` | قطاعي→سباك |
| GET/POST | `/invoices/{id}/return-preview` · `/returns` | شراء من الجملة |

**قاعدة:** مفيش مرتجع بعد صرف نقاط السباك من نفس التوزيع.

### إشعارات

`/trader/notifications…` أو `/mobile/notifications…`  
فلتر: `?order_id=` · `entity_type=order` · `filter=unread`

---

## 4. Wholesale distributor (جملة)

Prefix: `/api/v1/mobile/distributor` — Bearer + role `wholesale_distributor`

### تدفق التطبيق

1. **Login فقط** (حساب الجملة من الأدمن — لا `register-store` في التطبيق)  
2. إضافة قطاعي لشبكته (تسجيل جديد أو ربط برقم موحّد)  
3. إضافة سباكين **تحت** تاجر قطاعي مرتبط (مش ربط مباشر جملة↔سباك)  
4. كتالوج + طلب من المصنع  
5. استقبال طلبات القطاعي: تأكيد / شحن / **تم التسليم**  
6. منتجات العرض + POS + مرتجعات  

### شبكة القطاعي

| Method | Path | Body / notes |
|--------|------|----------------|
| GET | `/retail-traders` | قائمة الشبكة |
| POST | `/retail-traders` | **تسجيل قطاعي جديد + ربط** |
| POST | `/retail-traders/link` | ربط مسجّل: `network_code` أو `phone` |
| GET | `/retail-traders/{id}` | |
| DELETE | `/retail-traders/{id}` | فك الارتباط |

```json
POST /retail-traders
{
  "name": "محل النور",
  "phone": "0917…",
  "password": "optional",
  "brand_name": "النور"
}
```

```json
POST /retail-traders/link
{ "network_code": "MG-R-…" }
```

### شبكة السباكين (عبر القطاعي)

السباك يرتبط بـ **تاجر قطاعي** فقط. الجملة يضيفه باختيار `retail_trader_id` من شبكته.

| Method | Path | Body |
|--------|------|------|
| GET | `/plumbers?retail_trader_id=` | كل سباكين شبكة القطاعيين (أو محل واحد) |
| POST | `/plumbers` | تسجيل سباك جديد تحت قطاعي |
| POST | `/plumbers/link` | ربط سباك موجود |
| GET | `/plumbers/{id}` | |

```json
POST /plumbers/link
{
  "retail_trader_id": 12,
  "network_code": "MG-P-…"
}
```

```json
POST /plumbers
{
  "retail_trader_id": 12,
  "name": "أحمد",
  "phone": "0919…"
}
```

### منتجات

| Method | Path |
|--------|------|
| GET | `/products` | كتالوج MG لطلب المصنع |
| GET/POST/PUT/DELETE | `/my-products` | منتجات العرض بدون نقاط |

### طلبيات

| Method | Path | Notes |
|--------|------|-------|
| GET | `/orders?scope=to_factory\|from_retail\|needs_action` | |
| POST | `/orders` | طلب للمصنع — `lines` فقط |
| GET | `/orders/{id}` | |
| POST | `/orders/{id}/confirm\|ship\|deliver\|reject` | كمورّد لطلبات القطاعي |
| POST | `/orders/{id}/receive\|cancel` | كمشتري من المصنع |

```json
POST /orders
{
  "lines": [{ "product_id": 42, "quantity": 50 }],
  "note": "توريد"
}
```

### POS / مرتجعات / إشعارات

| Area | Paths |
|------|-------|
| POS | `GET/POST /pos/stock` · `/pos/checkout` |
| Returns | `GET /invoices` · `GET/POST /invoices/{id}/return-preview|/returns` · `GET /returns` |
| Notifications | `/distributor/notifications…` |

---

## 5. Plumber (سباك) — مختصر

Prefix: `/api/v1/mobile/plumber`

| Area | Paths |
|------|-------|
| Dashboard / wallet | `/dashboard` · `/wallet` · `/wallet/convert` · `/withdrawals` |
| Shops | `GET /api/v1/network-stores` → retail shops |
| Products | `GET /products?retail_trader_id=` (مخزون المحل) |
| Orders | `GET/POST /orders` · `receive` · `cancel` |
| Returns | `GET /distributions` · `…/return-preview` · `POST …/returns` · `GET /returns` |
| Notifications | `/plumber/notifications…` |

Full stock rules: [PLUMBER_SHOP_PRODUCTS.md](./PLUMBER_SHOP_PRODUCTS.md)

---

## 6. Network stores & my-store

| Method | Path | Who sees what |
|--------|------|----------------|
| GET | `/api/v1/network-stores` | Bearer قطاعي → محلات **جملة** · سباك/ضيف → محلات **قطاعي** |
| GET | `/api/v1/network-stores/{id\|code}` | تفاصيل + `my_products` |
| GET/PUT | `/api/v1/my-store` | بروفايل المحل (جملة/قطاعي) |
| * | `/api/v1/my-store/media\|slider\|my-products\|social-links` | وسائط ومنتجات عرض |

المصنع **مش** محل في الدليل — طلب الجملة للمصنع بدون `supplier_id`.

---

## 7. Orders status & actions

```
placed → confirmed → shipping → delivered
         ↘ reject / cancel (مبكراً فقط)
```

كل طلب يرجع `available_actions[]` — ابنِ الأزرار عليها:

`confirm` · `ship` · `deliver` · `receive` · `cancel` · `reject` · `fulfill` · `update_items` · `apply_stock`

تفاصيل: [MOBILE_NETWORK_ORDERS.md](./MOBILE_NETWORK_ORDERS.md)

---

## 8. Notifications

Shared: `/api/v1/mobile/notifications`

| Query | Meaning |
|-------|---------|
| `filter=unread` | غير مقروء |
| `order_id=` | إشعارات طلب معيّن |
| `entity_type=order` | طلبيات فقط |

Fields: `order_id`, `order_number`, `channel`, `order_status`, `title`, `body`, `is_read`

---

## 9. Returns rules (مهم)

| Hop | API |
|-----|-----|
| جملة→قطاعي | Distributor/Trader `invoices/{id}/returns` |
| قطاعي→سباك | Trader / Plumber `distributions/{id}/returns` |

- بعد تحويل/صرف نقاط السباك من نفس التوزيع → **422** برسالة عربية واضحة  
- مرتجع المصنع غير مدعوم من هذا المسار  

تفاصيل: [MOBILE_INVOICE_RETURNS.md](./MOBILE_INVOICE_RETURNS.md)

---

## 10. Quick cheat sheet

### Auth
`POST /auth/login` · `register-retail` · `register-plumber` · `verify-otp`  
(جملة = login فقط)

### Trader
`/trader/products` · `/trader/wholesalers/{id}/stock` · `/trader/my-products` · `/trader/orders*` · `/trader/plumbers*` · `/trader/pos*` · `/trader/distributions` · `/trader/invoices` · `/trader/returns*` · `/trader/notifications*`

### Distributor
`/distributor/products` · `/distributor/my-products` · `/distributor/retail-traders*` · `/distributor/plumbers*` · `/distributor/orders*` · `/distributor/pos*` · `/distributor/invoices` · `/distributor/returns*` · `/distributor/notifications*`

### Shared
`/mobile/me` · `/mobile/profile` · `/mobile/settings` · `/mobile/notifications*` · `/network-stores` · `/my-store*`

---

## 11. Domain notes for app UX

1. **النقاط** تظهر كإجمالي على الطلب/الفاتورة — مش سعر نقطة/وحدة في الكتالوج.  
2. **منتجات المحل (`shop`)** للعرض في البروفايل؛ طلب الشبكة يستخدم كتالوج MG (`mg_points` / `mg_no_points`).  
3. **الجملة ما بيربطش سباك مباشرة** — يختار قطاعي من شبكته ثم يضيف السباك تحته.  
4. **الإشعارات** تطلع مع تغيّر حالة الطلب؛ افتح الطلب بـ `order_id`.  
5. استخدم `available_actions` و`can_return` من الـ API بدل state machine محلي.
