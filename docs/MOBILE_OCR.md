# POS OCR — دليل مطوّر الموبايل

> الباك إند جاهز (نفس محرّك لوحة التحكم). نفّذ الشاشات حسب هذا الملف ثم اربط الـ API.

**Base (جملة):** `/api/v1/mobile/distributor/pos/ocr`  
**Base (قطاعي):** `/api/v1/mobile/trader/pos/ocr`  
**Auth:** `Authorization: Bearer {token}`  
**Checkout بعد OCR:** المسارات الحالية `POST …/pos/checkout` — OCR **لا** يُصدر فاتورة.

مرتبط: [MOBILE_POS_UX.md](./MOBILE_POS_UX.md) · [MOBILE_PENDING_RECEIPTS.md](./MOBILE_PENDING_RECEIPTS.md)

---

## 1) ليه مهم؟

البائع يصوّر ورقة مكتوبة (أو يلصق نص) → السيرفر يقرأ البنود ويربطها بكتالوج MG → المستخدم يراجع ويعتمد → البنود تدخل **السلة المحلية** → تأكيد البيع عبر `pos/checkout` كالعادة.

```
كاميرا / معرض / لصق نص
        ↓
POST batches → POST …/images (أو paste)
        ↓
poll GET batches/{id}  (step: polling → review)
        ↓
مراجعة: اختر منتج / نظام / كمية → approve
        ↓
POST …/apply  →  cart_lines[]
        ↓
ادمج في سلة التطبيق → POST …/pos/checkout
```

---

## 2) Checklist تنفيذ التطبيق

- [ ] زر في شاشة POS: «قراءة ورقة» / أيقونة كاميرا
- [ ] عند الفتح: `GET …/pos/ocr/current` — لو `resumable=true` اعرض «متابعة القراءة» أو «تجاهل»
- [ ] إنشاء دفعة: `POST …/pos/ocr/batches`
- [ ] رفع صور (حتى 10): `multipart` حقل `image` أو `file`
- [ ] أثناء القراءة: poll كل `poll.interval_ms` (ثم بعد ~20ث استخدم `backoff_interval_ms`)
- [ ] شاشة مراجعة: قائمة `draft.lines` + شارة `stats` + مستبعدات `draft.excluded`
- [ ] اعتماد بند: `PATCH …/lines/{ocr_line_id}` بـ `action=approve`
- [ ] «اعتماد كل الجاهز»: `POST …/approve-ready`
- [ ] «أضف للسلة»: `POST …/apply` → ادمج `cart_lines` محلياً
- [ ] لا تستدعِ checkout تلقائياً من OCR — المستخدم يراجع السلة أولاً
- [ ] لو `gemini_enabled=false` أخفِ الزر أو أظهر رسالة «غير متاح حالياً»

---

## 3) Endpoints

كل المسارات تحت `{base}` =  
`/api/v1/mobile/distributor/pos/ocr` **أو** `/api/v1/mobile/trader/pos/ocr`

| Method | Path | الغرض |
|--------|------|--------|
| GET | `/current` | دفعة معلّقة لنفس المستخدم (أو null) |
| POST | `/batches` | إنشاء / استئناف دفعة |
| GET | `/batches/{id}` | حالة + مسودة (لـ poll) |
| POST | `/batches/{id}/images` | رفع صورة |
| POST | `/batches/{id}/paste` | تحليل نص ملصوق |
| POST | `/batches/{id}/images/{imageId}/retry` | إعادة محاولة صورة فاشلة |
| POST | `/batches/{id}/images/{imageId}/cancel` | إلغاء صورة |
| POST | `/batches/{id}/reread` | إعادة قراءة كل الصور (يتجاهل الكاش) |
| POST | `/batches/{id}/abandon` | تجاهل الدفعة |
| PATCH | `/batches/{id}/prefs` | نظام افتراضي / تذكر للعميل |
| PATCH | `/batches/{id}/lines/{lineId}` | تعديل بند |
| POST | `/batches/{id}/approve-ready` | اعتماد كل البنود «الخضراء» |
| POST | `/batches/{id}/apply` | إخراج بنود السلة |

---

## 4) إنشاء دفعة

```http
POST /api/v1/mobile/distributor/pos/ocr/batches
Authorization: Bearer …
Content-Type: application/json

{
  "customer_user_id": 55,
  "system_default": "ask",
  "remember_for_customer": false
}
```

| حقل | مطلوب | قيم | معنى |
|-----|--------|------|------|
| `customer_user_id` | لا | رقم مستخدم | العميل (قطاعي للجملة / سباك للقطاعي) — لتحسين المطابقة والتعلّم |
| `system_default` | لا | `ask` \| `us` \| `eu` | لو المقاس مش واضح: اسأل / أمريكي / أوروبي |
| `remember_for_customer` | لا | bool | احفظ تصحيحات OCR لهذا العميل |

**رد 201 — ملخص `data.batch`:**

```json
{
  "batch_id": 12,
  "status": "draft",
  "step": "draft",
  "settled": true,
  "reading": false,
  "prefs": {
    "system_default": null,
    "customer_user_id": 55,
    "remember_for_customer": false
  },
  "poll": {
    "interval_ms": 2000,
    "backoff_after_ms": 20000,
    "backoff_interval_ms": 5000
  },
  "limits": { "max_images": 10 },
  "images": [],
  "draft": null,
  "merge_suggestions": [],
  "accounting": { "matched": 0, "needs_review": 0, "excluded": 0, "lines_read": 0 },
  "stats": { "pending": 0, "ready": 0, "approved": 0, "excluded": 0 },
  "gemini_enabled": true
}
```

`step` المهم للتطبيق:

| step | UI |
|------|-----|
| `draft` | ارفع صورة / الصق نص |
| `polling` | جاري القراءة — اعرض شريط الصور + progress |
| `review` | شاشة المراجعة |
| `added` | اتعمل apply مسبقاً |
| `abandoned` | متجاهَلة |

---

## 5) رفع صورة

```http
POST /api/v1/mobile/distributor/pos/ocr/batches/12/images
Authorization: Bearer …
Content-Type: multipart/form-data

image: (jpeg/png/webp ≤ 12MB)
```

- اسم الحقل: `image` **أو** `file`
- اختياري: `force_duplicate=1` لرفع نفس الصورة مرة أخرى
- **409** لو الصورة نفسها مرفوعة في الدفعة (`already uploaded`)
- **503** لو Gemini غير مفعّل
- بعد النجاح ابدأ الـ poll فوراً

نصائح جودة (من إعدادات السيرفر):

- صفّ الورقة، إضاءة جيدة، بدون قص للنص
- يفضّل تصغير الضلع الأطول إلى ~2000px وجودة JPEG ~0.85 قبل الرفع

---

## 6) لصق نص (بدون كاميرا)

```http
POST …/batches/12/paste
Content-Type: application/json

{ "text": "كوع 90 1 بوصة MG × 4\nماسورة 32 مم MG × 10" }
```

سطر لكل بند قدر الإمكان.

---

## 7) Poll الحالة

```http
GET …/batches/12
```

- طالما `reading=true` أو `step=polling`: كرّر الطلب
- استخدم `poll.interval_ms` أولاً؛ بعد `backoff_after_ms` من بدء الرفع بدّل إلى `backoff_interval_ms`
- يمكن عرض بنود جاهزة من صور خلصت قبل انتهاء كل الصور (`draft` قد يظهر مبكراً)
- توقف عند `settled=true` وانتقل لـ `review`

شكل صورة في `images[]`:

```json
{
  "id": 44,
  "position": 1,
  "status": "reading",
  "lines_count": 0,
  "error_code": null,
  "preview_url": "https://…/storage/ocr-uploads/…",
  "countdown_seconds": null,
  "quality_warning": false,
  "attempts": 1,
  "read_version": 0
}
```

| status | معنى |
|--------|------|
| `queued` | في الانتظار |
| `reading` | جاري |
| `retrying` | إعادة محاولة — استخدم `countdown_seconds` |
| `done` | نجحت |
| `empty` | ما فيش بنود |
| `failed` | فشلت — اعرض زر retry |
| `canceled` | أُلغيت |

أكواد خطأ شائعة في `error_code`: `OCR-TIMEOUT` · `OCR-BUSY` · `OCR-AUTH` · `OCR-EMPTY-JSON` · `OCR-FAILED`

---

## 8) شاشة المراجعة

### 8.1 إحصائيات

من `batch.stats`:

| حقل | عرض مقترح |
|-----|-----------|
| `pending` | يحتاجك |
| `ready` | جاهز (أخضر) |
| `approved` | معتمد |
| `excluded` | مستبعد (غالباً بدون علامة MG) |

### 8.2 بند في `draft.lines[]`

حقول مهمة للتطبيق:

| حقل | استخدام |
|-----|---------|
| `ocr_line_id` | معرّف الـ PATCH |
| `raw_text` | النص المقروء |
| `qty` | الكمية |
| `matched_product_id` | المنتج المقترح |
| `name` | اسم المنتج |
| `approved` / `review_status` | حالة الاعتماد |
| `needs_review` | مصفوفة أسباب (فارغة = جاهز غالباً) |
| `candidates[]` | مرشّحون `{product_id, name, system, …}` |
| `slots.system` | `us` / `eu` |
| `slots.lexicon_family` | عائلة معجمية |
| `main_image` / `pieces_per_box` | عرض |

### 8.3 تعديل بند

```http
PATCH …/batches/12/lines/901
Content-Type: application/json

{ "action": "approve", "product_id": 42 }
```

| action | جسم إضافي | نتيجة |
|--------|-----------|--------|
| `select_product` | `product_id` | ربط منتج بدون اعتماد |
| `approve` | `product_id` اختياري لو مربوط مسبقاً | اعتماد |
| `unapprove` | — | إلغاء الاعتماد |
| `set_qty` | `qty` ≥ 1 | تغيير الكمية |
| `choose_system` | `system`: `us`\|`eu` | اختيار من المرشّحين حسب النظام |
| `choose_family` | `lexicon_family` | فك غموض العائلة ثم إعادة مطابقة |
| `confirm_understanding` | — | تأكيد فهم اللهجة/الاسم |

### 8.4 اعتماد كل الجاهز

```http
POST …/batches/12/approve-ready
```

يعتمد البنود ذات `match_decision` = `auto` أو `alias` وبدون `needs_review`.

### 8.5 إعدادات أثناء المراجعة

```http
PATCH …/batches/12/prefs
{
  "system_default": "us",
  "remember_for_customer": true,
  "customer_user_id": 55,
  "reenrich": true
}
```

`reenrich=true` (الافتراضي عند تغيير النظام) يعيد تصنيف البنود بدون استدعاء Gemini من جديد.

---

## 9) تطبيق على السلة

```http
POST …/batches/12/apply
```

**نجاح 200:**

```json
{
  "status": true,
  "message": "تم تجهيز 3 منتج للسلة",
  "data": {
    "cart_lines": [
      {
        "product_id": 42,
        "name": "كوع 90 – 1 بوصة MG",
        "quantity": 4,
        "boxes": null,
        "pieces_per_box": null,
        "points_per_unit": 2,
        "image": "…"
      }
    ],
    "approved_count": 3,
    "idempotency_token": "uuid…",
    "batch": { "step": "added", "…" : "…" },
    "next": {
      "merge_into_local_cart": true,
      "checkout": "POST …/pos/checkout بنفس شكل lines (product_id + boxes|quantity)"
    }
  }
}
```

قواعد الدمج في التطبيق:

1. لكل عنصر في `cart_lines`: لو المنتج موجود في السلة → زِد الكمية/`boxes`؛ وإلا أضِف سطراً جديداً.
2. لو `boxes` غير null استخدمه في checkout؛ وإلا استخدم `quantity`.
3. **409** = الدفعة اتعملت apply قبل كده — لا تكرّر الدمج.
4. **422** = مفيش بنود معتمدة.

بعد الدمج: افتح شاشة مراجعة السلة ثم:

```http
POST /api/v1/mobile/distributor/pos/checkout
{
  "retail_trader_id": 55,
  "lines": [
    { "product_id": 42, "quantity": 4 }
  ]
}
```

(للقطاعي: `POST …/trader/pos/checkout` مع `plumber_id` حسب عقد الـ POS الحالي.)

---

## 10) استئناف / تجاهل

```http
GET …/current
→ { "resumable": true, "batch": { … } }
```

- **متابعة:** اذهب مباشرة لـ `GET …/batches/{id}`
- **تجاهل:** `POST …/batches/{id}/abandon` ثم أنشئ دفعة جديدة

---

## 11) أخطاء متوقعة

| HTTP | متى |
|------|-----|
| 401 | بدون توكن / منتهي |
| 403 | دور غير الجملة/القطاعي |
| 404 | دفعة/صورة/بند مش تبع المستخدم |
| 409 | صورة مكررة أو apply مكرر |
| 422 | فاليديشن / لا بنود معتمدة / دفعة مغلقة |
| 503 | Gemini غير مفعّل على السيرفر |

غلاف الرد دائماً:

```json
{ "status": true|false, "message": "…", "data": { } }
```

---

## 12) حدود وتشغيل

- حتى **10** صور لكل دفعة
- معالجة متوازية محدودة على السيرفر (~2 صور)
- القراءة غير متزامنة عادةً → **poll إلزامي**
- البنود بدون علامة MG تروح `excluded` — يمكن عرضها للمستخدم للمعلومة فقط
- OCR لا يخصم مخزون ولا يعطي نقاط — ده بعد `pos/checkout` + تأكيد الاستلام

---

## 13) اختبار سريع (curl)

```bash
TOKEN=…
BASE=https://mg-plastic.com/api/v1/mobile/distributor/pos/ocr

# 1) دفعة
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -d '{"system_default":"ask"}' -H "Content-Type: application/json" \
  "$BASE/batches"

# 2) صورة
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F "image=@/path/note.jpg" \
  "$BASE/batches/12/images"

# 3) poll
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/batches/12"

# 4) اعتماد + apply
curl -s -X PATCH -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"action":"approve","product_id":42}' \
  "$BASE/batches/12/lines/901"

curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE/batches/12/apply"
```
