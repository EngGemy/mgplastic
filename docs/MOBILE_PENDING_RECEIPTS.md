# حلقة تأكيد الاستلام — دليل مطوّر الموبايل

> الباك إند جاهز. نفّذ الشاشات والتنقّل حسب هذا الملف قبل الربط النهائي.

**Base:** `/api/v1/mobile`  
**Auth:** `Authorization: Bearer {token}`  
**Deep link الموحّد:** `mgplastic://pending-receipts`  
**اختياري للتفاصيل:** `mgplastic://pending-receipts/{id}`

مرتبط: [MOBILE_BUYER_RECEIPT.md](./MOBILE_BUYER_RECEIPT.md) · [HOME_LAYOUT_API.md](./HOME_LAYOUT_API.md) · [MOBILE_POINTS_RATE_HINT.md](./MOBILE_POINTS_RATE_HINT.md) · [MOBILE_PUSH_FCM.md](./MOBILE_PUSH_FCM.md)

---

## 1) ليه مهمة؟

فاتورة/توزيع من البائع تبقى `draft` / `pending_review` حتى **المستلم** يأكد الاستلام.

| بدون التأكيد | بعد `POST …/receive` |
|--------------|----------------------|
| نقاط السباك مش بتتثبت | النقاط تدخل المحفظة |
| مخزون القطاعي ناقص رغم إن البائع «باع» | الكميات تدخل مخزون المستلم |
| شارة الرئيسية والإشعار يفتحوا على فراغ | شاشة «بانتظار الاستلام» → تأكيد |

```
بائع (POS / شحن طلب)
        ↓
فاتورة/توزيع draft
        ↓
شارة على الرئيسية (badge_count) + إشعار (deep_link)
        ↓
شاشة pending-receipts → زر تأكيد
        ↓
POST receive → مخزون + نقاط
```

---

## 2) Checklist تنفيذ التطبيق

- [ ] تسجيل deep link: `mgplastic://pending-receipts` (+ اختياري `…/{id}`)
- [ ] على الرئيسية: قراءة قسم `type = pending_receipts`
  - لو القسم **مش موجود** في `sections` → لا تعرض الشريط (العدد صفر)
  - لو موجود → اعرض الشارة من `badge_count` (أو `items[0].badge_count`)
  - الضغط → `settings.deep_link` أو `items[0].deep_link` / `link`
- [ ] في `shop_quick_links` / `quick_actions`: بلاطة `key = pendingReceipts` → اعرض `badge_count` لو > 0
- [ ] شاشة القائمة: `GET …/pending-receipts` حسب الدور
- [ ] زر «تأكيد الاستلام» فقط لو `available_actions` فيها `receive`
- [ ] بعد نجاح `receive`: ارجع للرئيسية أو حدّث القائمة؛ الشارة لازم تتصفر (الباك يحدّث العدد فوراً)
- [ ] اعرض `points_rate_hint` على البنود لو موجود (انظر [MOBILE_POINTS_RATE_HINT.md](./MOBILE_POINTS_RATE_HINT.md))
- [ ] الإشعارات / FCM: افتح عبر `deep_link` أو `screen = pending-receipts` — **لا تخمّن من `order_id` وحده**

---

## 3) الرئيسية — بدون طلب زيادة

```http
GET /api/v1/mobile/home/layout
Authorization: Bearer …
```

### قسم موجود (فيه معلّق)

```json
{
  "key": "pending_receipts",
  "type": "pending_receipts",
  "title": null,
  "is_active": true,
  "badge_count": 2,
  "settings": {
    "link": "mgplastic://pending-receipts",
    "deep_link": "mgplastic://pending-receipts"
  },
  "items": [
    {
      "id": "pending",
      "title": "لديك 2 فواتير بانتظار تأكيد الاستلام",
      "subtitle": "اضغط للتأكيد حتى يدخل المخزون والنقاط",
      "badge_count": 2,
      "link": "mgplastic://pending-receipts",
      "deep_link": "mgplastic://pending-receipts"
    }
  ]
}
```

### العدد صفر

القسم **لا يُرجَع** داخل `sections` (مش `items: null` مع شارة صفر).

### بلاطة سريعة (قطاعي)

في `shop_quick_links` عنصر `pendingReceipts` قد يحمل:

```json
{
  "key": "pendingReceipts",
  "title": "بضاعة بانتظار الاستلام",
  "link": "mgplastic://pending-receipts",
  "deep_link": "mgplastic://pending-receipts",
  "badge_count": 2
}
```

---

## 4) قائمة + تأكيد

### قطاعي

| | |
|--|--|
| قائمة | `GET /api/v1/mobile/trader/pending-receipts` |
| تأكيد | `POST /api/v1/mobile/trader/invoices/{invoiceId}/receive` |
| بديل | `POST /api/v1/mobile/trader/distributions/{distributionId}/receive` |

رد القائمة:

```json
{
  "data": {
    "pending_count": 1,
    "items": [
      {
        "id": 901,
        "number": "…",
        "status": "pending_review",
        "pending_receipt": true,
        "distribution_id": 77,
        "available_actions": ["receive"],
        "wholesaler": { "id": 3, "brand_name": "…" },
        "items": [
          {
            "product_id": 42,
            "name": "…",
            "quantity": 24,
            "points_rate_changed": false,
            "points_rate_hint": null
          }
        ]
      }
    ]
  }
}
```

### سباك

| | |
|--|--|
| قائمة | `GET /api/v1/mobile/plumber/pending-receipts` |
| تأكيد | `POST /api/v1/mobile/plumber/distributions/{distributionId}/receive` |

بعد التأكيد: `points_earned` + `balance_points` (+ سمعة إن وُجدت).

### طلبات الشبكة (مشتري)

لو `available_actions` على الطلب فيها `receive`:

```http
POST /api/v1/mobile/{role}/orders/{id}/receive
```

`role` = `trader` | `distributor` | `plumber`

---

## 5) إشعارات و FCM

في قائمة الإشعارات وفي data الـ push ستجد:

| حقل | قيمة متوقعة لاستلام معلّق |
|-----|---------------------------|
| `screen` | `pending-receipts` |
| `deep_link` | `mgplastic://pending-receipts` |
| `order_id` | قد يوجد (شحن طلب) — استخدمه كمرجع فقط |

**قاعدة التنقّل:**

1. لو `deep_link` موجود → افتحه كما هو  
2. وإلا لو `screen == pending-receipts` → `mgplastic://pending-receipts`  
3. وإلا المسارات القديمة (`order` / `invoice` / …)

مثال FCM data:

```json
{
  "type": "order.shipped",
  "screen": "pending-receipts",
  "deep_link": "mgplastic://pending-receipts",
  "order_id": "40",
  "entity_id": "40",
  "inbox_id": "123"
}
```

---

## 6) حالات UI

| إشارة من الـ API | سلوك التطبيق |
|------------------|--------------|
| `pending_receipt: true` | لسه محتاج تأكيد |
| `available_actions: ["receive"]` | أظهر زر التأكيد |
| توزيع `draft` / فاتورة `pending_review` | معلّق |
| بعد receive: `pending_receipt: false` | أخفِ الزر وحدّث الرئيسية |
| `points_rate_hint` غير null | بانر/سطر تلميح تحت البند |

لا تعتمد على اكتمال شاشة البائع وحده — لازم شاشة المستلم.

---

## 7) اختبار يدوي سريع

1. جملة → POS قطاعي → على تطبيق القطاعي: شارة على الرئيسية + عنصر في `pending-receipts`
2. تأكيد استلام → الشارة تختفي / `badge_count` يختفي من layout · المخزون يزيد
3. قطاعي → POS سباك → على تطبيق السباك: شارة + قائمة · بعد receive النقاط تزيد
4. اضغط إشعار شحن طلب → يفتح `pending-receipts` مش شاشة عامة فارغة

---

## ملخص Endpoints

| دور | قائمة | تأكيد |
|-----|-------|--------|
| قطاعي | `GET …/trader/pending-receipts` | `POST …/trader/invoices/{id}/receive` |
| سباك | `GET …/plumber/pending-receipts` | `POST …/plumber/distributions/{id}/receive` |
| مشترٍ على طلب | `available_actions` على الطلب | `POST …/orders/{id}/receive` |
| الكل | `GET …/home/layout` → `badge_count` | — |
