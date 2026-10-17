# MG Plastic — دليل تطبيق المحل (جملة + قطاعي)

ملف التسليم لفريق الموبايل: كتالوج MG، اقتراحات البحث، معرض الأعمال، منتجات العرض مع أنيميشن، ولوحة تحكم النقاط.

| | |
|--|--|
| **Production** | `https://mg-plastic.com/api` |
| **OpenAPI** | `GET /docs/api` |
| **تاريخ** | 2026-10-17 |
| **مرجع عام** | [MOBILE_HANDOFF.md](./MOBILE_HANDOFF.md) |

```http
Authorization: Bearer {token}
Accept: application/json
Accept-Language: ar
Content-Type: application/json
```

| دور | Prefix |
|-----|--------|
| قطاعي | `/api/v1/mobile/trader` |
| جملة | `/api/v1/mobile/distributor` |

---

## 1) كتالوج منتجات MG (مصنع / مخزن)

نفس الكتالوج للجملة (طلب من المصنع) والقطاعي (طلب من الجملة).

| Method | Path |
|--------|------|
| GET | `…/products` |
| GET | `…/products/categories` |

Query: `?category_id=` · `?q=` / `?search=` · `?has_points=1|0` · `?per_page=` · `?tags=`

```json
{
  "status": true,
  "message": "كتالوج منتجات MG — للمصنع والمخزن",
  "data": {
    "items": [{
      "id": 42,
      "name": "ماسورة",
      "description": "…",
      "image_url": "https://…",
      "points_per_unit": null,
      "has_points": true,
      "product_kind": "mg_points",
      "is_mg": true,
      "awards_points": true,
      "category_id": 1,
      "category_name": "مواسير"
    }],
    "pagination": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 48 }
  }
}
```

| `product_kind` | المعنى |
|----------------|--------|
| `mg_points` | MG بنقاط ولاء |
| `mg_no_points` | MG يُطلب **بدون** نقاط |

**طلب الجملة للمصنع:** `POST /distributor/orders` بـ `{ "lines":[{product_id,quantity}] }`  
**طلب القطاعي للجملة:** `POST /trader/orders` بـ `{ "supplier_id", "lines":[...] }`  
قبل الطلب: `GET /trader/wholesalers/{id}/stock`

---

## 2) اقتراحات بحث سريعة (Typeahead)

```http
GET /api/v1/mobile/suggest?q=مفتاح&type=plumber&limit=8
```

| Param | القيم | افتراضي حسب الدور |
|-------|-------|-------------------|
| `q` / `search` | نص البحث (حرف واحد يكفي) | مطلوب |
| `type` | `plumber` · `retail_trader` · `wholesale_distributor` · `store` · `auto` | auto |
| `limit` | 1–20 | 8 |

```json
{
  "status": true,
  "data": {
    "q": "مفتاح",
    "type": "plumber",
    "items": [{
      "id": 12,
      "type": "plumber",
      "role": "plumber",
      "name": "مفتاح محمد",
      "brand_name": null,
      "display_name": "مفتاح محمد",
      "phone": "0912…",
      "network_code": "MG-P-000012",
      "profile_photo": "https://…",
      "subtitle": "MG-P-000012 · 0912…"
    }]
  }
}
```

### متى تستخدم أي `type`؟

| شاشة | type | ثم |
|------|------|-----|
| قطاعي يضيف سباك | `plumber` | `POST /trader/plumbers/link` بـ `network_code` أو `code` أو `phone` |
| جملة يضيف قطاعي | `retail_trader` | `POST /distributor/retail-traders/link` |
| جملة يضيف سباك تحت قطاعي | `plumber` | `POST /distributor/plumbers/link` + `retail_trader_id` |
| سباك يبحث محل | `store` | افتح `/network-stores/{id}` |

Debounce مقترح في الموبايل: **250–300ms** بعد توقف الكتابة.

القوائم الكاملة ما زالت تدعم `?search=` / `?q=`:
- `/trader/plumbers?search=`
- `/distributor/plumbers?search=`
- `/distributor/retail-traders?q=`
- `/api/v1/network-stores?q=`

---

## 3) معرض الأعمال = Gallery + Slider فقط

صور واجهة المحل للعرض العام يجب أن تكون من نوعين فقط:

| نوع الموبايل | kind في API | المسار المختصر |
|--------------|-------------|----------------|
| **Slider / Carousel** | `banner` (alias: `slider`, `carousel`) | `GET/POST …/slider` |
| **Gallery / Casual / معرض أعمال** | `gallery` (alias: `causal`, `casual`) | `GET/POST …/gallery` |

### مسارات الموبايل (جملة وقطاعي)

```http
GET    /api/v1/mobile/{trader|distributor}/slider
POST   /api/v1/mobile/{trader|distributor}/slider          # multipart: media[]
PUT    /api/v1/mobile/{trader|distributor}/slider/order     # ترتيب
DELETE /api/v1/mobile/{trader|distributor}/slider/{mediaId}

GET    /api/v1/mobile/{trader|distributor}/gallery
POST   /api/v1/mobile/{trader|distributor}/gallery         # multipart: media[]
DELETE /api/v1/mobile/{trader|distributor}/gallery/{mediaId}

GET    /api/v1/mobile/{trader|distributor}/media            # الكل مجمّع
POST   /api/v1/mobile/{trader|distributor}/media            # kind=slider|gallery|…
```

```http
POST …/slider
Content-Type: multipart/form-data

media[]=<image>
# أو عبر /media:
kind=slider
media[]=<image>
```

```http
POST …/gallery
Content-Type: multipart/form-data

media[]=<image>
# aliases مقبولة في kind: gallery | causal | casual
```

في البروفايل العام للمحل (`GET /api/v1/network-stores/{id}`) الحقول:
- `slider` / `banners`
- `gallery`

> لا تخلط معرض الأعمال مع `my_products` (منتجات العرض بدون نقاط).

---

## 4) منتجات غير MG (بدون نقاط) + أنيميشن

منتجات يعرضها المحل في بروفايله — **ليست** من كتالوج MG ولا عليها نقاط ولاء.

| Method | Path |
|--------|------|
| GET | `…/my-products` |
| POST | `…/my-products` |
| PUT\|PATCH\|POST | `…/my-products/{mediaId}` |
| DELETE | `…/my-products/{mediaId}` |

### إنشاء

```http
POST …/my-products
Content-Type: multipart/form-data

image=<file>
name=منتج عرض
description=اختياري
animation=fade
```

### قيم `animation` المسموحة

| قيمة | استخدام مقترح في الموبايل |
|------|---------------------------|
| `none` | بدون حركة |
| `fade` | افتراضي |
| `slide_up` | ظهور من الأسفل |
| `slide_left` | ظهور جانبي |
| `zoom_in` | تكبير خفيف |
| `bounce` | نبضة دخول |
| `pulse` | نبض مستمر خفيف |

### Response item

```json
{
  "id": 88,
  "kind": "my_product",
  "name": "منتج عرض",
  "title": "منتج عرض",
  "description": "…",
  "image_url": "https://…",
  "has_points": false,
  "awards_points": false,
  "is_mg": false,
  "product_kind": "shop",
  "animation": "fade",
  "orderable_without_points": true,
  "sort_order": 0,
  "is_active": true
}
```

**مهم:** هذه المنتجات للعرض في بروفايل المحل فقط — الطلبات الشبكية من كتالوج MG.

---

## 5) لوحة تحكم النقاط (لكل محل)

شاشة احترافية: إجمالي / موزّع / متبقي + نسب + مخزون.

| Method | Path |
|--------|------|
| GET | `/api/v1/mobile/trader/points-panel` |
| GET | `/api/v1/mobile/distributor/points-panel` |
| GET | `…/dashboard` | يتضمن نفس `points_panel` داخل الرد |

```json
{
  "status": true,
  "message": "لوحة تحكم النقاط",
  "data": {
    "role": "retail_trader",
    "title": "لوحة تحكم النقاط",
    "subtitle": "ملخص النقاط والمخزون لطلبك",
    "total_points": 1200,
    "distributed_points": 450,
    "remaining_points": 750,
    "percent_distributed": 38,
    "percent_remaining": 62,
    "cards": [
      {
        "key": "total",
        "label": "إجمالي النقاط المستلمة",
        "value": 1200,
        "color": "primary",
        "icon": "points_total"
      },
      {
        "key": "distributed",
        "label": "النقاط الموزّعة",
        "value": 450,
        "color": "warning",
        "icon": "points_out"
      },
      {
        "key": "remaining",
        "label": "النقاط المتبقية",
        "value": 750,
        "color": "success",
        "icon": "points_wallet"
      }
    ],
    "stock": {
      "products": 18,
      "units": 640,
      "points_in_stock": 910
    },
    "network": {
      "plumbers_count": 7
    },
    "updated_at": "2026-10-17T15:00:00+00:00"
  }
}
```

للجملة: `cards[0].label` = «إجمالي نقاط المصنع» و`network.retail_traders_count`.

### إرشاد UI للموبايل

1. هيدر بعنوان `title` + `subtitle`
2. ثلاث بطاقات من `cards[]` بألوان `primary` / `warning` / `success`
3. شريط تقدّم مزدوج أو دائري من `percent_distributed` / `percent_remaining`
4. صف ثانٍ: مخزون (`stock`) + شبكة (`network`)
5. اسحب للتحديث → أعد استدعاء `points-panel`

---

## 6) Cheat sheet سريع

```
Suggest:     GET  /api/v1/mobile/suggest?q=&type=&limit=

MG Catalog:  GET  /{trader|distributor}/products
             GET  /{trader|distributor}/products/categories

Points:      GET  /{trader|distributor}/points-panel
             GET  /{trader|distributor}/dashboard   (+ points_panel)

Shop SKUs:   CRUD /{trader|distributor}/my-products  (+ animation)

Visuals:     CRUD /{trader|distributor}/slider
             CRUD /{trader|distributor}/gallery
```

---

## 7) Checklist تكامل الموبايل

- [ ] كتالوج MG للجملة والقطاعي + فلتر تصنيفات
- [ ] Typeahead عبر `/suggest` (debounce 300ms)
- [ ] معرض الأعمال = `gallery` + `slider` فقط
- [ ] منتجات العرض `my-products` بدون نقاط + `animation`
- [ ] شاشة لوحة النقاط من `points-panel` / `dashboard.points_panel`
- [ ] ألوان البطاقات حسب `cards[].color`
