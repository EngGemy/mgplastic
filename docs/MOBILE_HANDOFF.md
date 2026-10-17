# MG Plastic — دليل الموبايل الموحّد (Handoff)

ملف واحد لكل ما يحتاجه مطوّر التطبيق. تجاهل المستندات القديمة المتفرقة إن تعارضت مع هذا الملف.

| | |
|--|--|
| **Production** | `https://mg-plastic.com/api` |
| **Local** | `http://mgplastic.test/api` |
| **OpenAPI** | `GET /docs/api` |
| **تاريخ** | 2026-10-17 |

```http
Authorization: Bearer {token}
Accept: application/json
Accept-Language: ar
Content-Type: application/json
```

```json
{ "status": true, "message": "…", "data": { } }
```

```json
{ "status": false, "message": "…", "errors": { "field": ["رسالة عربية"] } }
```

| تطبيق | `role` بعد الدخول | Prefix |
|-------|-------------------|--------|
| سباك | `plumber` | `/api/v1/mobile/plumber` |
| قطاعي | `retail_trader` | `/api/v1/mobile/trader` |
| جملة | `wholesale_distributor` | `/api/v1/mobile/distributor` |

دور خاطئ على المسار → **403**.

---

## 1) قواعد ذهبية (اقرأها قبل أي شاشة)

1. أزرار الطلبيات من `available_actions[]` فقط — لا state machine محلي.
2. أزرار المرتجع من `can_return` + اعرض `block_reason` إن وُجد.
3. **الجملة = Login فقط.** لا شاشة تسجيل جملة. (`register-store` ليس للتطبيق.)
4. الجملة لا يربط سباكاً مباشرة — يختار `retail_trader_id` ثم يضيف السباك تحته.
5. منتجات `shop` للعرض في بروفايل المحل فقط. الطلبات من كتالوج MG.
6. بعد صرف/تحويل نقاط السباك من نفس التوزيع → المرتجع مرفوض.

### `product_kind`

| قيمة | `is_mg` | `has_points` | المعنى |
|-------|---------|--------------|--------|
| `mg_points` | true | true | MG بنقاط |
| `mg_no_points` | true | false | MG يُطلب **بدون** نقاط |
| `shop` | false | false | منتج عرض المحل |

### `points_per_unit` — متى null ومتى رقم؟

| السطح | القيمة |
|-------|--------|
| كتالوج `/products` · `OrderResource` · `GET /trader/wholesalers/{id}/stock` | دائماً **`null`** — استخدم `has_points` + إجمالي النقاط |
| معاينة مرتجع `return-preview` | **رقم** (لحساب الاسترجاع) |
| `GET /pos/stock` · `GET orders/{id}/stock` | **رقم** تشغيلي |

---

## 2) Auth

| Method | Path | من؟ |
|--------|------|-----|
| POST | `/api/v1/auth/login` | **كل الأدوار** (بما فيها الجملة) |
| POST | `/api/v1/auth/register-plumber` | سباك |
| POST | `/api/v1/auth/register-retail` | قطاعي فقط (alias: `register-trader`) |
| POST | `/api/v1/auth/verify-otp` | throttle 6/د |
| POST | `/api/v1/auth/resend-otp` | throttle 3/د |
| POST | `/api/v1/auth/forgot-password/send-otp` | |
| POST | `/api/v1/auth/forgot-password/verify-otp` | |
| POST | `/api/v1/auth/forgot-password/reset` | |

| Method | Path |
|--------|------|
| GET | `/api/v1/mobile/me` |
| POST | `/api/v1/mobile/logout` · `/logout-all` |
| GET / PUT\|PATCH\|POST | `/api/v1/mobile/profile` |
| GET | `/api/v1/mobile/settings` |

| حالة | HTTP |
|------|------|
| بيانات خاطئة / معلّق | 422 عربية |
| OTP تجاوز الحد | 429 عربية |
| هاتف غير موثّق عند login | 200 + OTP و`token=null` |

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
| GET | `/api/v1/network-stores` | قطاعي ← محلات جملة · سباك ← محلات قطاعي |
| GET | `/api/v1/network-stores/{id\|code}` | تفاصيل + `my_products` |
| * | `/api/v1/my-store…` | بروفايل/وسائط/سلايدر/منتجات عرض (جملة/قطاعي) |

المصنع ليس محلّاً — طلب الجملة للمصنع بدون `supplier_id`.

---

## 5) سباك — `/api/v1/mobile/plumber`

| Area | Paths |
|------|-------|
| Dashboard | `GET /dashboard` |
| Wallet | `GET /wallet` · `/wallet/transactions` · `/wallet/conversion-rules` · `POST /wallet/convert` |
| Withdrawals | `GET/POST /withdrawals` · `GET /withdrawals/{id}` · `/receipt` · `/receipt/download` |
| Products | `GET /products?retail_trader_id=` |
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

### مخزون

| Method | Path | Notes |
|--------|------|-------|
| GET | `/wholesalers/{id}/stock` | مخزون الجملة المرتبطة — `points_per_unit: null` + `available_qty` |
| GET | `/pos/stock` | مخزونك — `points_per_unit` رقمي |
| POST | `/pos/checkout` | `{ "plumber_id", "lines":[{product_id,quantity}] }` |

### شبكة السباكين

| Method | Path | Body |
|--------|------|------|
| GET | `/plumbers?search=&page=` | |
| POST | `/plumbers/link` | `{ "code" }` أو `{ "phone" }` |
| GET | `/plumbers/{id}` | |

### منتجات

| Method | Path |
|--------|------|
| GET | `/products` | كتالوج MG للطلب |
| GET/POST/PUT/DELETE | `/my-products` | منتجات عرض بدون نقاط |

### طلبيات

| Method | Path | Notes |
|--------|------|-------|
| GET | `/orders?scope=to_wholesaler\|from_plumbers` | |
| POST | `/orders` | للجملة — يحتاج `supplier_id` |
| GET | `/orders/{id}` | + `available_actions` |
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
  "lines": [
    { "product_id": 42, "quantity": 7 }
  ]
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

### شبكة القطاعي

| Method | Path | Body |
|--------|------|------|
| GET | `/retail-traders` | |
| POST | `/retail-traders` | تسجيل قطاعي جديد + ربط |
| POST | `/retail-traders/link` | `{ "network_code" }` أو `{ "phone" }` |
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

### سباكين (عبر القطاعي فقط)

| Method | Path | Body |
|--------|------|------|
| GET | `/plumbers?retail_trader_id=` | |
| POST | `/plumbers` | تسجيل تحت قطاعي |
| POST | `/plumbers/link` | `{ "retail_trader_id", "network_code"|"phone" }` |
| GET | `/plumbers/{id}` | |

### منتجات / POS

| Method | Path |
|--------|------|
| GET | `/products` |
| GET/POST/PUT/DELETE | `/my-products` |
| GET | `/pos/stock` |
| POST | `/pos/checkout` | `{ "retail_trader_id", "lines":[{product_id,quantity}] }` |

### طلبيات

| Method | Path | Notes |
|--------|------|-------|
| GET | `/orders?scope=to_factory\|from_retail\|needs_action` | |
| POST | `/orders` | للمصنع — `lines` فقط |
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

`deliver` = تم التسليم من الجملة (ينقل المخزون للقطاعي).  
`reject`: `{ "reason": "…" }`.

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
3. `POST …/returns`

### Preview shape

```json
{
  "distribution_id": 5,
  "tier": 3,
  "lines": [{
    "invoice_item_id": 12,
    "product_name": "ماسورة",
    "sold": 5,
    "returned": 0,
    "returnable": 5,
    "points_per_unit": 10,
    "has_points": true
  }],
  "returnable_points_preview": 50,
  "can_return": false,
  "block_reason": "لا يمكن عمل مرتجع بعد صرف النقاط — …"
}
```

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
         ↘ reject / cancel (مبكراً فقط)
```

| `channel` | من يطلب | من يورّد |
|-----------|---------|----------|
| `wholesale_to_retail` | قطاعي | جملة |
| `factory_to_wholesale` | جملة | المصنع |
| `retail_to_plumber` | سباك | قطاعي |

| Action | من | Endpoint |
|--------|-----|----------|
| `confirm` | مورّد | `POST …/confirm` |
| `ship` | مورّد | `POST …/ship` |
| `deliver` | جملة (مورّد) | `POST …/deliver` |
| `receive` | مشتري | `POST …/receive` |
| `cancel` | مشتري | `POST …/cancel` |
| `reject` | مورّد | `POST …/reject` |
| `fulfill` | قطاعي على طلب سباك | `POST …/fulfill` |
| `update_items` | قطاعي على طلب سباك | `PUT …/items` |
| `apply_stock` | قطاعي على طلب سباك | `POST …/apply-stock` |

كل طلب فيه `timeline[]` + `available_actions[]`.

---

## 10) Checklist تكامل

- [ ] Login حسب الدور + حفظ Sanctum token  
- [ ] جملة: **login فقط** — بدون register  
- [ ] `Accept-Language: ar` دائماً  
- [ ] `product_kind` + فلتر `?has_points=`  
- [ ] قطاعي: `GET /wholesalers/{id}/stock` قبل الطلب من جملة  
- [ ] طلبيات: أزرار من `available_actions` فقط  
- [ ] جملة: زر تم التسليم = `POST …/deliver`  
- [ ] طلب سباك: `stock` → (`items` أو `apply-stock`) → `fulfill`  
- [ ] مرتجع: قائمة → preview → POST  
- [ ] `can_return=false` → أخفِ الزر + اعرض `block_reason`  
- [ ] `points_per_unit`: null كتالوج · رقم في preview/POS  
- [ ] إشعارات: افتح بـ `order_id`  
- [ ] `shop` للعرض فقط  

---

## 11) Cheat sheet

```
Auth:      POST /api/v1/auth/login
           POST /api/v1/auth/register-plumber|register-retail
           POST /api/v1/auth/verify-otp|resend-otp|forgot-password/*
           (جملة = login فقط)

Shared:    /api/v1/mobile/me|profile|settings|notifications*
Stores:    /api/v1/network-stores* · /api/v1/my-store*

Plumber:   /plumber/{dashboard,wallet*,withdrawals*,products,orders*,
           distributions,returns*,notifications*}

Trader:    /trader/{products,wholesalers/{id}/stock,my-products,plumbers*,
           orders*,pos*,distributions,invoices,returns*,notifications*}

Distrib:   /distributor/{products,my-products,retail-traders*,plumbers*,
           orders*,pos*,invoices,returns*,notifications*}
```
