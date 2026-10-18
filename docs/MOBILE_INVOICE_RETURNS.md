# Mobile — Invoice returns & product kinds

**Production:** `https://mg-plastic.com/api`  
**Auth:** `Authorization: Bearer {token}` · `Accept-Language: ar`

دليل التسليم الكامل: [MOBILE_API_FOR_APP.md](./MOBILE_API_FOR_APP.md)

Returns are allowed on:

| Hop | Who requests | Who approves | Endpoint |
|-----|--------------|--------------|----------|
| جملة → قطاعي (tier 2) | **قطاعي فقط** | جملة | Trader invoice returns → Distributor approve |
| قطاعي → سباك (tier 3) | **سباك فقط** | قطاعي | Plumber distribution returns → Trader approve |

**مهم:** الطرف الأعلى (جملة / قطاعي) **لا ينشئ** مرتجعاً نيابةً عن الأدنى. يستقبل الطلب ثم `approve` / `reject`.  
استثناء: المخزن المركزي والمصنع (admin) يمكنهم إنشاء مرتجع.

**حالات المرتجع:** `pending` → `approved` | `rejected`  
قبول/رفض: `POST …/returns/{id}/approve` و `…/reject` + `available_actions`  
التفاصيل الكاملة: [MOBILE_SOURCES_RETURNS_REPORTS.md](./MOBILE_SOURCES_RETURNS_REPORTS.md)

**Blocked:** مرتجع بعد صرف/تحويل نقاط السباك من نفس التوزيع.  
**Tier 2:** لا يحرّك محفظة النقاط (مخزون/صافي فقط) — بعد **الموافقة**.  
**Tier 3:** يخصم من محفظة السباك ويعيد نفس العدد للقطاعي — بعد **الموافقة**.  
**Zero points (`mg_no_points`):** مرتجع كمية فقط؛ بوابة صرف النقاط لا تُفعّل.

---

## Body الموحّد (كل الأدوار)

```json
{
  "lines": [
    { "invoice_item_id": 12, "quantity": 2 }
  ],
  "note": "تالف"
}
```

Validation: `lines` مطلوبة، كل بند يحتاج `invoice_item_id` + `quantity >= 1`.  
رسائل الحقول عربية (`معرّف بند الفاتورة` · `كمية المرتجع`).

---

## Distributor (جملة)

```http
GET  /api/v1/mobile/distributor/invoices
GET  /api/v1/mobile/distributor/returns
GET  /api/v1/mobile/distributor/returns/{return}
GET  /api/v1/mobile/distributor/invoices/{invoice}/return-preview
POST /api/v1/mobile/distributor/invoices/{invoice}/returns
```

`GET /invoices` → قائمة فواتير الصادر للجملة→قطاعي مع `can_return` / `block_reason` / `returnable_quantity` لاختيار الفاتورة قبل الـ preview.

Preview includes `can_return`, `block_reason`, `lines[].returnable`, `returnable_points_preview`.  
`lines[].points_per_unit` هنا **رقم** (مش null) — لحساب نقاط الاسترجاع.

---

## Trader (قطاعي)

```http
GET  /api/v1/mobile/trader/distributions
GET  /api/v1/mobile/trader/invoices
GET  /api/v1/mobile/trader/returns
GET  /api/v1/mobile/trader/returns/{return}
GET  /api/v1/mobile/trader/distributions/{distribution}/return-preview
POST /api/v1/mobile/trader/distributions/{distribution}/returns
GET  /api/v1/mobile/trader/invoices/{invoice}/return-preview
POST /api/v1/mobile/trader/invoices/{invoice}/returns
```

| قائمة | استخدمها لـ |
|-------|-------------|
| `GET /distributions` | مبيعاتك للسباكين (tier 3) |
| `GET /invoices` | مشترياتك من الجملة (tier 2) |

ثم preview → POST على نفس الـ id.

---

## Plumber (سباك)

```http
GET  /api/v1/mobile/plumber/distributions
GET  /api/v1/mobile/plumber/distributions/{distribution}/return-preview
POST /api/v1/mobile/plumber/distributions/{distribution}/returns
GET  /api/v1/mobile/plumber/returns
GET  /api/v1/mobile/plumber/returns/{return}
```

`GET /distributions` يعيد لكل صف: `can_return`, `block_reason`, `returnable_quantity`, `returnable_points_preview`, بيانات المحل القطاعي.

Flow المقترح للتطبيق:

1. `GET /distributions` → اعرض فقط ما `can_return=true` (أو الكل مع سبب المنع)  
2. `GET …/return-preview` → عبّئ الكميات من `lines[].returnable`  
3. `POST …/returns` → نفّذ  

---

## Preview shape

```json
{
  "status": true,
  "message": "معاينة المرتجع",
  "data": {
    "distribution_id": 5,
    "tier": 3,
    "status": "points_awarded",
    "lines": [
      {
        "invoice_item_id": 12,
        "product_id": 42,
        "product_name": "ماسورة",
        "sold": 5,
        "returned": 0,
        "returnable": 5,
        "points_per_unit": 10,
        "has_points": true
      }
    ],
    "returnable_points_preview": 50,
    "can_return": false,
    "block_reason": "لا يمكن عمل مرتجع بعد صرف النقاط — …"
  }
}
```

> ملاحظة: `points_per_unit` في معاينة المرتجع **متعمد رقم**. في كتالوج `/products` ومخزون الجملة للقطاعي يكون `null` — لا تخلط بين السطحين.

---

## Error examples (422 / 403)

| Message (ar) | Meaning |
|--------------|---------|
| لا يمكن عمل مرتجع بعد صرف النقاط — … | Plumber balance too low after spend |
| لا يمكن عمل مرتجع بعد صرف نقاط هذه الفاتورة — … | Lots from this distribution spent |
| كمية المرتجع (…) تتجاوز المتاح للإرجاع | Qty too high / already returned / pushed down |
| بيانات المرتجع غير صالحة | Validation — check `errors` |
| مرتجع الجملة→قطاعي متاح على فواتير الصادر… | Wrong invoice type |
| غير مصرّح… | Not a party on the document |

Success create → **201** with `return_number`, `tier`, `items[]`.

---

## Product kinds (catalog / cart)

| `product_kind` | `is_mg` | `has_points` / `awards_points` | Meaning |
|----------------|---------|--------------------------------|---------|
| `mg_points` | true | true | MG product with loyalty points |
| `mg_no_points` | true | false | MG catalog item — **orderable, 0 points** |
| `shop` | false | false | Store `my_product` (display / shop catalog) |

Filter catalog: `?has_points=1` or `?has_points=0`.

Shop `my_products` on network store profiles expose `product_kind: shop`. Network stock POS/orders use MG catalog (`mg_points` / `mg_no_points`).
