# السمعة · الشارات · السلسلة · الضمان · التذكيرات

وثيقة تسليم لفريق React Native — MG للصناعات البلاستيكية.

**قاعدة ذهبية:** لا تعرض `points_per_unit` في الكتالوج (يبقى `null`). اعتمد `can_issue` / `requirements` من الـ API. كل الأخطاء 422/403 بالعربية داخل `{status,message,errors}`.

---

## 1) الإعدادات العامة

`GET /api/v1/mobile/settings`

```json
{
  "data": {
    "reputation": {
      "enabled": true,
      "levels": { "bronze": {"min":0,"max":39,"label":"برونزي"}, "silver": {}, "gold": {}, "diamond": {} },
      "weights": { "volume": 40, "consistency": 25, "returns": 15, "warranty": 10, "tenure": 10 }
    },
    "warranty": {
      "enabled": true,
      "min_level": "gold",
      "min_score": 70,
      "min_distributions": 5,
      "default_months": 12
    },
    "reminders": { "enabled": false }
  }
}
```

---

## 2) سمعة السباك

`GET /api/v1/mobile/plumber/reputation` (Bearer · دور plumber)

```json
{
  "data": {
    "score": 72,
    "level": "gold",
    "level_label": "ذهبي",
    "next_level": { "name": "diamond", "label": "ماسي", "points_needed": 13 },
    "components": [{ "key": "volume", "value": 80, "weight": 40 }],
    "rank_percentile": null,
    "streak": {
      "current_weeks": 4,
      "best_weeks": 6,
      "freezes_available": 1,
      "week_active": true,
      "ends_at": "2026-10-24T23:59:59+02:00"
    },
    "badges": {
      "earned": [{ "id": 1, "key": "first_order", "name_ar": "أول طلب", "tier": "common", "icon": "star" }],
      "locked": [{ "key": "orders_10", "progress": { "current": 3, "target": 10, "percent": 30 } }]
    },
    "unlock_warranty": {
      "can_issue": false,
      "requirements": [
        { "key": "level", "label": "المستوى gold أو أعلى", "current": "silver", "target": "gold", "done": false },
        { "key": "distributions", "label": "توزيعات مؤكدة", "current": 3, "target": 5, "done": false }
      ]
    }
  }
}
```

### UX شاشة السمعة
- دائرة الدرجة (0–100) + لون المستوى رمادي/أسود (ماسي بإبراز خفيف).
- سلسلة بلهب + `freezes_available`.
- شارات مكتسبة / مقفلة مع شريط `progress`.
- بطاقة «فتح الضمان» من `unlock_warranty`.

---

## 2b) الشارات فقط

`GET /api/v1/mobile/plumber/badges`

```json
{
  "status": true,
  "message": "الشارات",
  "data": {
    "earned": [
      {
        "id": 1,
        "key": "first_order",
        "name_ar": "أول طلب",
        "description_ar": "أول توزيع مؤكد",
        "tier": "common",
        "icon": "star"
      }
    ],
    "locked": [
      {
        "id": 2,
        "key": "orders_10",
        "name_ar": "10 طلبات",
        "description_ar": "10 توزيعات مؤكدة",
        "tier": "common",
        "icon": "star",
        "progress": { "current": 3, "target": 10, "percent": 30 }
      }
    ]
  }
}
```

`tier`: `common` | `rare` | `epic` | `legendary`

---

## 2c) السلسلة فقط

`GET /api/v1/mobile/plumber/streak`

```json
{
  "status": true,
  "message": "السلسلة",
  "data": {
    "current_weeks": 4,
    "best_weeks": 6,
    "freezes_available": 1,
    "week_active": true,
    "ends_at": "2026-10-24T23:59:59+02:00"
  }
}
```

| حقل | معنى |
|-----|------|
| `current_weeks` | السلسلة الحالية |
| `best_weeks` | أفضل سلسلة |
| `freezes_available` | فرص التجميد المتبقية |
| `week_active` | هل نشط هذا الأسبوع (سبت→جمعة طرابلس) |
| `ends_at` | نهاية الأسبوع الحالي |

---

## 3) الضمان

| Method | Path | ملاحظة |
|--------|------|--------|
| GET | `/plumber/warranties/eligible-items` | منتجات من فواتيره المؤكدة فقط |
| GET | `/plumber/warranties?status=` | قائمة |
| GET | `/plumber/warranties/{id}` | تفاصيل |
| POST | `/plumber/warranties` | إصدار |
| POST | `/plumber/warranties/{id}/resend` | مرة / 24س |
| GET | `/plumber/warranties/{id}/pdf` | تحميل شهادة |

### eligible-items
```json
{
  "data": {
    "items": [
      {
        "distribution_id": 12,
        "product_id": 5,
        "name": "مواسير 20مم",
        "remaining_quantity": 3,
        "warranty_months": 12
      }
    ]
  }
}
```

### إصدار
```http
POST /api/v1/mobile/plumber/warranties
{
  "customer_name": "محمد",
  "customer_phone": "0912345678",
  "work_description": "تركيب حمام",
  "site_address": "طرابلس",
  "city_id": 1,
  "customer_consent": true,
  "items": [{ "distribution_id": 12, "product_id": 5, "quantity": 2 }]
}
```

```json
{
  "status": true,
  "data": {
    "id": 1,
    "code": "MGW-AB12CD34",
    "verify_url": "https://…/w/MGW-AB12CD34",
    "pdf_url": "https://…/storage/warranties/MGW-AB12CD34.html",
    "card_url": "…",
    "whatsapp_status": "sent"
  }
}
```

**خطوات الشاشة:** بيانات العميل ← اختيار من eligible-items ← checkbox موافقة العميل ← نجاح + مشاركة PDF/رابط.

### عام بدون مصادقة
- `GET /w/{code}` — JSON إن `Accept: application/json` وإلا HTML RTL.
- `POST /w/{code}/claims` — `{phone_last4, note}` (throttle).

---

## 4) تفضيلات التذكير

`GET|PUT /api/v1/mobile/plumber/reminder-preferences`  
نفس المسار تحت `/trader` و `/distributor`.

```json
{
  "weekly_enabled": true,
  "daily_points_enabled": true,
  "quiet_hours": { "start": 23, "end": 7 }
}
```

---

## 5) احتفال الشارة / المستوى

تظهر داخل `data` بعد:

| متى | المسار |
|-----|--------|
| بيع POS قطاعي→سباك | `POST /trader/pos/checkout` |
| تنفيذ طلب سباك | `POST /trader/orders/{id}/fulfill` |

```json
{
  "status": true,
  "data": {
    "new_badges": [
      { "id": 2, "key": "orders_10", "name_ar": "10 طلبات", "tier": "common", "icon": "star" }
    ],
    "score_delta": 3,
    "level_changed": false
  }
}
```

| حقل | استخدام |
|-----|---------|
| `new_badges[]` | احتفال إن `length > 0` |
| `score_delta` | فرق الدرجة بعد إعادة الحساب |
| `level_changed` | `true` → انتقال مستوى |

### شارة السباك للتاجر (`plumber_badge`)
في ردود الطلبات عندما `channel = retail_to_plumber`:

```json
{
  "id": 55,
  "channel": "retail_to_plumber",
  "requester": { "id": 9, "name": "أحمد الترهوني" },
  "plumber_badge": {
    "level": "gold",
    "top_badges": [
      { "key": "trusted_pro", "name_ar": "محترف موثوق", "tier": "epic", "icon": "badge" }
    ],
    "streak_weeks": 4
  },
  "items": [{ "points_per_unit": null, "line_points": null }]
}
```

نظرة سريعة بدون فتح الطلب:
`GET /trader/plumbers/{id}/reputation` · `GET /distributor/plumbers/{id}/reputation`

```json
{
  "data": {
    "id": 9,
    "name": "أحمد الترهوني",
    "score": 72,
    "level": "gold",
    "level_label": "ذهبي",
    "badges": [],
    "active_warranties": 2,
    "plumber_badge": { "level": "gold", "top_badges": [], "streak_weeks": 4 }
  }
}
```

---

## 6) قواعد UX
1. لا تعرض نقاط الوحدة في الكتالوج.
2. لا تفعّل زر الضمان إلا إذا `unlock_warranty.can_issue === true`؛ اعرض المتبقي من `requirements`.
3. وافق العميل إلزامي (`customer_consent`).
4. لا تخزّن هاتف العميل كاملًا في واجهات عامة — استخدم القناع من الـ API.
5. Deep links للتذكيرات: `/open/home/0` و `/open/points/0`.
