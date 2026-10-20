# تحديثات الموبايل — 19 و 20 أكتوبر 2026

**للـ React Native فقط** · Base: `https://mg-plastic.com/api/v1/mobile`  
Envelope ثابت: `{ "status": true|false, "message": "…", "data": … }` · أخطاء 422/403 عربية.  
**لا تعرض `points_per_unit` في الكتالوج** (يبقى `null`).

مستندات مرتبطة:
- [MOBILE_HANDOFF.md](./MOBILE_HANDOFF.md) — المرجع الشامل
- [MOBILE_REPUTATION_WARRANTY.md](./MOBILE_REPUTATION_WARRANTY.md) — سمعة/ضمان/تذكيرات
- [MOBILE_DEEP_LINKS.md](./MOBILE_DEEP_LINKS.md) — `/open/{type}/{id}`
- [MOBILE_FEATURES_CHANGELOG.md](./MOBILE_FEATURES_CHANGELOG.md) — بانرات/مجموعات (19)
- [MOBILE_BIOMETRIC_RN.md](./MOBILE_BIOMETRIC_RN.md) — بصمة

---

## أ) مشاركة الفاتورة على واتساب (جديد — 20 أكتوبر) ⭐

كل فاتورة (قطاعي ← سباك · جملة ← قطاعي · مصنع/مخزن ← جملة) قابلة للمشاركة من التطبيق عبر رابط موقّع + `wa.me` لرقم المستلم.

### مسارات API

| دور | Method | Path |
|-----|--------|------|
| قطاعي | GET | `/trader/invoices/{id}/share` |
| قطاعي | POST | `/trader/invoices/{id}/share-whatsapp` |
| قطاعي | GET/POST | `/trader/distributions/{id}/share` · `…/share-whatsapp` |
| جملة | GET | `/distributor/invoices/{id}/share` |
| جملة | POST | `/distributor/invoices/{id}/share-whatsapp` |
| جملة | GET/POST | `/distributor/distributions/{id}/share` · `…/share-whatsapp` |
| سباك | GET/POST | `/plumber/distributions/{id}/share` · `…/share-whatsapp` |

### GET …/share — مثال

```json
{
  "status": true,
  "message": "بيانات مشاركة الفاتورة",
  "data": {
    "can_share": true,
    "share_url": "https://mg-plastic.com/invoices/42/share?signature=…",
    "whatsapp_url": "https://wa.me/218912345678?text=…",
    "deep_link": "https://mg-plastic.com/open/invoice/42",
    "message": "…نص جاهز…",
    "recipient": {
      "name": "محل النور",
      "phone": "0912345678",
      "phone_masked": "******5678"
    },
    "invoice_id": 42,
    "invoice_number": "INV-…"
  }
}
```

### POST …/share-whatsapp

```http
POST /api/v1/mobile/trader/invoices/42/share-whatsapp
Authorization: Bearer …
Content-Type: application/json

{ "phone": "0912345678", "send_alert": false }
```

| حقل | معنى |
|-----|------|
| `phone` | اختياري — إن لم يُرسل يُستخدم رقم الطرف المقابل تلقائيًا |
| `send_alert` | اختياري — إن `true` يعيد إرسال كارت واتساب عبر السيرفر (يخضع للحد اليومي) |

**UX المطلوب في التطبيق:**
1. بعد POS / بيع / استلام فاتورة → زر **مشاركة واتساب**.
2. افتح `whatsapp_url` بـ `Linking.openURL` (يفتح محادثة صاحب الرقم بنص جاهز + رابط الفاتورة).
3. أو اعرض `share_url` في WebView / مشاركة النظام.
4. في قائمة **فواتيري** كل صف يحتوي الآن:
   - `can_share_whatsapp`
   - `share_url`
   - `whatsapp_url`
   - `deep_link` (عند توفره)

### ردود POS بعد البيع (حقول إضافية غير كاسرة)

**قطاعي → سباك** `POST /trader/pos/checkout`:
```json
{
  "distributions": […],
  "total_points": 120,
  "plumber_id": 9,
  "whatsapp_share": { "whatsapp_url": "https://wa.me/…", "share_url": "…", "…" },
  "share_url": "…",
  "whatsapp_url": "https://wa.me/…",
  "new_badges": [],
  "score_delta": 0,
  "level_changed": false
}
```

**جملة → قطاعي** `POST /distributor/pos/checkout`:
```json
{
  "invoices": […],
  "whatsapp_share": { … },
  "share_url": "…",
  "whatsapp_url": "https://wa.me/…"
}
```

صفحة المشاركة العامة (بدون توكن): `GET /invoices/{id}/share?signature=…` — HTML للفاتورة.

---

## ب) السمعة · الشارات · السلسلة · الضمان · التذكيرات (20 أكتوبر)

تفاصيل JSON كاملة: [MOBILE_REPUTATION_WARRANTY.md](./MOBILE_REPUTATION_WARRANTY.md)

### سباك
| Method | Path |
|--------|------|
| GET | `/plumber/reputation` |
| GET | `/plumber/badges` |
| GET | `/plumber/streak` |
| GET/PUT | `/plumber/reminder-preferences` |
| GET | `/plumber/warranties` · `/warranties/{id}` · `/warranties/eligible-items` |
| POST | `/plumber/warranties` (مع `customer_consent: true`) |
| POST | `/plumber/warranties/{id}/resend` |
| GET | `/plumber/warranties/{id}/pdf` |

### قطاعي / جملة
| Method | Path |
|--------|------|
| GET | `/trader/plumbers/{id}/reputation` |
| GET | `/distributor/plumbers/{id}/reputation` |
| GET/PUT | `…/reminder-preferences` |

### عام
| Method | Path |
|--------|------|
| GET | `/w/{code}` — JSON إن `Accept: application/json` وإلا HTML |
| POST | `/w/{code}/claims` — `{ phone_last4, note }` |

### settings
`GET /settings` → `data.reputation` · `data.warranty` · `data.reminders`

### احتفال الشارة
في رد تنفيذ طلب سباك / POS:
```json
{ "new_badges": […], "score_delta": 3, "level_changed": false }
```
وفي `OrderResource` لطلبات السباك: `plumber_badge: { level, top_badges[0..2], streak_weeks }`

### شاشات RN مطلوبة
1. سمعة (دائرة درجة + مستوى + سلسلة + شارات + فتح الضمان).
2. إصدار ضمان: عميل ← منتجات eligible ← موافقة ← نجاح + PDF.
3. احتفال `new_badges`.
4. تفضيلات تذكير.

---

## ج) Deep Links + كروت واتساب (19–20)

| نوع | مثال |
|-----|------|
| HTTPS | `https://mg-plastic.com/open/invoice/42` |
| Scheme | `mgplastic://invoice/42` |

أنواع: `invoice` · `order` · `return` · `points` · `supply` · `withdrawal` · `home`

ثيمات كروت جديدة للمعاينة: `badge_awarded` · `level_up` · `streak` · `warranty_issued` · `reminder_weekly` · `reminder_daily`

---

## د) بانرات · مجموعات · ترويج · فواتيري (19 أكتوبر)

| Feature | Path |
|---------|------|
| بانرات بين سباكين/متاجر | `GET /promo-banners?section=between_plumbers_stores` |
| مجموعات منتجات | `GET /product-collections` · `?kind=` · `/{id}` |
| إعلانات منتجات MG | `GET /product-promotions` |
| أهم موزعين | `GET /featured-wholesalers` |
| فواتيري جملة | `GET /distributor/my-invoices` · `?scope=sales\|purchases\|all` |
| فواتيري قطاعي | `GET /trader/my-invoices` |
| مخزن = كتالوج مصنع | `GET /distributor/warehouses/{id}/stock` (`same_as_factory`) |
| بصمة | `/biometric/challenge` · `verify` · `register` · `devices` |

---

## هـ) خريطة أزرار المشاركة في الواجهة

```
بعد POS قطاعي→سباك     → زر واتساب (whatsapp_url من الرد)
بعد POS جملة→قطاعي     → زر واتساب (whatsapp_url من الرد)
شاشة فواتيري (جملة)    → لكل فاتورة: can_share_whatsapp + زر مشاركة
شاشة فواتيري (قطاعي)   → نفس الشيء
تفاصيل توريد سباك      → /plumber/distributions/{id}/share-whatsapp
فاتورة مشتريات من مخزن → /distributor/invoices/{id}/share-whatsapp
```

تدفق الزر:
1. `POST …/share-whatsapp` (أو استخدم `whatsapp_url` الجاهز من القائمة).
2. `Linking.openURL(data.whatsapp_url)`.
3. المستخدم يضغط إرسال في واتساب لصاحب الرقم.

---

## و) Checklist تنفيذ RN (19+20)

- [ ] بانرات `between_plumbers_stores` + مجموعات + ترويج + featured
- [ ] فواتيري جملة/قطاعي + مرتجعات
- [ ] بصمة (challenge/verify/register)
- [ ] Deep links `/open/{type}/{id}`
- [ ] **مشاركة فاتورة واتساب** (زر + Linking على `whatsapp_url`)
- [ ] شاشة سمعة + شارات + سلسلة
- [ ] شاشة ضمان (eligible → إصدار → PDF/مشاركة)
- [ ] احتفال `new_badges` / `level_changed`
- [ ] `plumber_badge` على طلبات السباك عند القطاعي
- [ ] تفضيلات التذكير
- [ ] لا تعرض `points_per_unit` في الكتالوج
- [ ] اعتمد `can_issue` من `unlock_warranty`

---

## ز) أوامر باكند (مرجع سيرفر — ليس للموبايل)

```bash
php artisan migrate --force
php artisan db:seed --class=BadgeSeeder --force
php artisan config:clear && php artisan route:clear
php artisan whatsapp:preview --all
```

تذكيرات واتساب: `REMINDERS_ENABLED=false` و `REMINDERS_ADMIN_ONLY=true` افتراضيًا حتى التفعيل.
