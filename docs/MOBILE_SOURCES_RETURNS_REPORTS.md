# مصادر الطلب · المرتجعات · التقارير — دليل الموبايل

تاريخ: 2026-10-17  
Base: `/api/v1/mobile`  
Auth: `Authorization: Bearer {token}`

> **كتالوج الجملة (صناديق/قطع + باجينيشن + مجموع نقاط فقط):**  
> انظر [MOBILE_WHOLESALE_SUPPLY_CATALOG.md](./MOBILE_WHOLESALE_SUPPLY_CATALOG.md)

---

## 1) مصادر التوريد (مصنع + مخازن)

لموزّع الجملة (ومخزن الدعوة يستخدم نفس مسار `distributor`).

### قائمة المصادر

```http
GET /distributor/order-sources
```

```json
{
  "status": true,
  "data": {
    "items": [
      {
        "source": "factory",
        "id": null,
        "name": "المصنع",
        "label": "طلب توريد من المصنع",
        "requires_warehouse_id": false
      },
      {
        "source": "warehouse",
        "id": 91,
        "warehouse_id": 91,
        "name": "مخزن الدعوة",
        "label": "مخزن الدعوة — MG-W-000091",
        "phone": "0910000001",
        "network_code": "MG-W-000091",
        "requires_warehouse_id": true,
        "stock_url": "https://…/api/v1/mobile/distributor/warehouses/91/stock"
      }
    ]
  }
}
```

### مخزون مخزن معيّن

```http
GET /distributor/warehouses/{warehouse_id}/stock
```

شكل البنود مثل `trader/wholesalers/{id}/stock`:

| حقل | معنى |
|-----|------|
| `product_id` | منتج MG |
| `available_qty` / `available_quantity` | المتاح |
| `has_points` | هل للمنتج نقاط |
| `pieces_per_box` | إن وُجد → الطلب بالصندوق |

### إنشاء طلب مع المصدر

```http
POST /distributor/orders
```

**من المصنع (الافتراضي):**

```json
{
  "source": "factory",
  "lines": [
    { "product_id": 42, "boxes": 3 },
    { "product_id": 7, "quantity": 10 }
  ],
  "note": "توريد أسبوعي"
}
```

**من مخزن مركزي:**

```json
{
  "source": "warehouse",
  "warehouse_id": 91,
  "lines": [
    { "product_id": 42, "boxes": 2 }
  ]
}
```

| `source` | القناة داخلياً | المورّد |
|----------|----------------|---------|
| `factory` (أو حذفه) | `factory_to_wholesale` | `null` (المصنع / أدمن) |
| `warehouse` | `warehouse_to_wholesale` | المخزن (`warehouse_id`) |

- منتجات لها `pieces_per_box` → أرسل `boxes` (أو `quantity` من مضاعفات الصندوق).
- منتجات بدون صندوق → أرسل `quantity` بالقطع.
- المخزن المركزي نفسه يطلب من المصنع فقط (`source=factory`).

### scopes مفيدة للطلبات

| scope | معنى |
|-------|------|
| `placed` / `to_factory` | طلباتي للمصنع **أو** المخزن |
| `incoming` / `from_retail` | وارد عليّ (قطاعي + طلبات مخزن واردة إن كنت مخزناً) |
| `needs_action` | وارد يحتاج تأكيد/شحن/تسليم |

`available_actions` على الطلب كما السابق: `confirm`, `ship`, `deliver`, `reject`, `receive`, `cancel`.

---

## 2) المرتجعات — حالات + موافقة/رفض

### الحالات

| status | المعنى |
|--------|--------|
| `pending` | بانتظار موافقة المستلم (البائع الأصلي) |
| `approved` | مقبول — طُبّق المخزون/النقاط |
| `rejected` | مرفوض — بدون أثر على المخزون |

> الصفوف القديمة `confirmed` تُعامَل كـ `approved` في الـ API.

### إنشاء مرتجع

نفس المسارات السابقة — النتيجة الآن `pending` وليس فورياً:

| دور | إنشاء |
|-----|--------|
| جملة | `POST /distributor/invoices/{invoice}/returns` |
| قطاعي | `POST /trader/invoices/{invoice}/returns` أو `…/distributions/{id}/returns` |
| سباك | `POST /plumber/distributions/{id}/returns` |

```json
{
  "lines": [{ "invoice_item_id": 15, "quantity": 2 }],
  "note": "تالف"
}
```

الاستجابة تتضمن:

```json
{
  "status": "pending",
  "status_label": "بانتظار الموافقة",
  "available_actions": [],
  "blocked_actions": [
    { "action": "approve", "reason": "قبول المرتجع متاح للمستلم (البائع الأصلي) فقط" }
  ]
}
```

### قائمة المرتجعات

```http
GET /{role}/returns?scope=needs_action
GET /{role}/returns?scope=incoming&status=pending
GET /{role}/returns?scope=outgoing
```

| scope | معنى |
|-------|------|
| *(فارغ)* | الطرفان |
| `incoming` | واردة عليّ (`to_user` = أنا) |
| `needs_action` | واردة + `pending` (تحتاج رد) |
| `outgoing` / `placed` | أنا طلبتها |

`role` = `distributor` | `trader` | `plumber`

### قبول / رفض

```http
POST /{role}/returns/{id}/approve
POST /{role}/returns/{id}/reject
```

Body اختياري:

```json
{ "note": "تم الاستلام" }
```

```json
{ "reason": "الكمية غير مطابقة" }
```

**من يقبل؟** `to_user` (البائع الأصلي في التوزيع) أو أدمن.  
يظهر في `available_actions`: `["approve","reject"]` عندما تكون الحالة `pending` وأنت المستلم.

> **محظور حالياً:** مرتجع توزيع **مخزن الدعوة → جملة** (`from` مخزن مركزي و`to` موزّع جملة).  
> التسليم يُنشئ مخزوناً مستقلاً للمشتري؛ إرجاع الـ tier-2 فقط كان يضخّم الشبكة.  
> الـ API يرجع `can_return: false` مع `block_reason` واضح.

### ويب (Filament)

- تسجيل مرتجع → حالة `pending`.
- في تبويب المرتجعات على الفاتورة: أزرار **قبول** / **رفض**.
- الخصم من المخزون/النقاط يحدث **بعد القبول فقط**.

---

## 3) التقارير

```http
GET /distributor/reports?from=2026-10-01&to=2026-10-17
GET /trader/reports?from=2026-10-01&to=2026-10-17
GET /plumber/reports?from=2026-10-01&to=2026-10-17
```

- بدون `from`/`to` → آخر 30 يوماً (شامل اليوم).
- الاستجابة موحّدة الشكل:

```json
{
  "status": true,
  "data": {
    "role": "distributor",
    "from": "2026-10-01",
    "to": "2026-10-17",
    "totals": { },
    "daily": [
      { "date": "2026-10-01", "orders_placed": 2, "orders_incoming": 1, "sales_points": 120 }
    ],
    "top_customers": [
      { "id": 5, "name": "محل النور", "orders_count": 4, "points": 800 }
    ],
    "top_products": [
      { "product_id": 42, "name": "محبس ½", "quantity": 60, "points": 60 }
    ]
  }
}
```

### أرقام `totals` حسب الدور (النسخة الحالية — قابلة للتوسيع مع صاحب المشروع)

**distributor / warehouse**

| مفتاح | معنى |
|-------|------|
| `orders_placed` | طلبات أُرسلت (مصنع/مخزن) |
| `orders_incoming` | طلبات واردة عليّ |
| `orders_delivered` | مُسلَّمة |
| `sales_qty` | وحدات مباعة للقطاعي (توزيعات مؤكدة) |
| `sales_points` | نقاط المبيعات |
| `returns_pending` | مرتجعات تحتاج ردي |
| `returns_approved` | مرتجعات مقبولة في الفترة |

**trader**

| مفتاح | معنى |
|-------|------|
| `orders_to_wholesale` | طلبات للجملة |
| `orders_from_plumbers` | طلبات من السباكين |
| `sales_points` | نقاط بيع للسباكين |
| `returns_pending` / `returns_approved` | مرتجعات |

**plumber**

| مفتاح | معنى |
|-------|------|
| `orders_placed` | طلبات للمحل |
| `orders_delivered` | مستلمة |
| `points_received` | نقاط وصلت |
| `returns_placed` | مرتجعات أرسلتها |

`daily[]` جاهز للرسم البياني (نقطة لكل يوم في الفترة).  
`top_customers` / `top_products` أعلى 10.

> للتوسيع لاحقاً مع صاحب المشروع: قيمة مالية LYD، نسبة المرتجعات، متوسط زمن التسليم، إلخ.

---

## 4) ملخص سريع للتطبيق

1. شاشة «طلب توريد»: `GET order-sources` → اختر مصنع أو مخزن → إن مخزن: `GET warehouses/{id}/stock` → `POST orders` مع `source` + `warehouse_id`.
2. شاشة المرتجعات الواردة: `GET returns?scope=needs_action` → `approve` / `reject` حسب `available_actions`.
3. شاشة التقارير: `GET reports?from=&to=` → بطاقات من `totals` + خط من `daily` + قوائم top.

---

## ملفات مرتبطة

- [MOBILE_NETWORK_ORDERS.md](./MOBILE_NETWORK_ORDERS.md) — طلبات الشبكة والصناديق  
- [MOBILE_INVOICE_RETURNS.md](./MOBILE_INVOICE_RETURNS.md) — إنشاء المرتجع (معاينة/بنود)  
- [WAREHOUSE_DAAWA.md](./WAREHOUSE_DAAWA.md) — لوحة مخزن الدعوة  
