# POS الموبايل — شكل الشاشات + API (تصنيف → فلتر → سلة → مراجعة → تأكيد)

تاريخ: 2026-10-21  
مرجع بصري: شاشات MG Plumbers (نظام → مواسير/ملحقات → أكورديون → مراجعة)

---

## 0) التدفق (إلزامي)

```
[1] اختر النظام المناسب     ← تصنيفات رئيسية (parent)
        │
        ▼
[2] منتجات النظام           ← فلتر: مواسير | ملحقات
        │                    + مجموعات أكورديون (فروع / أسماء)
        │                    +/− كمية → سلة محلية
        ▼
[3] شريط سفلي: مراجعة الطلب
        │
        ▼
[4] مراجعة الطلب            ← تجميع حسب المجموعة + إجمالي
        │
        ▼
[5] تأكيد الطلب             ← POST place order
```

بعد التأكيد: المورّد يؤكد/يشحن؛ **تم الاستلام دائماً من المستلم** (انظر القسم 5).

---

## 1) شاشة الأنظمة (التصنيف الرئيسي)

`GET /api/v1/mobile/{distributor|trader}/products/categories?systems=1`

```json
{
  "items": [
    {
      "id": 1,
      "name": "النظام الأوروبي",
      "image_url": "…",
      "products_count": 120,
      "is_accessories": false,
      "children": [
        { "id": 11, "name": "المواسير العادية", "products_count": 40, "parent_id": 1 },
        { "id": 12, "name": "المواسير الثقيلة", "products_count": 30, "parent_id": 1 }
      ]
    }
  ]
}
```

UX: بطاقات عمودية بصورة/أيقونة + اسم النظام + وصف قصير اختياري من الإدارة.

عند الضغط: احفظ `system_id` (= parent category id) وافتح شاشة المنتجات.

---

## 2) شاشة المنتجات داخل النظام

### فلاتر

| UI | Query |
|----|--------|
| مواسير (محدد) | `product_family=pipe` |
| ملحقات | `product_family=accessory` |
| فرع/مجموعة | `category_id={child_id}` أو فلترة محلية من `category_name` |
| بحث / باركود | `q=` |

مثال:

```http
GET …/products?category_id=11&product_family=pipe&page=1&per_page=50
GET …/products?category_id=1&product_family=accessory
```

ملاحظة: لو `category_id` نظام أب، فضّل تمرير أبناءه أو فلتر محليًا حسب `parent_category_id`.

### شكل المنتج (حقول جديدة للـ POS)

```json
{
  "id": 42,
  "name": "مواسير PVC أبيض ضغط 32مم",
  "image_url": "…",
  "order_unit": "piece",
  "pieces_per_box": null,
  "is_accessory": false,
  "product_family": "pipe",
  "category_id": 11,
  "category_name": "المواسير العادية",
  "parent_category_id": 1,
  "parent_category_name": "النظام الأوروبي",
  "has_points": true,
  "points_per_unit": null,
  "available_quantity": 120
}
```

### تجميع الأكورديون

اجمع `items` حسب `category_name` (أو `category_id`):

```
▼ المواسير العادية
   [img] 32مم · 4م — 120 قطعة   [−] 25 [+]
▼ المواسير الثقيلة
   …
▸ الملحقات والوصلات   (أو تبويب ملحقات يعرض شبكة أفقية)
```

العداد:

- `order_unit=box` → يزيد **صناديق**؛ أرسل `boxes`
- `order_unit=piece` → يزيد **قطع**؛ أرسل `quantity`

لا تعرض سعرًا ولا نقطة/قطعة.

### شريط السلة السفلي

```
6 أصناف / 128 قطعة          [ مراجعة الطلب → ]
```

---

## 3) مراجعة الطلب

- تجميع بنفس أسماء المجموعات
- كل سطر: اسم · مقاس/وصف · الكمية · حذف
- ملخص: إجمالي الكمية + عدد الأصناف
- اختياري: `POST …/orders/quote` لـ `total_points` فقط
- زر **تأكيد الطلب** → `POST …/orders`

---

## 4) تأكيد الطلب (API)

حسب الدور:

| من → إلى | Endpoint |
|----------|----------|
| قطاعي → جملة / مخزن | `POST /mobile/trader/orders` |
| جملة → مصنع/مخزن | `POST /mobile/distributor/orders` |
| سباك → قطاعي | `POST /mobile/plumber/orders` |

جسم نموذجي:

```json
{
  "supplier_id": 9,
  "lines": [
    { "product_id": 42, "quantity": 25 },
    { "product_id": 55, "boxes": 2 }
  ],
  "note": "اختياري"
}
```

---

## 5) تم الاستلام = الطرف المستلم فقط (قاعدة أعمال)

| القناة | المورّد يفعل | المستلم يؤكد الاستلام |
|--------|--------------|------------------------|
| مصنع/مخزن → جملة | confirm / ship | **الجملة** `POST …/receive` |
| جملة → قطاعي | confirm / ship | **القطاعي** `receive` |
| قطاعي → سباك | confirm / ship / fulfill | **السباك** `receive` |

- المورّد **لا** يرى `deliver` في `available_actions`
- `POST …/distributor/orders/{id}/deliver` → `403` (للعملاء القدامى)
- استخدم فقط `receive` من حساب المشتري (`requester`)

حالات:

```
placed → confirmed → shipping → delivered
                              ↑
                     receive من المستلم فقط
```

بعد `fulfill` لطلب سباك: الحالة تبقى `shipping` حتى يضغط السباك **تأكيد الاستلام**.

### أزرار التطبيق

| دور | أزرار من `available_actions` |
|-----|------------------------------|
| مورّد | `confirm`, `ship`, `reject`, (`fulfill` للسباك) |
| مستلم | `receive`, `cancel` (حسب الحالة) |

نص الزر للمستلم: **تأكيد الاستلام** / تم الاستلام.

---

## 6) برومبت GPT لشكل الشاشات

```
صمّم 3 شاشات POS عربية RTL لتطبيق MG Plumbers بأسلوب نظيف أبيض/أخضر/أزرق:

1) اختر النظام المناسب — بطاقات أنظمة (أوروبي، أمريكي، رمادي، برتقالي) + صورة مواسير، سلة بشارة عدد.
2) منتجات النظام — تبويب مواسير|ملحقات، أقسام أكورديون للمنتجات مع −/عداد/+، شريط سفلي أخضر «مراجعة الطلب».
3) مراجعة الطلب — قائمة مجمّعة + إجمالي الكمية والأصناف + زر تأكيد الطلب.

ممنوع: أسعار، نقاط لكل منتج، زر «تم التسليم» عند البائع.
```

---

## 7) Checklist موبايل

- [ ] شاشة أنظمة من `categories?systems=1`
- [ ] فلتر `product_family=pipe|accessory`
- [ ] أكورديون حسب `category_name`
- [ ] سلة محلية → مراجعة → `POST orders`
- [ ] لا تستخدم `deliver` للمورّد
- [ ] زر الاستلام يظهر للمستلم فقط من `available_actions` تحتوي `receive`
