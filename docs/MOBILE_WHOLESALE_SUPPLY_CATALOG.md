# كتالوج توريد الجملة — صناديق / قطع + مجموع النقاط فقط

تاريخ: 2026-10-17  
Base: `/api/v1/mobile`  
Auth: `Authorization: Bearer {token}`  
دور الحساب: `wholesale_distributor` (ومخزن الدعوة بنفس المسارات + `is_central_warehouse=true`)

---

## القاعدة الذهبية للنقاط

| ما يُعرض في التطبيق | ما لا يُعرض أبداً |
|---------------------|-------------------|
| **`total_points`** على الطلب / ملخص السلة فقط | `points_per_unit` لأي منتج |
| | `line_points` لأي بند |

- في الكتالوج والمخزون: `points_per_unit` دائماً `null` (قد يوجد `has_points` كعلم بدون قيمة).
- في الطلب: البنود بدون نقاط؛ المجموع في `data.total_points`.
- لمعرفة مجموع السلة **قبل** الإرسال: استخدم `POST /distributor/orders/quote`.

---

## 1) تبويبات الطلب: صناديق vs قطع

كل منتج له:

| حقل | معنى |
|-----|------|
| `order_unit` | `"box"` أو `"piece"` |
| `pieces_per_box` | عدد القطع في الصندوق، أو `null` لو قطعة فقط |

**كيف يطلب التطبيق؟**

| `order_unit` | أرسل في الـ cart |
|--------------|------------------|
| `box` | `"boxes": N` (يُحوَّل داخلياً إلى قطع) |
| `piece` | `"quantity": N` بالقطع |

---

## 2) كتالوج المصنع (باجينيشن + فلتر)

```http
GET /distributor/products?order_unit=box&page=1&per_page=20
GET /distributor/products?order_unit=piece&page=1&per_page=20
GET /distributor/products?page=1&per_page=20
```

| Query | معنى | افتراضي |
|-------|------|---------|
| `order_unit` أو `unit` | `box` \| `piece` — فلتر التبويب | الكل |
| `page` | رقم الصفحة | `1` |
| `per_page` | حجم الصفحة (1–50) | `20` |
| `category_id` | تصنيف | — |
| `q` أو `search` | بحث بالاسم | — |
| `has_points` | `1` / `0` اختياري | الكل |

### شكل الرد

```json
{
  "status": true,
  "data": {
    "items": [
      {
        "id": 42,
        "name": "محبس ½",
        "image_url": "https://…",
        "points_per_unit": null,
        "has_points": true,
        "pieces_per_box": 12,
        "order_unit": "box",
        "category_id": 3,
        "category_name": "محابس"
      },
      {
        "id": 7,
        "name": "قطعة فردية",
        "points_per_unit": null,
        "has_points": false,
        "pieces_per_box": null,
        "order_unit": "piece",
        "category_id": 3,
        "category_name": "محابس"
      }
    ],
    "units": {
      "all": 97,
      "box": 40,
      "piece": 57
    },
    "filters": {
      "order_unit": "box",
      "category_id": null,
      "search": null
    },
    "pagination": {
      "current_page": 1,
      "last_page": 2,
      "per_page": 20,
      "total": 40,
      "from": 1,
      "to": 20,
      "has_more": true,
      "next_page": 2,
      "prev_page": null
    }
  }
}
```

### باجينيشن — كيف يستخدمه التطبيق

1. أول تحميل: `page=1&per_page=20` (+ `order_unit` لو التبويب نشط).
2. Infinite scroll / «تحميل المزيد»: لو `pagination.has_more === true` استدعِ `page = pagination.next_page`.
3. عند تبديل التبويب (`box` ↔ `piece` ↔ الكل): ارجع `page=1` واقرأ `units` لعرض أعداد الشارات.
4. `units` دائماً على كامل الفلتر الحالي **بدون** تقييد `order_unit` (أعداد التبويبات ثابتة أثناء التصفح داخل تبويب).
5. لا تعتمد على `per_page` أكبر من 50 — السيرفر يقصّها.

### التصنيفات

```http
GET /distributor/products/categories
```

---

## 3) مخزون مخزن الدعوة (نفس الفلتر + باجينيشن)

بعد اختيار المصدر `warehouse` من `GET /distributor/order-sources`:

```http
GET /distributor/warehouses/{warehouse_id}/stock?order_unit=box&page=1&per_page=20
GET /distributor/warehouses/{warehouse_id}/stock?order_unit=piece&q=محبس
```

نفس حقول `units` / `filters` / `pagination`.  
البنود فيها `available_qty` / `available_quantity` + `order_unit` + `pieces_per_box`.  
`points_per_unit` = `null`.

---

## 4) ملخص السلة (مجموع النقاط فقط)

قبل `POST /orders` — احسب المجموع بدون كشف نقاط المنتج:

```http
POST /distributor/orders/quote
```

```json
{
  "lines": [
    { "product_id": 42, "boxes": 3 },
    { "product_id": 7, "quantity": 10 }
  ]
}
```

```json
{
  "status": true,
  "data": {
    "total_quantity": 46,
    "total_points": 120,
    "items": [
      {
        "product_id": 42,
        "name": "محبس ½",
        "order_unit": "box",
        "pieces_per_box": 12,
        "boxes": 3,
        "quantity": 36,
        "points_per_unit": null,
        "line_points": null
      },
      {
        "product_id": 7,
        "name": "قطعة فردية",
        "order_unit": "piece",
        "pieces_per_box": null,
        "boxes": null,
        "quantity": 10,
        "points_per_unit": null,
        "line_points": null
      }
    ]
  }
}
```

اعرض في شاشة السلة: **مجموع النقاط = `data.total_points`** فقط.

---

## 5) إنشاء الطلب

```http
POST /distributor/orders
```

**من المصنع:**

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

**من مخزن الدعوة:**

```json
{
  "source": "warehouse",
  "warehouse_id": 91,
  "lines": [
    { "product_id": 42, "boxes": 2 }
  ]
}
```

### رد الطلب

- `total_points` — المجموع الظاهر في التطبيق.
- كل بند: `order_unit`, `boxes`, `quantity`, `pieces_per_box`.
- `points_per_unit` و `line_points` = `null` على البنود.

منتجات الصندوق: لو أرسلت `quantity` بدل `boxes` لازم تكون من مضاعفات `pieces_per_box` وإلا 422.

---

## 6) تدفق شاشة التطبيق المقترح

```
1. GET order-sources
2. اختر factory أو warehouse
3. تبويبان: صناديق | قطع
   - مصنع → GET products?order_unit=…
   - مخزن → GET warehouses/{id}/stock?order_unit=…
4. أضف للسلة (boxes أو quantity حسب order_unit)
5. POST orders/quote → اعرض total_points فقط
6. POST orders → تأكيد الإرسال
7. تتبع: GET orders?scope=placed
```

---

## 7) ملخص المسارات

| Method | Path | غرض |
|--------|------|-----|
| GET | `/distributor/order-sources` | مصنع + مخازن |
| GET | `/distributor/products` | كتالوج + فلتر `order_unit` + باجينيشن |
| GET | `/distributor/products/categories` | تصنيفات |
| GET | `/distributor/warehouses/{id}/stock` | مخزون مخزن + نفس الفلتر |
| POST | `/distributor/orders/quote` | مجموع نقاط السلة فقط |
| POST | `/distributor/orders` | إرسال الطلب |

---

## ملفات مرتبطة

- [MOBILE_SOURCES_RETURNS_REPORTS.md](./MOBILE_SOURCES_RETURNS_REPORTS.md) — مصادر / مرتجعات / تقارير  
- [MOBILE_NETWORK_ORDERS.md](./MOBILE_NETWORK_ORDERS.md) — دورة حياة الطلب و `available_actions`  
- [WAREHOUSE_DAAWA.md](./WAREHOUSE_DAAWA.md) — مخزن الدعوة  
