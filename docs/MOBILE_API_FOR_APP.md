# MG Plastic — Mobile API (لموبايل التطبيق)

دليل التسليم لمطوّر الموبايل: كل المسارات المطلوبة + الحالات الحدّية + رسائل التحقق العربية.

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

3. في كتالوج الشبكة: `points_per_unit` دائماً `null` — اعرض إجمالي النقاط على الطلب/السطر فقط.
4. الجملة **لا** يربط سباكاً مباشرة — يختار `retail_trader_id` من شبكته ثم يضيف السباك تحته.
5. بعد صرف/تحويل نقاط السباك من نفس التوزيع → المرتجع **مرفوض 422** برسالة عربية واضحة.

---

## 1) Auth

| Method | Path | Notes |
|--------|------|-------|
| POST | `/api/v1/auth/login` | `{ phone, password }` → `token` أو OTP إن الهاتف غير موثّق |
| POST | `/api/v1/auth/register-plumber` | سباك |
| POST | `/api/v1/auth/register-retail` | قطاعي (alias: `register-trader`) |
| POST | `/api/v1/auth/register-store` | جملة |
| POST | `/api/v1/auth/verify-otp` | throttle 6/دقيقة |
| POST | `/api/v1/auth/resend-otp` | throttle 3/دقيقة |
| POST | `/api/v1/auth/forgot-password/send-otp` | |
| POST | `/api/v1/auth/forgot-password/verify-otp` | |
| POST | `/api/v1/auth/forgot-password/reset` | |

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
| بيانات خاطئة | 422 | رسالة عربية (فشل الدخول) |
| حساب معلّق / غير معتمد | 422/403 | رسالة عربية حسب الحالة |
| تجاوز حد OTP | 429 | انتظار بالعربية (`ArabicWait`) |
| هاتف غير موثّق عند الدخول | 200 | يُرسل OTP و`token` = null |

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
3. كتالوج طلب: `GET /products`  
4. منتجات عرض: `GET/POST/PUT/DELETE /my-products`  
5. طلب من جملة: `POST /orders`  
6. سباكين: `GET/POST /plumbers*`  
7. POS + تنفيذ طلبات السباكين + مرتجعات  

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
| POST | `/orders/{id}/confirm\|ship\|reject\|fulfill` | لطلبات السباكين |
| PUT | `/orders/{id}/items` | تعديل أصناف طلب سباك |
| GET | `/orders/{id}/stock` | |
| POST | `/orders/{id}/apply-stock` | |

### POS

| Method | Path |
|--------|------|
| GET | `/pos/stock` |
| POST | `/pos/checkout` |

### مرتجعات

| Method | Path | Hop |
|--------|------|-----|
| GET | `/returns` · `/returns/{id}` | الكل |
| GET/POST | `/distributions/{id}/return-preview` · `/returns` | قطاعي→سباك |
| GET/POST | `/invoices/{id}/return-preview` · `/returns` | شراء من الجملة |

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
| POS | `GET/POST /pos/stock` · `/pos/checkout` |
| Returns | `GET /returns` · `GET/POST /invoices/{id}/return-preview|/returns` |

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
- [ ] `Accept-Language: ar` دائماً  
- [ ] عرض `product_kind` وفلترة `?has_points=`  
- [ ] طلبيات: أزرار من `available_actions` فقط  
- [ ] جملة: زر **تم التسليم** = `POST …/deliver`  
- [ ] مرتجع: دائماً `return-preview` قبل `POST`  
- [ ] سباك: شاشة مرتجعات من `GET /plumber/distributions`  
- [ ] منع المرتجع محلياً عند `can_return=false` وعرض `block_reason`  
- [ ] إشعارات: فتح الطلب بـ `order_id` من الـ payload  
- [ ] منتجات `shop` للعرض فقط — الطلب من كتالوج MG  

---

## 10) Cheat sheet سريع

```
Auth:     POST /api/v1/auth/login|register-*|verify-otp|forgot-password/*
Shared:   /api/v1/mobile/me|profile|settings|notifications*
Stores:   /api/v1/network-stores* · /api/v1/my-store*
Plumber:  /plumber/{dashboard,wallet*,withdrawals*,products,orders*,distributions,returns*}
Trader:   /trader/{products,my-products,plumbers*,orders*,pos*,returns*,distributions*,invoices*}
Distrib:  /distributor/{products,my-products,retail-traders*,plumbers*,orders*,pos*,returns*,invoices*}
```
