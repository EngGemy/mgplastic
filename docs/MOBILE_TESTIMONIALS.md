# آراء العملاء (Testimonials) — موقع + تطبيق

**تاريخ:** 20 أكتوبر 2026  
**جمهور:** React Native + ويب  
**عرض:** نهاية تجربة التطبيق (شاشة Home / بعد الكتالوج) + قسم في الموقع العام.

شهادات إيجابية **5 نجوم** عن منتجات MG، بأسماء ليبية (سباكون · تجّار · موزّعون · أصحاب مشاريع · مهندسون). يُولَّد جزء منها يوميًا عبر Gemini (مع قوالب احتياطية).

---

## 1) API الموبايل (عام — بدون توكن)

```http
GET /api/v1/mobile/testimonials?limit=24
```

### مثال رد

```json
{
  "status": true,
  "message": "آراء العملاء عن منتجات MG",
  "data": {
    "enabled": true,
    "animation": {
      "style": "marquee_cards",
      "autoplay": true,
      "pause_on_touch": true,
      "stars": 5
    },
    "items": [
      {
        "id": 1,
        "name": "أحمد الترهوني",
        "role": "سباك محترف",
        "role_key": "plumber",
        "city": "طرابلس",
        "body": "أنابيب PVC ضغط من MG غيّرت جودة شغلي…",
        "rating": 5,
        "product_hint": "أنابيب PVC ضغط",
        "avatar_initials": "أت",
        "is_featured": true,
        "published_at": "2026-10-20T09:15:00+02:00"
      }
    ]
  }
}
```

| حقل | استخدام في RN |
|-----|----------------|
| `name` / `avatar_initials` | رأس الكرت + دائرة أحرف |
| `role` + `city` | سطر ثانٍ رمادي |
| `body` | نص الشهادة |
| `rating` | دائمًا `5` — اعرض ★★★★★ |
| `product_hint` | شريحة منتج أسفل الكرت |
| `is_featured` | إطار/تمييز خفيف |
| `animation` | إرشاد للعرض (ماركي أفقي + توقف عند اللمس) |

---

## 2) أين تعرض في التطبيق

اقترح موضعين (نفس الـ API):

1. **Home** — قسم «ماذا يقول المتعاملون» بعد البانرات/المجموعات.
2. **نهاية التدفق** — بعد الكتالوج أو بعد إتمام طلب/POS كشريط ثقة.

### UX مقترح (مطابق للموقع)

- كروت أفقية تتحرك تلقائيًا (marquee / carousel لا نهائي).
- توقف عند اللمس/السحب للقراءة.
- نجوم ذهبية تظهر بتتابع خفيف.
- خلفية داكنة أو بطاقات بيضاء حسب ثيم التطبيق — المهم حركة واضحة ونجوم 5.
- لا تعرض أرقام هواتف أو أسعار داخل النص (الباك يمنع الروابط والأرقام الحساسة في التوليد).

```tsx
// مثال مختصر
const { data } = await api.get('/mobile/testimonials', { params: { limit: 20 } });
const items = data?.data?.items ?? [];
// FlatList horizontal + auto-scroll أو Reanimated marquee
```

---

## 3) الموقع

- قسم `#testimonials` في الصفحة الرئيسية بعد «خدماتنا».
- إدارة من لوحة التحكم: **المحتوى → آراء العملاء** (`/mg-cp/testimonials`).
- زر **توليد بالذكاء الاصطناعي** ينشئ شهادتين فورًا عبر Gemini.

---

## 4) الجدولة (سيرفر)

| وقت (طرابلس) | أمر |
|--------------|-----|
| يوميًا 09:15 | `php artisan testimonials:generate` |

```env
TESTIMONIALS_ENABLED=true
TESTIMONIALS_DAILY_GENERATE=true
TESTIMONIALS_DAILY_COUNT=2
TESTIMONIALS_PUBLIC_LIMIT=24
GEMINI_API_KEY=…   # إن غاب المفتاح → قوالب جاهزة
```

بذرة أولية قوية:
```bash
php artisan migrate --force
php artisan db:seed --class=TestimonialSeeder --force
```

---

## 5) Checklist RN

- [ ] استدعاء `GET /testimonials` عند فتح Home
- [ ] كروت + 5 نجوم + اسم/صفة/مدينة/منتج
- [ ] أنيميشن ماركي أو كاروسيل + pause on touch
- [ ] احترام `enabled: false` (إخفاء القسم)
- [ ] لا تعتمد على `points_per_unit` هنا — هذا قسم ثقة فقط
