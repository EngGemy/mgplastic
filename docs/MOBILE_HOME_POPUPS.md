# بوب أب الرئيسية (Home Popup)

إعلان يظهر **أول ما يفتح الرئيسية**: صورة، عنوان ونص، وزرار واحد أو أكتر (أو تصويت).

**لوحة التحكم → بوب أب الرئيسية → إدارة البوب أب**

> التطبيق بيقرأ `data.popup` من `home/layout`. لو `null` مفيش عرض — مفيش كسر للنسخ القديمة.  
> عشان تشيله: وقّفه من اللوحة → السيرفر يبعت `popup: null`.

---

## 1) فين يتبعت؟ — جوه `layout` (مفيش request جديد مطلوب)

```http
GET /api/v1/mobile/home/layout?role={role}
Authorization: Bearer {token}     # مش عند الزائر
Accept-Language: ar | en
```

| role | مين |
|------|-----|
| `guest` | زائر، من غير token |
| `plumber` | سباك |
| `retail_trader` | تاجر قطاعي |
| `vendor` | محل — **يتعامل زي** `retail_trader` |
| `wholesale_distributor` | موزع جملة |

- لو فيه token: الدور من التوكن.
- لو مفيش: `guest`.

حقل جوه `data`:

```json
"popup": { ... } | null
```

Compat (اختياري): `GET /api/v1/mobile/home/popup` يرجع نفس شكل الـ popup فقط.

اكتشاف:

```http
GET /api/v1/mobile/settings
→ data.home.layout
→ data.home.popup
→ data.home.popup_event   # POST …/home/popup/{id}/event
```

---

## 2) شكل `popup`

```json
{
  "id": 7,
  "version": 3,
  "kind": "announcement",
  "image_url": "https://mg-plastic.com/storage/home-popups/….jpg",
  "image_ratio": 0.8,
  "title": "سباك الشهر",
  "body": "أحمد علي — أعلى تقييم في بنغازي.",
  "buttons": [
    { "id": "visit", "text": "زور صفحة السباك", "link": "mgplastic://plumber/45", "style": "primary" },
    { "id": "facebook", "text": "صفحتنا على فيسبوك", "link": "https://facebook.com/mgplastic", "style": "outline" }
  ],
  "dismissible": true,
  "dismiss_text": "لاحقاً",
  "show": "once"
}
```

السيرفر بيختار النصوص حسب `Accept-Language` (نص واحد).  
Legacy: لو `buttons` فاضية وما زال فيه `button_text` / `button_link` بيتبعتوا كمان.

### الحقول

| الحقل | مطلوب | وصف |
|-------|-------|-----|
| `id` | ✅ | رقم البوب أب |
| `version` | ✅ | يزيد لما الأدمن يفعّل «اعرضه تاني للي شافوه» |
| `kind` | | `announcement` (افتراضي) أو `poll` |
| `image_url` | | الصورة — التطبيق يستنى التحميل قبل العرض |
| `image_ratio` | | عرض÷طول · افتراضي `0.8` · من `0.5` لـ `2` |
| `title` / `body` | | نصوص |
| `buttons` | | أزرار بالترتيب |
| `dismissible` | | افتراضي `true` |
| `dismiss_text` | | نص «لاحقاً» |
| `show` | | `once` \| `daily` \| `always` |

**poll:** بعد الإجابة مش يظهر تاني لنفس `version` (منطق التطبيق). `dismissible: false` يجبر الاختيار.

---

## 3) الأزرار

| حقل | وصف |
|-----|-----|
| `id` | مفتاح الإحصائيات / إجابة التصويت |
| `text` | نص الزرار |
| `link` | وجهة أو `null` (تصويت) |
| `style` | `primary` \| `outline` \| `ghost` |

- أي زرار → يقفل البوب أب + يبعت `click`.
- الصورة = نفس أول زرار ليه `link`.
- السيرفر بيشيل الأزرار اللي وجهتها مش مناسبة للدور أو الهدف اتحذف.

### مثال تصويت

```json
{
  "id": 20, "version": 1, "kind": "poll", "dismissible": false, "show": "always",
  "image_url": "https://…/pipes.jpg", "image_ratio": 1.78,
  "title": "هل جربت مواسير MG الجديدة؟",
  "buttons": [
    { "id": "yes", "text": "نعم", "link": null, "style": "primary" },
    { "id": "no", "text": "لا", "link": null, "style": "outline" }
  ]
}
```

---

## 4) الوجهات (`link`) — ملخص

- حساب: `mgplastic://plumber/{id}` · `shop/{id}` (كل الأدوار + زائر)
- خارجي: `https://…` · `https://wa.me/…` · `tel:` · `mailto:`
- زائر: `mgplastic://register` · `mgplastic://login`
- مشترك: `product` · `promotion` · `collection` · `event` · `category` · `catalog` · `list/*` · `support` · `home` · `terms` · `privacy`
- مسجّل فقط: `wallet` · `points` · `notifications` · `pending-receipts` · `order/{id}` · `returns` · `profile`
- سباك / قطاعي / جملة: نفس جداول HOME_LAYOUT_API

---

## 5) الإحصائيات

```http
POST /api/v1/mobile/home/popup/{id}/event
Authorization: Bearer {token}   # اختياري
Content-Type: application/json

{
  "event": "view",
  "button_id": "yes",
  "version": 3,
  "device_id": "lq3k9x-4f7a2c"
}
```

| event | امتى |
|-------|------|
| `view` | ظهر على الشاشة |
| `click` | زرار أو صورة (+ `button_id`) |
| `dismiss` | × / لاحقاً / برة / رجوع |

الرد: `204`. التطبيق مش بيستنى. اللوحة بتعرض مشاهدات · ضغطات · CTR · نتائج التصويت.

---

## 6) Checklist للتطبيق

- [ ] اقرأ `data.popup` من `home/layout` (مش request منفصل مطلوب)
- [ ] `null` → مفيش عرض
- [ ] انتظر `image_url` قبل الفتح
- [ ] طبّق `show` + `version` + منطق `poll`
- [ ] ابعت `view` / `click` / `dismiss`
- [ ] دعم `register` / `login` و https خارجي
