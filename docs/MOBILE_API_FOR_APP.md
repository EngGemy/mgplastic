# MG Plastic — Mobile API (لموبايل التطبيق)

> **المصدر الموحّد المحدَّث:** [MOBILE_HANDOFF.md](./MOBILE_HANDOFF.md) — اعتمد عليه إن تعارض أي شيء هنا.

دليل تسليم مختصر. للتفاصيل الكاملة والـ bodies المحدَّثة استخدم الـ Handoff.

**Production:** `https://mg-plastic.com/api`  
**Local:** `http://mgplastic.test/api` أو `http://127.0.0.1:8000/api`  
**OpenAPI تفاعلي:** `GET /docs/api`

```http
Authorization: Bearer {token}
Accept: application/json
Accept-Language: ar
Content-Type: application/json
```

### Envelope

```json
{ "status": true, "message": "…", "data": { } }
```

```json
{ "status": false, "message": "…", "errors": { "field": ["رسالة عربية"] } }
```

| دور التطبيق | قيمة `role` عند الدخول | Prefix |
|-------------|------------------------|--------|
| سباك | `plumber` | `/api/v1/mobile/plumber` |
| قطاعي | `retail_trader` | `/api/v1/mobile/trader` |
| جملة | `wholesale_distributor` | `/api/v1/mobile/distributor` |

دور خاطئ على المسار → **403** `غير مصرح — الدور غير مناسب لهذا المسار`.

أدلة مرتبطة: [طلبيات](./MOBILE_NETWORK_ORDERS.md) · [مرتجعات](./MOBILE_INVOICE_RETURNS.md) · [دليل كامل](./MOBILE_API_COMPLETE.md)

---

## 0) قواعد مهمة قبل البرمجة

1. ابنِ الأزرار على `available_actions[]` (طلبيات) و`can_return` + `block_reason` (مرتجعات) — **لا** تعمل state machine محلي فقط.
2. أنواع المنتج في الكتالوج:

| `product_kind` | `is_mg` | `has_points` | المعنى |
|----------------|---------|--------------|--------|
| `mg_points` | true | true | MG بنقاط ولاء |
| `mg_no_points` | true | false | MG **يُطلب بدون نقاط** |
| `shop` | false | false | منتج عرض المحل (`my_products`) |

3. **`points_per_unit`:**

| سطح API | القيمة |
|---------|--------|
| كتالوج `/products` · مورد الطلب `OrderResource` · مخزون الجملة للقطاعي | دائماً `null` — اعرض `has_points` + إجمالي النقاط |
| معاينة المرتجع `return-preview` | **رقم** (لحساب الاسترجاع) |
| مخزون POS الخاص `/pos/stock` · `GET orders/{id}/stock` | **رقم** تشغيلي |

4. الجملة **لا** يربط سباكاً مباشرة — يختار `retail_trader_id` من شبكته ثم يضيف السباك تحته.
5. بعد صرف/تحويل نقاط السباك من نفس التوزيع → المرتجع **مرفوض 422** برسالة عربية واضحة.
6. **تطبيق الجملة = دخول فقط** (`login`) — لا شاشة تسجيل جملة من الموبايل.

---

## 1) Auth

| Method | Path | Notes |
|--------|------|-------|
| POST | `/api/v1/auth/login` | كل الأدوار بما فيها **الجملة** |
| POST | `/api/v1/auth/register-plumber` | سباك |
| POST | `/api/v1/auth/register-retail` | قطاعي فقط (alias: `register-trader`) |
| POST | `/api/v1/auth/verify-otp` | throttle 6/دقيقة |
| POST | `/api/v1/auth/resend-otp` | throttle 3/دقيقة |
| POST | `/api/v1/auth/forgot-password/send-otp` | |
| POST | `/api/v1/auth/forgot-password/verify-otp` | |
| POST | `/api/v1/auth/forgot-password/reset` | |

> `register-store` موجود في الـ backend لإنشاء جملة من لوحة/أدوات داخلية — **لا تستخدمه في تطبيق الموبايل.** حسابات الجملة تُنشأ من الأدمن ويدخلون بـ `login` فقط.

### Session / بروفايل

| Method | Path |
|--------|------|
| GET | `/api/v1/mobile/me` |
| POST | `/api/v1/mobile/logout` · `/logout-all` |
| GET / PUT|PATCH|POST | `/api/v1/mobile/profile` |
| GET | `/api/v1/mobile/settings` |

### Edge cases — Auth

| حالة | HTTP | رسالة متوقعة |
|------|------|----------------|
| بيانات خاطئة | **401** | `رقم الهاتف أو كلمة المرور غير صحيحة` |
| حساب معلّق / غير مصرّح | **403** | رسالة عربية حسب الحالة |
| تجاوز حد OTP | 429 | انتظار بالعربية (`ArabicWait`) |
| هاتف غير موثّق عند الدخول | 200 | يُرسل OTP و`token` = null (الجذر وليس داخل `data`) |

---

## 2) إشعارات (مشتركة)

Prefix: `/api/v1/mobile/notifications`  
أو نفس المسارات تحت `/plumber|/trader|/distributor/notifications`

| Method | Path |
|--------|------|
| GET | `/` · `/summary` · `/unread-count` · `/{id}` |
| POST | `/read-all` · `/read-many` · `/{id}/read` · `/{id}/unread` · `/delete-many` |
| DELETE | `/` · `/read` · `/{id}` |

Query: `?filter=unread` · `?order_id=` · `?entity_type=order`

---

## 3) سباك — `/api/v1/mobile/plumber`

### لوحة / محفظة / سحب

| Method | Path |
|--------|------|
| GET | `/dashboard` |
| GET | `/wallet` · `/wallet/transactions` · `/wallet/conversion-rules` |
| POST | `/wallet/convert` |
| GET/POST | `/withdrawals` · `/withdrawals/{id}` |
| GET | `/withdrawals/{id}/receipt` · `/receipt/download` |

### منتجات وطلبيات

| Method | Path | Notes |
|--------|------|-------|
| GET | `/products?retail_trader_id=` | مخزون محل القطاعي المرتبط |
| GET/POST | `/orders` | طلب لقطاعي |
| GET | `/orders/{id}` | فيه `available_actions` |
| POST | `/orders/{id}/receive` · `/cancel` | |

### مرتجعات السباك (tier 3)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/distributions` | قائمة توزيعات البيع من القطاعي + `can_return` |
| GET | `/distributions/{id}/return-preview` | معاينة بنود + `block_reason` |
| POST | `/distributions/{id}/returns` | تنفيذ المرتجع |
| GET | `/returns` · `/returns/{id}` | سجل المرتجعات |

```json
POST /distributions/{id}/returns
{
  "lines": [{ "invoice_item_id": 12, "quantity": 2 }],
  "note": "تالف"
}
```

**يخصم** نقاط السباك ويعيدها للقطاعي بنفس العدد (بدون تضخيم).  
**محظور** بعد صرف/تحويل نقاط نفس التوزيع.

محلات القطاعي العامة: `GET /api/v1/network-stores`

---

## 4) قطاعي — `/api/v1/mobile/trader`

### تدفق مقترح

1. Login / register-retail  
2. محلات الجملة: `GET /api/v1/network-stores`  
3. مخزون جملة مرتبط: `GET /wholesalers/{id}/stock`  
4. كتالوج طلب: `GET /products`  
5. منتجات عرض: `GET/POST/PUT/DELETE /my-products`  
6. طلب من جملة: `POST /orders`  
7. سباكين: `GET/POST /plumbers*`  
8. POS + تنفيذ طلبات السباكين + مرتجعات  

### مخزون

| Method | Path | Notes |
|--------|------|-------|
| GET | `/wholesalers/{id}/stock` | مخزون **الجملة المرتبطة** قبل الطلب — `points_per_unit: null` + `available_qty` |
| GET | `/pos/stock` | مخزونك القطاعي (POS / تنفيذ طلبات السباك) — فيه `points_per_unit` رقمي |
| POST | `/pos/checkout` | بيع لسباك `{ plumber_id, lines[] }` |

### شبكة السباكين

| Method | Path | Body |
|--------|------|------|
| GET | `/plumbers?search=&page=` | |
| POST | `/plumbers/link` | `{ "code" }` أو `{ "phone" }` |
| GET | `/plumbers/{id}` | |

### طلبيات

| Method | Path | Notes |
|--------|------|-------|
| GET | `/orders?scope=to_wholesaler\|from_plumbers` | |
| POST | `/orders` | `{ supplier_id, lines[], note? }` |
| GET | `/orders/{id}` | |
| POST | `/orders/{id}/receive` · `/cancel` | كمشتري من الجملة |
| POST | `/orders/{id}/confirm\|ship\|reject` | لطلبات السباكين |
| GET | `/orders/{id}/stock` | فحص مخزون مقابل بنود طلب سباك |
| PUT | `/orders/{id}/items` | تعديل أصناف طلب سباك |
| POST | `/orders/{id}/apply-stock` | قص للمتوفر |
| POST | `/orders/{id}/fulfill` | تنفيذ كامل → فاتورة + تسليم |

```json
POST /orders
{
  "supplier_id": 15,
  "lines": [{ "product_id": 42, "quantity": 10 }],
  "note": "…"
}
```

#### تنفيذ طلب سباك (bodies)

```http
GET /orders/{id}/stock
```

لا body → `lines[].requested_qty|available_qty|fulfillable_qty|is_available` (+ `points_per_unit` رقمي هنا).

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

لا body.

```json
POST /orders/{id}/fulfill
{ "note": "تم التنفيذ من المخزون" }
```

`note` اختياري. يفشل لو أي بند غير متوفر بالكامل.

### مرتجعات — قائمة ثم preview

| Method | Path | Hop |
|--------|------|-----|
| GET | `/distributions` | قائمة مبيعاتك للسباكين + `can_return` |
| GET/POST | `/distributions/{id}/return-preview` · `/returns` | قطاعي→سباك |
| GET | `/invoices` | قائمة فواتير الشراء من الجملة + `can_return` |
| GET/POST | `/invoices/{id}/return-preview` · `/returns` | شراء من الجملة |
| GET | `/returns` · `/returns/{id}` | سجل المرتجعات |

---

## 5) جملة — `/api/v1/mobile/distributor`

### شبكة القطاعي

| Method | Path | Body |
|--------|------|------|
| GET | `/retail-traders` | |
| POST | `/retail-traders` | تسجيل قطاعي جديد + ربط |
| POST | `/retail-traders/link` | `{ network_code }` أو `{ phone }` |
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

### سباكين عبر القطاعي (مش ربط مباشر)

| Method | Path | Body |
|--------|------|------|
| GET | `/plumbers?retail_trader_id=` | |
| POST | `/plumbers` | تسجيل تحت قطاعي من الشبكة |
| POST | `/plumbers/link` | `{ retail_trader_id, network_code\|phone }` |
| GET | `/plumbers/{id}` | |

### طلبيات

| Method | Path | Notes |
|--------|------|-------|
| GET | `/orders?scope=to_factory\|from_retail\|needs_action` | |
| POST | `/orders` | للمصنع — `lines` فقط (بدون `supplier_id`) |
| POST | `/orders/{id}/confirm\|ship\|deliver\|reject` | كمورّد لطلبات القطاعي |
| POST | `/orders/{id}/receive\|cancel` | كمشتري من المصنع |

> **تم التسليم** للجملة على طلبات القطاعي: `POST /orders/{id}/deliver`

### منتجات / POS / مرتجعات

| Area | Paths |
|------|-------|
| Catalog | `GET /products` · `GET/POST/PUT/DELETE /my-products` |
| POS | `GET /pos/stock` · `POST /pos/checkout` `{ retail_trader_id, lines[] }` |
| Returns | `GET /invoices` (قائمة اختيار) · `GET/POST /invoices/{id}/return-preview|/returns` · `GET /returns` |

---

## 6) مرتجعات — قواعد Edge Cases (إلزامي)

### من يسمح له؟

| Hop | أطراف مصرّح لهم | API |
|-----|-----------------|-----|
| جملة → قطاعي (tier 2) | جملة أو قطاعي طرف الفاتورة | Distributor / Trader `invoices/{id}/…` |
| قطاعي → سباك (tier 3) | قطاعي أو سباك طرف التوزيع | Trader / Plumber `distributions/{id}/…` |

### سلوك النظام

| حالة | النتيجة |
|------|---------|
| معاينة أولاً | `can_return` + `block_reason` + `lines[].returnable` |
| كمية أكبر من المتاح | 422 عربية تتضمن اسم المنتج والمتاح |
| مرتجع جزئي متكرر | مسموح حتى `returnable = 0` |
| Tier 2 | **لا** يحرّك محفظة النقاط (مخزون/صافي فقط) |
| Tier 3 + نقاط | يخصم من السباك ويعيد **نفس** العدد للقطاعي |
| منتج `mg_no_points` (0 نقاط) | مرتجع كمية فقط — بوابة صرف النقاط لا تُفعّل |
| بعد تحويل/صرف نقاط التوزيع | 422 — لا مرتجع |
| طرف غريب عن الفاتورة/التوزيع | 403 |
| فاتورة/توزيع غير مؤكّد | 422 |
| كمية مدفوعة لأسفل (tier2 بيعت قطاعي→سباك) | تُخصم من المتاح للإرجاع |
| `lines` فارغة / `invoice_item_id` خاطئ | 422 عربية |

### Body الموحّد

```json
{
  "lines": [
    { "invoice_item_id": 12, "quantity": 2 }
  ],
  "note": "سبب اختياري"
}
```

### رسائل 422 شائعة

| رسالة تحتوي | المعنى للتطبيق |
|-------------|----------------|
| `صرف النقاط` / `صرف نقاط هذه الفاتورة` | أخفِ زر المرتجع / اعرض السبب |
| `تتجاوز المتاح للإرجاع` | صحّح الكمية من `returnable` |
| `بيانات المرتجع غير صالحة` | راجع `errors` للحقول |
| `غير مصرّح` | المستخدم ليس طرفاً |

### نجاح 201

`data` يحتوي: `id`, `return_number`, `tier`, `tier_label`, `total_quantity`, `total_points`, `items[]`, `from_user`, `to_user`.

---

## 7) طلبيات — Status & Actions

```
placed → confirmed → shipping → delivered
         ↘ reject / cancel (مبكراً فقط)
```

| Action | معنى الزر |
|--------|-----------|
| `confirm` | تأكيد |
| `ship` | شحن |
| `deliver` | تم التسليم (مورّد) |
| `receive` | استلام (مشتري) |
| `cancel` | إلغاء |
| `reject` | رفض |
| `fulfill` | تنفيذ من المخزون |
| `update_items` | تعديل أصناف |
| `apply_stock` | تطبيق المخزون |

تفاصيل القنوات والحالات: [MOBILE_NETWORK_ORDERS.md](./MOBILE_NETWORK_ORDERS.md)

---

## 8) شبكة المحلات

| Method | Path | من يرى ماذا |
|--------|------|-------------|
| GET | `/api/v1/network-stores` | قطاعي←جملة · سباك/ضيف←قطاعي |
| GET | `/api/v1/network-stores/{id\|code}` | تفاصيل + `my_products` |
| * | `/api/v1/my-store…` | بروفايل/وسائط/سلايدر/منتجات عرض |

المصنع ليس محلّاً في الدليل — طلب الجملة للمصنع بدون `supplier_id`.

---

## 9) Checklist تكامل التطبيق

- [ ] Login حسب الدور + حفظ Sanctum token  
- [ ] جملة: login فقط — **بدون** register-store في التطبيق  
- [ ] `Accept-Language: ar` دائماً  
- [ ] عرض `product_kind` وفلترة `?has_points=`  
- [ ] قطاعي: `GET /wholesalers/{id}/stock` لعرض مخزون الجملة المرتبطة  
- [ ] طلبيات: أزرار من `available_actions` فقط  
- [ ] جملة: زر **تم التسليم** = `POST …/deliver`  
- [ ] طلب سباك: `stock` → (`items` أو `apply-stock`) → `fulfill` حسب الحاجة  
- [ ] مرتجع: قائمة (`/distributions` أو `/invoices`) ثم `return-preview` ثم `POST`  
- [ ] سباك/قطاعي/جملة: استخدم قوائم الاختيار المناسبة لكل دور  
- [ ] منع المرتجع محلياً عند `can_return=false` وعرض `block_reason`  
- [ ] `points_per_unit`: null في الكتالوج؛ رقم في preview المرتجع وPOS  
- [ ] إشعارات: فتح الطلب بـ `order_id` من الـ payload  
- [ ] منتجات `shop` للعرض فقط — الطلب من كتالوج MG  

---

## 10) Cheat sheet سريع

```
Auth:     POST /api/v1/auth/login|register-plumber|register-retail|verify-otp|forgot-password/*
          (جملة = login فقط)
Shared:   /api/v1/mobile/me|profile|settings|notifications*
Stores:   /api/v1/network-stores* · /api/v1/my-store*
Plumber:  /plumber/{…,distributions,returns*}
Trader:   /trader/{products,wholesalers/{id}/stock,pos*,plumbers*,orders*,distributions,invoices,returns*}
Distrib:  /distributor/{products,retail-traders*,plumbers*,orders*,pos*,invoices,returns*}
```

## 11) Checklist تكامل — تحديثات هذه الجولة

- [ ] قطاعي: `GET /wholesalers/{id}/stock` قبل الطلب من جملة  
- [ ] قطاعي: `GET /distributions` و`GET /invoices` قبل شاشة المرتجع  
- [ ] جملة: `GET /invoices` قبل شاشة المرتجع  
- [ ] تنفيذ طلب سباك: stock → (items|apply-stock) → fulfill مع الـ bodies أعلاه  
- [ ] جملة: لا شاشة register — login فقط  
- [ ] لا تفترض `points_per_unit=null` في معاينة المرتجع |
