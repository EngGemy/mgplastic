# MG Plastic — دليل الموبايل الموحّد (Handoff)

ملف واحد لمطوّر التطبيق. إن تعارض أي مستند قديم مع هذا الملف — **اعتمد هذا**.

**تطبيق المحل (جملة/قطاعي):** كتالوج MG · اقتراحات بحث · معرض · منتجات عرض · لوحة نقاط → [MOBILE_STORE_APP.md](./MOBILE_STORE_APP.md)  
**مخزن الدعوة (لوحة ويب):** `/warehouse` → [WAREHOUSE_DAAWA.md](./WAREHOUSE_DAAWA.md)

| | |
|--|--|
| **Production** | `https://mg-plastic.com/api` |
| **Local** | `http://mgplastic.test/api` |
| **OpenAPI** | `GET /docs/api` |
| **تاريخ** | 2026-10-17 (rev 3 — متجر الموبايل) |

```http
Authorization: Bearer {token}
Accept: application/json
Accept-Language: ar
Content-Type: application/json
```

### Envelope العام

```json
{ "status": true, "message": "…", "data": { } }
```

```json
{ "status": false, "message": "…", "errors": { "field": ["رسالة عربية"] } }
```

**استثناء Login:** النجاح / انتظار OTP يعيد `token` و`user` في الجذر (ليس داخل `data`).

| تطبيق | `user.role` | Prefix |
|-------|-------------|--------|
| سباك | `plumber` | `/api/v1/mobile/plumber` |
| قطاعي | `retail_trader` | `/api/v1/mobile/trader` |
| جملة | `wholesale_distributor` | `/api/v1/mobile/distributor` |

دور خاطئ على المسار → **403** `غير مصرح — الدور غير مناسب لهذا المسار`.

---

## 1) قواعد ذهبية

1. أزرار الطلبيات من `available_actions[]` فقط — لا state machine محلي.
2. أزرار المرتجع من `can_return` + اعرض `block_reason` إن وُجد.
3. **الجملة = Login فقط.** لا شاشة تسجيل جملة. (`register-store` موجود في الـ backend — **لا تستخدمه في الموبايل.**)
4. الجملة لا يربط سباكاً مباشرة — يختار `retail_trader_id` ثم يضيف السباك تحته.
5. منتجات `shop` للعرض في بروفايل المحل فقط. الطلبات من كتالوج MG (`mg_points` / `mg_no_points`).
6. بعد صرف/تحويل نقاط السباك من نفس التوزيع → المرتجع مرفوض 422.

### `product_kind`

| قيمة | أين تظهر | المعنى |
|-------|----------|--------|
| `mg_points` | كتالوج `/products` | MG بنقاط |
| `mg_no_points` | كتالوج `/products` | MG يُطلب **بدون** نقاط |
| `shop` | `my-products` / وسائط المحل فقط | عرض محل — **لا يُطلب كنقاط** |

### `points_per_unit`

| السطح | القيمة |
|-------|--------|
| كتالوج `/products` · `OrderResource` · `GET /trader/wholesalers/{id}/stock` | دائماً **`null`** — استخدم `has_points` + إجمالي النقاط |
| معاينة مرتجع `return-preview` | **رقم** |
| `GET /pos/stock` · `GET orders/{id}/stock` | **رقم** تشغيلي |

---

## 2) Auth

| Method | Path | من؟ |
|--------|------|-----|
| POST | `/api/v1/auth/login` | **كل الأدوار** (بما فيها الجملة) |
| POST | `/api/v1/auth/login-plumber` | alias لـ login |
| POST | `/api/v1/auth/register-plumber` | سباك |
| POST | `/api/v1/auth/register-retail` | قطاعي (alias: `register-trader`) |
| POST | `/api/v1/auth/verify-otp` | throttle 6/د |
| POST | `/api/v1/auth/resend-otp` | throttle 3/د |
| POST | `/api/v1/auth/forgot-password/send-otp` | throttle 3/د |
| POST | `/api/v1/auth/forgot-password/verify-otp` | throttle 6/د |
| POST | `/api/v1/auth/forgot-password/reset` | |

### Login body

```json
{
  "login": "0912345678",
  "password": "…"
}
```

بديل: `phone` أو `email` بدل `login`.

### Login success 200

```json
{
  "status": true,
  "message": "…",
  "token": "1|…",
  "user": {
    "id": 1,
    "role": "retail_trader",
    "name": "…"
  }
}
```

السباك قد يحصل على `user.balance_points`.

### OTP pending 200

```json
{
  "status": true,
  "message": "…",
  "token": null,
  "user": { "…": "…" },
  "otp": 123456,
  "otp_expires_at": "2026-10-17T12:00:00+00:00"
}
```

`otp` يظهر في بيئة التطوير فقط — لا تعتمد عليه في الإنتاج.

### حالات Auth

| حالة | HTTP | ملاحظة |
|------|------|--------|
| بيانات خاطئة | **401** | `رقم الهاتف أو كلمة المرور غير صحيحة` |
| حساب معلّق / غير مصرّح | **403** | حسب الدور |
| محل شبكة معلّق/غير نشط | 200 + تحذير في `message` | قد يُسمح بالدخول |
| OTP تجاوز الحد | **429** | رسالة انتظار عربية |
| هاتف غير موثّق | **200** | `token=null` + OTP |

### Session / بروفايل / إعدادات

| Method | Path |
|--------|------|
| GET | `/api/v1/mobile/me` |
| POST | `/api/v1/mobile/logout` · `/logout-all` |
| GET / PUT\|PATCH\|POST | `/api/v1/mobile/profile` |
| GET | `/api/v1/mobile/settings` |
| GET | `/api/v1/mobile/wallet-visibility` | → `{ "show_wallet": true\|false }` |

---

## 3) إشعارات

`/api/v1/mobile/notifications`  
أو تحت `/plumber|/trader|/distributor/notifications` (نفس الشكل).

| Method | Path |
|--------|------|
| GET | `/` · `/summary` · `/unread-count` · `/{id}` |
| POST | `/read-all` · `/read-many` · `/{id}/read` · `/{id}/unread` · `/delete-many` |
| DELETE | `/` · `/read` · `/{id}` |

Query: `?filter=unread` · `?order_id=` · `?entity_type=order`  
افتح الطلب من الحقل `order_id` في الـ payload.

---

## 4) شبكة المحلات

| Method | Path | من يرى ماذا |
|--------|------|-------------|
| GET | `/api/v1/network-stores` | قطاعي ← محلات جملة · سباك/ضيف ← محلات قطاعي |
| GET | `/api/v1/vendors` | alias لنفس القائمة |
| GET | `/api/v1/network-stores/{id\|code}` | تفاصيل + `my_products` |
| * | `/api/v1/my-store…` | بروفايل/وسائط/سلايدر/منتجات عرض (جملة/قطاعي) |

### `my-store` (مصادقة)

| Method | Path |
|--------|------|
| GET / PUT\|PATCH | `/api/v1/my-store` |
| GET/POST | `/api/v1/my-store/media` |
| DELETE | `/api/v1/my-store/media/{mediaId}` |
| POST | `/api/v1/my-store/social-links` |
| DELETE | `/api/v1/my-store/social-links/{linkId}` |
| GET/POST | `/api/v1/my-store/slider` |
| DELETE | `/api/v1/my-store/slider/{mediaId}` |
| PUT | `/api/v1/my-store/slider/order` |
| GET/POST | `/api/v1/my-store/my-products` |
| PUT\|PATCH\|POST / DELETE | `/api/v1/my-store/my-products/{mediaId}` |

المصنع ليس محلّاً — طلب الجملة للمصنع بدون `supplier_id`.

---

## 5) سباك — `/api/v1/mobile/plumber`

| Area | Paths |
|------|-------|
| Dashboard | `GET /dashboard` |
| Wallet | `GET /wallet` · `/wallet/transactions` · `/wallet/conversion-rules` · `POST /wallet/convert` |
| Withdrawals | `GET/POST /withdrawals` · `GET /withdrawals/{id}` · `/receipt` · `/receipt/download` |
| Products | `GET /products?retail_trader_id=&category_id=&q=&has_points=&per_page=` |
| Orders | `GET/POST /orders` · `GET /orders/{id}` · `POST …/receive` · `…/cancel` |
| Returns | انظر §8 |
| Notifications | `/notifications…` |
| Shops | `GET /api/v1/network-stores` |

```json
POST /orders
{
  "retail_trader_id": 12,
  "lines": [{ "product_id": 42, "quantity": 5 }],
  "note": "…"
}
```

`POST …/cancel` اختياري: `{ "reason": "…" }`.

### كتالوج المنتج (حقول مهمة)

`id`, `name`, `description`, `image`, `image_url`, `points_per_unit` (**null**), `has_points`, `product_kind`, `is_mg`, `awards_points`, `category_id`, `category_name`  
مع `retail_trader_id`: يضاف `available_quantity`.

---

## 6) قطاعي — `/api/v1/mobile/trader`

### تدفق الشاشات

1. Login أو `register-retail`
2. محلات الجملة → `GET /api/v1/network-stores`
3. مخزون جملة مرتبط → `GET /wholesalers/{id}/stock`
4. كتالوج → `GET /products`
5. طلب للجملة → `POST /orders`
6. سباكين → `/plumbers*`
7. POS / تنفيذ طلبات السباك / مرتجعات

| Method | Path |
|--------|------|
| GET | `/dashboard` |

### مخزون

| Method | Path | Notes |
|--------|------|-------|
| GET | `/wholesalers/{id}/stock` | مخزون الجملة المرتبطة — `points_per_unit: null` |
| GET | `/pos/stock` | مخزونك — `points_per_unit` رقمي |
| POST | `/pos/checkout` | `{ "plumber_id", "lines":[{product_id,quantity}] }` |

```json
GET /wholesalers/{id}/stock → data
{
  "wholesaler": { "id": 15, "name": "…", "phone": "…" },
  "items": [{
    "product_id": 42,
    "name": "ماسورة",
    "image": "…",
    "image_url": "…",
    "category_id": 1,
    "points_per_unit": null,
    "has_points": true,
    "available_qty": 100,
    "available_quantity": 100
  }]
}
```

```json
GET /pos/stock → item
{
  "product_id": 42,
  "name": "ماسورة",
  "image_url": "…",
  "category_id": 1,
  "points_per_unit": 10,
  "has_points": true,
  "available_qty": 7,
  "available_points": 70,
  "slots": []
}
```

### شبكة السباكين

| Method | Path | Body |
|--------|------|------|
| GET | `/plumbers?search=&page=` | |
| POST | `/plumbers/link` | `{ "network_code" }` أو `{ "code" }` أو `{ "phone" }` |
| GET | `/plumbers/{id}` | |

### منتجات

| Method | Path |
|--------|------|
| GET | `/products` | كتالوج MG — `points_per_unit: null` |
| GET/POST | `/my-products` | منتجات عرض (`product_kind: shop`) |
| PUT\|PATCH\|POST / DELETE | `/my-products/{mediaId}` | تحديث/حذف |

### طلبيات

| Method | Path | Notes |
|--------|------|-------|
| GET | `/orders?scope=to_wholesaler\|from_plumbers\|placed\|incoming` | `placed`=`to_wholesaler` · `incoming`=`from_plumbers` |
| POST | `/orders` | للجملة — `supplier_id` مُفضّل (أو مرتبط تلقائياً إن وُجد) |
| GET | `/orders/{id}` | + `available_actions` + `timeline` |
| POST | `/orders/{id}/receive` · `/cancel` | كمشتري من الجملة |
| POST | `/orders/{id}/confirm\|ship\|reject` | لطلبات السباكين |
| GET | `/orders/{id}/stock` | فحص مخزون طلب سباك |
| PUT | `/orders/{id}/items` | تعديل أصناف |
| POST | `/orders/{id}/apply-stock` | قص للمتوفر |
| POST | `/orders/{id}/fulfill` | تنفيذ كامل |

```json
POST /orders
{
  "supplier_id": 15,
  "lines": [{ "product_id": 42, "quantity": 10 }],
  "note": "توصيل صباحاً"
}
```

Alias: `wholesale_distributor_id` بدل `supplier_id`.  
إن لم يُرسل ولم يوجد جملة مرتبط → 422 `اختر موزّع الجملة (supplier_id)…`.

#### تنفيذ طلب سباك (من مخزونك)

فقط `channel=retail_to_plumber` وحسب `available_actions`.

```http
GET /orders/{id}/stock
```

```json
{
  "order_id": 55,
  "lines": [{
    "product_id": 42,
    "name": "ماسورة",
    "requested_qty": 10,
    "available_qty": 7,
    "fulfillable_qty": 7,
    "is_available": false,
    "points_per_unit": 10
  }]
}
```

```json
PUT /orders/{id}/items
{
  "lines": [{ "product_id": 42, "quantity": 7 }]
}
```

```http
POST /orders/{id}/apply-stock
```
لا body — يقص للكميات المتوفرة.

```json
POST /orders/{id}/fulfill
{ "note": "تم التنفيذ من المخزون" }
```

يفشل 422 لو أي بند غير متوفر بالكامل.  
نجاح → تأكيد + شحن + توزيع tier3 + `delivered` + نقاط السباك.

```json
POST /orders/{id}/ship
{
  "carrier_name": "مندوبنا",
  "tracking_number": "optional",
  "expected_delivery_at": "2026-10-20"
}

POST /orders/{id}/confirm
{ "note": "تم القبول" }

POST /orders/{id}/reject
{ "reason": "…" }

POST /orders/{id}/cancel
{ "reason": "…" }
```

بديل متدرج: `confirm` → `ship` ثم السباك يعمل `receive`.

---

## 7) جملة — `/api/v1/mobile/distributor`

### تدفق الشاشات

1. **Login فقط** (الحساب من الأدمن)
2. إضافة/ربط قطاعي
3. إضافة سباكين تحت قطاعي من الشبكة
4. كتالوج + طلب للمصنع
5. طلبات القطاعي: تأكيد / شحن / **تم التسليم**
6. POS + مرتجعات

| Method | Path |
|--------|------|
| GET | `/dashboard` |

### شبكة القطاعي

| Method | Path | Body |
|--------|------|------|
| GET | `/retail-traders` | |
| POST | `/retail-traders` | تسجيل قطاعي جديد + ربط |
| POST | `/retail-traders/link` | `{ "network_code" }` أو `{ "phone" }` (أو `code`) |
| GET | `/retail-traders/{id}` | |
| DELETE | `/retail-traders/{id}` | فك الارتباط |

```json
POST /retail-traders
{
  "name": "محل النور",
  "phone": "0917…",
  "password": "optional",
  "brand_name": "النور",
  "email": "optional",
  "address": "optional",
  "store_description": "optional",
  "short_description": "optional",
  "country_id": null,
  "city_id": null,
  "latitude": null,
  "longitude": null
}
```

### سباكين (عبر القطاعي فقط)

| Method | Path | Body |
|--------|------|------|
| GET | `/plumbers?retail_trader_id=&search=` | |
| POST | `/plumbers` | تسجيل تحت قطاعي |
| POST | `/plumbers/link` | ربط موجود |
| GET | `/plumbers/{id}` | |

```json
POST /plumbers
{
  "retail_trader_id": 12,
  "name": "أحمد",
  "phone": "0912…",
  "password": "optional",
  "country_id": null,
  "city_id": null
}
```

```json
POST /plumbers/link
{
  "retail_trader_id": 12,
  "network_code": "ABC123"
}
```

بديل للربط: `code` أو `phone` بدل `network_code`.

### منتجات / POS

| Method | Path |
|--------|------|
| GET | `/products` |
| GET/POST | `/my-products` |
| PUT\|PATCH\|POST / DELETE | `/my-products/{mediaId}` |
| GET | `/pos/stock` | صفوف مخزون خام (`points_per_unit` رقمي + `available_points` + `slots`) |
| POST | `/pos/checkout` | `{ "retail_trader_id", "lines":[{product_id,quantity}] }` |

### طلبيات

| Method | Path | Notes |
|--------|------|-------|
| GET | `/orders?scope=to_factory\|from_retail\|needs_action\|placed\|incoming` | |
| POST | `/orders` | للمصنع — `lines` فقط |
| GET | `/orders/{id}` | + `available_actions` |
| POST | `/orders/{id}/confirm\|ship\|deliver\|reject` | كمورّد لطلبات القطاعي |
| POST | `/orders/{id}/receive\|cancel` | كمشتري من المصنع |

```json
POST /orders
{
  "lines": [{ "product_id": 42, "quantity": 50 }],
  "note": "توريد"
}
```

```json
POST /orders/{id}/confirm
{ "note": "تم القبول" }

POST /orders/{id}/ship
{
  "carrier_name": "مندوبنا",
  "tracking_number": "optional",
  "expected_delivery_at": "2026-10-20"
}

POST /orders/{id}/deliver
```

`deliver` = تم التسليم من الجملة (ينقل المخزون للقطاعي) — **بدون body**.  
`reject` / `cancel`: `{ "reason": "…" }`.

---

## 8) المرتجعات (كل الأدوار)

### Body موحّد

```json
{
  "lines": [
    { "invoice_item_id": 12, "quantity": 2 }
  ],
  "note": "تالف"
}
```

### المسارات

#### سباك

```http
GET  /api/v1/mobile/plumber/distributions
GET  /api/v1/mobile/plumber/distributions/{id}/return-preview
POST /api/v1/mobile/plumber/distributions/{id}/returns
GET  /api/v1/mobile/plumber/returns
GET  /api/v1/mobile/plumber/returns/{id}
```

#### قطاعي

```http
GET  /api/v1/mobile/trader/distributions          ← مبيعات السباكين
GET  /api/v1/mobile/trader/invoices               ← شراء من الجملة
GET  /api/v1/mobile/trader/distributions/{id}/return-preview
POST /api/v1/mobile/trader/distributions/{id}/returns
GET  /api/v1/mobile/trader/invoices/{id}/return-preview
POST /api/v1/mobile/trader/invoices/{id}/returns
GET  /api/v1/mobile/trader/returns
GET  /api/v1/mobile/trader/returns/{id}
```

#### جملة

```http
GET  /api/v1/mobile/distributor/invoices
GET  /api/v1/mobile/distributor/invoices/{id}/return-preview
POST /api/v1/mobile/distributor/invoices/{id}/returns
GET  /api/v1/mobile/distributor/returns
GET  /api/v1/mobile/distributor/returns/{id}
```

### Flow الشاشة

1. قائمة (`/distributions` أو `/invoices`) → صف فيه `can_return` / `block_reason` / `returnable_quantity`
2. `return-preview` → عبّئ الكميات من `lines[].returnable`
3. `POST …/returns` → **201**

### صف القائمة (distributions / invoices)

**مهم:** استخدم الحقل `id` دائماً في مسار الـ preview/returns.

| قائمة | قيمة `id` | المسار |
|-------|-----------|--------|
| `/distributions` | = `distribution_id` | `…/distributions/{id}/return-preview` |
| `/invoices` | = `invoice_id` | `…/invoices/{id}/return-preview` |

```json
{
  "id": 5,
  "distribution_id": 5,
  "invoice_id": 9,
  "tier": 3,
  "status": "confirmed",
  "plumber": { "id": 3, "name": "…" },
  "sold_quantity": 5,
  "returned_quantity": 0,
  "returnable_quantity": 5,
  "returnable_points_preview": 50,
  "product_points_preview": 50,
  "affects_wallet_points": true,
  "can_return": true,
  "block_reason": null,
  "confirmed_at": "…",
  "created_at": "…"
}
```

صف فاتورة (tier 2): `id` = `invoice_id`، و`returnable_points_preview: 0` و`affects_wallet_points: false`  
(النقاط المحاسبية تبقى في `product_points_preview`).

### Preview shape

```json
{
  "id": 5,
  "distribution_id": 5,
  "tier": 3,
  "status": "confirmed",
  "lines": [{
    "invoice_item_id": 12,
    "product_id": 42,
    "product_name": "ماسورة",
    "sold": 5,
    "returned": 0,
    "returnable": 5,
    "points_per_unit": 10,
    "has_points": true
  }],
  "returnable_points_preview": 50,
  "product_points_preview": 50,
  "affects_wallet_points": true,
  "can_return": false,
  "block_reason": "لا يمكن عمل مرتجع بعد صرف النقاط — …"
}
```

### مورد المرتجع بعد الإنشاء (201)

`id`, `return_number`, `invoice_id`, `distribution_id`, `tier`, `tier_label`, `status`, `total_quantity`, `total_points` (0 لـ tier 2), `product_points`, `affects_wallet_points`, `note`, `from_user`, `to_user`, `items[]`, `confirmed_at`, `created_at`

### قواعد Edge

| حالة | نتيجة |
|------|--------|
| Tier 2 (جملة↔قطاعي) | لا يحرّك محفظة النقاط |
| Tier 3 (قطاعي↔سباك) | يخصم من السباك ويعيد **نفس** العدد للقطاعي |
| بعد صرف نقاط التوزيع | 422 |
| كمية أكبر من `returnable` | 422 باسم المنتج |
| مرتجع جزئي متكرر | مسموح حتى صفر |
| `mg_no_points` | مرتجع كمية فقط |
| طرف غريب | 403 |
| نجاح الإنشاء | **201** + `return_number` |

### رسائل 422 مهمة

| تحتوي | المعنى |
|-------|--------|
| `صرف النقاط` | أخفِ الزر / اعرض السبب |
| `تتجاوز المتاح للإرجاع` | صحّح من `returnable` |
| `بيانات المرتجع غير صالحة` | راجع `errors` |
| `غير مصرّح` | ليس طرفاً |

---

## 9) حالات الطلب والأزرار

```
placed → confirmed → shipping → delivered
         ↘ rejected / cancelled (مبكراً فقط)
```

| `channel` | من يطلب | من يورّد |
|-----------|---------|----------|
| `wholesale_to_retail` | قطاعي | جملة |
| `factory_to_wholesale` | جملة | المصنع |
| `retail_to_plumber` | سباك | قطاعي |

| Action | من | Endpoint | Body |
|--------|-----|----------|------|
| `confirm` | مورّد | `POST …/confirm` | `{ "note"? }` |
| `ship` | مورّد | `POST …/ship` | `{ "carrier_name"?, "tracking_number"?, "expected_delivery_at"? }` |
| `deliver` | جملة (مورّد) | `POST …/deliver` | — |
| `receive` | مشتري | `POST …/receive` | — |
| `cancel` | مشتري | `POST …/cancel` | `{ "reason"? }` |
| `reject` | مورّد | `POST …/reject` | `{ "reason"? }` |
| `fulfill` | قطاعي على طلب سباك | `POST …/fulfill` | `{ "note"? }` |
| `update_items` | قطاعي على طلب سباك | `PUT …/items` | `{ "lines":[{product_id,quantity}] }` |
| `apply_stock` | قطاعي على طلب سباك | `POST …/apply-stock` | — |

كل طلب فيه `timeline[]` + `available_actions[]` + `blocked_actions[]`.

- `timeline[].reached` = `true` لكل خطوة حتى الحالة الحالية (شاملاً إياها).
- لا يُدرج زر في `available_actions` إن كان سيفشل بسبب المخزون (مثل `receive`).
- السبب يظهر في `blocked_actions`: `[{ "action": "receive", "reason": "…" }]`.
- تأكيد/شحن يفحص المخزون؛ إن نقص → 422.
- طلب في `shipping` بدون مخزون → يظهر `cancel` للمشتري و`reject` للمورّد.

### حقول OrderResource (مختصر)

`id`, `order_number`, `channel`, `channel_label`, `status`, `status_label`, `status_description`, `is_open`, `timeline[]`, `available_actions[]`, `blocked_actions[]`, `requester`, `supplier`, `total_quantity`, `total_points`, `carrier_name`, `tracking_number`, `expected_delivery_at`, `note`, `supplier_note`, `items[]` (`product_id`, `name`, `quantity`, `points_per_unit: null`, `line_points`)

---

## 10) إجابات فريق الموبايل (Q&A)

### 5) هل يطلب السباك من أي محل قطاعي؟

**نعم — السباك يتعامل مع أكثر من محل قطاعي.**
أول طلب لمحل يضيف ذلك المحل لشبكة السباك (بدون حذف الروابط الأخرى).
الربط اليدوي `POST /trader/plumbers/link` يضيف المحل أيضاً لنفس السباك.

**نعم — القطاعي يتعامل مع أكثر من محل جملة:**
| Method | Path |
|--------|------|
| GET | `/trader/wholesalers` |
| POST | `/trader/wholesalers/link` `{ code \| network_code \| phone }` |
| DELETE | `/trader/wholesalers/{id}` |
| GET | `/trader/wholesalers/{id}/stock` |
طلب توريد: `POST /trader/orders` مع `supplier_id` لأي جملة مرتبط (أو يُربط تلقائياً عند أول طلب).

### 6) كيف تُقرَّب النقاط في المرتجع؟

لكل بند في **كل** مرتجع: `points_value = floor(quantity × points_per_unit)`.  
مثال: `1 × 2.7 → 2`.  
الحساب **لكل مرتجع جزئي على حدة**، والمجموع لا يتجاوز نقاط التوزيع الأصلية (بوابة `assertPointsCanBeReturned` + `returnable`).

### 7) لماذا tier 2 كان يُظهر نقاطاً؟

Tier 2 لا يحرّك محفظة النقاط. كانت الأرقام «نقاط منتج محاسبية».  
الآن: `returnable_points_preview = 0` و`total_points = 0` و`affects_wallet_points = false`، مع الإبقاء على `product_points_preview` / `product_points` للتدقيق.

### 404 عربي

```json
{ "status": false, "message": "الفاتورة غير موجودة" }
```

---

## 11) Checklist تكامل

- [ ] Login حسب الدور + حفظ Sanctum `token` من الجذر
- [ ] جملة: **login فقط** — بدون register
- [ ] `Accept-Language: ar` دائماً
- [ ] `product_kind` + فلتر `?has_points=`
- [ ] `GET /wallet-visibility` لإظهار/إخفاء المحفظة
- [ ] قطاعي: `GET /wholesalers/{id}/stock` قبل الطلب من جملة
- [ ] طلبيات: أزرار من `available_actions` فقط + راقب `blocked_actions`
- [ ] جملة: زر تم التسليم = `POST …/deliver`
- [ ] طلب سباك: `stock` → (`items` أو `apply-stock`) → `fulfill`
- [ ] مرتجع: قائمة → استخدم **`id`** → preview → POST (201)
- [ ] `can_return=false` → أخفِ الزر + اعرض `block_reason`
- [ ] `points_per_unit`: null كتالوج · رقم في preview/POS/order-stock
- [ ] إشعارات: افتح بـ `order_id`
- [ ] `shop` للعرض فقط (`my-products/{mediaId}`)
- [ ] بيانات دخول خاطئة → **401** (ليس 422)
- [ ] سباك يطلب فقط من المحل المرتبط
- [ ] `POST /trader/plumbers/link` يقبل `network_code` أو `code` أو `phone`

---

## 12) Cheat sheet

```
Auth:      POST /api/v1/auth/login
           POST /api/v1/auth/register-plumber|register-retail
           POST /api/v1/auth/verify-otp|resend-otp|forgot-password/*
           (جملة = login فقط — لا register-store من الموبايل)

Shared:    /api/v1/mobile/me|profile|settings|wallet-visibility|notifications*
Stores:    /api/v1/network-stores* · /api/v1/vendors · /api/v1/my-store*

Plumber:   /plumber/{dashboard,wallet*,withdrawals*,products,orders*,
           distributions,returns*,notifications*}

Trader:    /trader/{dashboard,products,wholesalers/{id}/stock,my-products*,
           plumbers*,orders*,pos*,distributions,invoices,returns*,notifications*}

Distrib:   /distributor/{dashboard,products,my-products*,retail-traders*,
           plumbers*,orders*,pos*,invoices,returns*,notifications*}
```
