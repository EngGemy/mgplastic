# إعلان منبثق على الرئيسية (Home Popup)

> Popup فوق شاشة الـ Home عند الدخول: صورة + زر CTA من الباكند.  
> عام (بدون توكن = guest) أو مع Bearer حسب الدور.

**لوحة التحكم → المحتوى → إعلانات الرئيسية**

مرتبط: تخطيط الرئيسية [`HOME_LAYOUT_API.md`](./HOME_LAYOUT_API.md) · Deep links [`MOBILE_DEEP_LINKS.md`](./MOBILE_DEEP_LINKS.md)

---

## 1) متى يُستدعى؟

1. حمّل `GET /api/v1/mobile/home/layout` بنجاح.
2. نادِ فوراً:

```http
GET /api/v1/mobile/home/popup
Authorization: Bearer {token}   # اختياري
Accept: application/json
Accept-Language: ar
```

3. لو `data === null` → لا تعرض شيئاً.
4. لو فيه object و`id` مش متخزّن كـ dismissed محلياً → اعرض الـ Modal.
5. Popup **واحد** فقط — لا تكدّس.

اكتشاف الرابط من الإعدادات:

```http
GET /api/v1/mobile/settings
→ data.home.popup
```

### Query (اختياري)

| Param | معنى |
|-------|------|
| `role` | `guest` \| `plumber` \| `retail_trader` \| `wholesale_distributor` |

- لو فيه Bearer صالح → الدور من المستخدم ويتجاهل `?role=`.
- بدون توكن → `?role=` أو **guest**.

---

## 2) شكل الرد

### فيه إعلان

```json
{
  "status": true,
  "message": "إعلان الرئيسية",
  "data": {
    "id": 12,
    "role": "plumber",
    "kind": "feature",
    "layout": "hero",
    "title": "سباك مميز هذا الأسبوع",
    "subtitle": "شوف أعماله واطلب منه",
    "image_url": "https://mg-plastic.com/storage/home-popups/….jpg",
    "cta": {
      "text": "عرض السباك",
      "link": "mgplastic://plumber/55"
    },
    "frequency": "once",
    "dismissible": true,
    "starts_at": "2026-11-01T00:00:00+02:00",
    "ends_at": "2026-11-30T23:59:59+02:00"
  }
}
```

### مفيش إعلان

```json
{
  "status": true,
  "message": "لا يوجد إعلان",
  "data": null
}
```

### حقول مهمة للتطبيق

| حقل | معنى |
|-----|------|
| `role` | الجمهور المطابق: `guest` \| `plumber` \| `retail_trader` \| `wholesale_distributor` |
| `layout` | **شكل العرض** — اختَر الـ UI حسبه (شوف §3) |
| `cta.text` | **نص الزر** من الباكند — اعرضه كما هو |
| `cta.link` | وجهة جاهزة `mgplastic://…` (أو `https://` خارجي) |
| `frequency` | `once` \| `once_per_day` \| `every_open` |
| `dismissible` | لو `false` أخفِ ✕ (نادر) |
| `kind` | تلميح محتوى: `promo` · `announcement` · `event` · `feature` · `auth` |

### محتوى مختلف لكل دور (مهم)

الباكند يرجّع **إعلاناً واحداً** حسب الدور. عشان الضيف يشوف حاجة مختلفة عن السباك:

1. في اللوحة اعمل إعلان للضيف فقط (`target_roles = [guest]`) — مثلاً «سجّل عندنا» + `layout: auth`.
2. إعلان تاني للسباك فقط — صورة سباك مشهور + `layout: hero`.
3. إعلان تالت للقطاعي / الجملة حسب الحاجة.

كل إعلان = صورة + نص زر + وجهة + شكل عرض مستقل. التطبيق مش محتاج يفرّق الجمهور يدوياً؛ يعتمد على `data` اللي راجع + `data.role` / `data.layout`.

---

## 3) أشكال العرض `layout` (عقد التصميم)

طبّق الشكل حسب `data.layout` (لو قيمة مجهولة → اعتبرها `hero`):

### `hero` — صورة كاملة + زر (افتراضي)

```
┌─────────────────────────────┐
│  ✕                          │
│     [ صورة ~4:5 كاملة ]     │
│     عنوان / وصف اختياري     │
│   [     cta.text      ]     │
└─────────────────────────────┘
```

مناسب لـ: سباك مميز، محل، عرض منتج.

### `card` — بطاقة متوسطة

```
┌─────────────────────────────┐
│           ✕                 │
│   ┌───────────────────┐     │
│   │   صورة مربّعة     │     │
│   │   عنوان + وصف     │     │
│   │   [ cta.text ]    │     │
│   └───────────────────┘     │
└─────────────────────────────┘
```

مناسب لـ: فعالية، مجموعة منتجات، تنويه خفيف.

### `auth` — دعوة تسجيل (موصى به للضيف)

```
┌─────────────────────────────┐
│  ✕                          │
│     [ صورة ترحيبية ]        │
│     انضم لشبكة MG           │
│     سجّل كسباك أو تاجر      │
│   [ سجّل عندنا ]            │
│   رابط صغير: دخول           │  ← اختياري في التطبيق لو cta → register
└─────────────────────────────┘
```

مناسب لـ: `role=guest` · `cta.link = mgplastic://auth/register`.

### `announce` — تنويه نصّي

```
┌─────────────────────────────┐
│  ✕                          │
│     أيقونة / صورة صغيرة     │
│     عنوان التنويه           │
│     نص أوضح (subtitle)      │
│   [ حسناً / اعرف المزيد ]   │
└─────────────────────────────┘
```

مناسب لـ: صيانة، خبر، سياسة.

قواعد مشتركة لكل الأشكال:

- خلفية داكنة شفافة فوق الـ Home.
- زر واحد من `cta.text` (ما عدا رابط ثانوي اختياري في `auth` من جهة التطبيق فقط).
- ✕ أو الضغط على الخلفية → dismiss **بدون** تنقّل (لو `dismissible`).
- الضغط على الزر → افتح `cta.link` ثم طبّق `frequency`.

### تخزين الـ dismiss محلياً

مفتاح مقترح: `home_popup_dismissed:{id}`

| frequency | سلوك |
|-----------|------|
| `once` | بعد الإغلاق/الضغط لا تعرض نفس `id` مرة أخرى |
| `once_per_day` | خزّن تاريخ اليوم؛ اسمح غداً |
| `every_open` | لا تخزّن (أو امسح عند كل جلسة رئيسية) |

---

## 4) أمثلة curl

### ضيف (بدون توكن) — محتوى وشكل خاص بالضيف

```bash
curl -H "Accept: application/json" \
  "https://mg-plastic.com/api/v1/mobile/home/popup"
```

```json
{
  "data": {
    "role": "guest",
    "kind": "auth",
    "layout": "auth",
    "title": "انضم لشبكة MG",
    "cta": {
      "text": "سجّل عندنا",
      "link": "mgplastic://auth/register"
    }
  }
}
```

### سباك (Bearer) — شكل ومحتوى مختلفين عن الضيف

```bash
curl -H "Authorization: Bearer …" \
  -H "Accept: application/json" \
  "https://mg-plastic.com/api/v1/mobile/home/popup"
```

```json
{
  "data": {
    "role": "plumber",
    "kind": "feature",
    "layout": "hero",
    "cta": {
      "text": "عرض السباك",
      "link": "mgplastic://plumber/55"
    }
  }
}
```

نفس الـ endpoint؛ الفرق من إعلان الأدمن المستهدف لكل دور.

### تست بدون توكن بدور معيّن

```bash
curl "https://mg-plastic.com/api/v1/mobile/home/popup?role=retail_trader"
```

---

## 5) الوجهات (`cta.link`) حسب الدور

الصيغة: `mgplastic://{path}`  
نفس المسارات تعمل كـ Universal Link: `https://mg-plastic.com/open/{type}/{id}` عند دعمها.

### مشتركة (guest + كل الأدوار)

| Link | الشاشة |
|------|--------|
| `mgplastic://home` | الرئيسية |
| `mgplastic://catalog` | الكتالوج |
| `mgplastic://category/{id}` | قسم منتجات |
| `mgplastic://product/{id}` | تفاصيل منتج |
| `mgplastic://collection/{id}` | مجموعة منتجات |
| `mgplastic://promotion/{id}` | إعلان / عرض MG |
| `mgplastic://event/{id}` | فعالية |
| `mgplastic://list/events` | كل الفعاليات |
| `mgplastic://shop/{id}` | صفحة محل |
| `mgplastic://list/shops` | كل المحلات |
| `mgplastic://plumber/{id}` | صفحة سباك |
| `mgplastic://list/plumbers` | كل السباكين |
| `mgplastic://support` | الدعم |
| `mgplastic://terms` | الشروط |
| `mgplastic://privacy` | الخصوصية |
| `mgplastic://notifications` | الإشعارات |
| `mgplastic://profile` | حسابي |

### جديد — ضيف / تسجيل (auth)

| Link | الشاشة |
|------|--------|
| `mgplastic://auth/login` | تسجيل الدخول |
| `mgplastic://auth/register` | اختيار نوع الحساب (سباك / قطاعي) |
| `mgplastic://auth/register-plumber` | تسجيل سباك مباشرة |
| `mgplastic://auth/register-retail` | تسجيل قطاعي مباشرة |

> الجملة = login فقط من الموبايل (مفيش register-store في التطبيق).

### سباك `plumber`

| Link | الشاشة |
|------|--------|
| `mgplastic://wallet` | المحفظة |
| `mgplastic://points` | النقاط |
| `mgplastic://pending-receipts` | بضاعة بانتظار الاستلام |
| `mgplastic://plumber-orders` | طلباتي من القطاعي |
| `mgplastic://plumber-orders/new` | طلب جديد |
| `mgplastic://my-works` | أعمالي |
| `mgplastic://reputation` | سمعتي |
| `mgplastic://order/{id}` | تفاصيل طلب |
| `mgplastic://invoice/{id}` | فاتورة |
| `mgplastic://return` | المرتجعات |
| `mgplastic://withdrawal` | سحب رصيد |

### تاجر قطاعي `retail_trader`

| Link | الشاشة |
|------|--------|
| `mgplastic://trader-orders` | طلبات السباكين |
| `mgplastic://trader-orders?scope=to_wholesaler` | طلباتي من الجملة |
| `mgplastic://choose-wholesaler` | طلب مواد من الجملة |
| `mgplastic://join-wholesaler` | الانضمام لموزع |
| `mgplastic://network/plumbers` | سباكيني |
| `mgplastic://invoices` | فواتيري |
| `mgplastic://returns` | المرتجعات |
| `mgplastic://store-products` | منتجاتي |
| `mgplastic://reports` | التقارير |
| `mgplastic://store-media` | صور المحل |
| `mgplastic://points` | النقاط |
| `mgplastic://product_type/{key}` | منتجات نوع (محلات) |

### موزع جملة `wholesale_distributor`

| Link | الشاشة |
|------|--------|
| `mgplastic://distributor-orders?scope=from_retail` | طلبات القطاعي |
| `mgplastic://distributor-orders?scope=to_factory&source=factory` | مشتريات المصنع |
| `mgplastic://distributor-orders?scope=to_factory&source=warehouse` | مشتريات المخزن |
| `mgplastic://new-order` | طلب شراء مواد |
| `mgplastic://join-requests` | طلبات الانضمام |
| `mgplastic://network/traders` | القطاعيين |
| `mgplastic://pos` | نقطة البيع |
| `mgplastic://invoices` | فواتيري |
| `mgplastic://returns` | المرتجعات |
| `mgplastic://store-products` | منتجاتي |
| `mgplastic://reports` | التقارير |
| `mgplastic://store-media` | صور المحل |

### قاعدة أمان في التطبيق

لو `cta.link` يخص دوراً آخر (مثلاً ضيف فتح `wallet`):

1. افتح `mgplastic://auth/login`، أو
2. تجاهل بهدوء وأغلق الـ popup.

الأدمن يختار الوجهة حسب `target_roles` في اللوحة لتقليل الغلط.

---

## 6) حالات استخدام مقترحة

| kind | مثال | CTA |
|------|------|-----|
| `feature` | سباك مشهور | «عرض السباك» → `plumber/{id}` |
| `feature` | محل قطاعي | «زيارة المحل» → `shop/{id}` |
| `event` | ورشة / معرض | «تفاصيل الفعالية» → `event/{id}` |
| `promo` | عرض أنابيب | «شوف العرض» → `promotion/{id}` أو `collection/{id}` |
| `announcement` | صيانة / خبر | «حسناً» → `home` أو `support` |
| `auth` | دعوة تسجيل للضيف | «سجّل عندنا» → `auth/register` |

---

## 7) Checklist للتطبيق

- [ ] بعد `home/layout` نادِ `home/popup`
- [ ] تعامل مع `data: null`
- [ ] ارسم UI حسب `data.layout` (`hero` \| `card` \| `auth` \| `announce`)
- [ ] اعرض `cta.text` كما يأتي من السيرفر
- [ ] افتح `cta.link` عبر راوتر الـ deep links
- [ ] طبّق `frequency` بمفتاح `home_popup_dismissed:{id}`
- [ ] دعم `mgplastic://auth/login|register|register-plumber|register-retail`
- [ ] ضيف بدون توكن ياخد إعلان `guest` (محتوى/شكل مختلف عن المسجّلين)
- [ ] Bearer يتجاهل `?role=` ويستخدم دور المستخدم
