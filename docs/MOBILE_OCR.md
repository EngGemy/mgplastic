# POS OCR — دليل مطوّر الموبايل

> الباك إند جاهز (نفس محرّك لوحة التحكم). نفّذ الشاشات حسب هذا الملف ثم اربط الـ API.

**Base (جملة):** `/api/v1/mobile/distributor/pos/ocr`  
**Base (قطاعي):** `/api/v1/mobile/trader/pos/ocr`  
**Auth:** `Authorization: Bearer {token}`  
**Checkout بعد OCR:** المسارات الحالية `POST …/pos/checkout` — OCR **لا** يُصدر فاتورة.

مرتبط: [MOBILE_POS_UX.md](./MOBILE_POS_UX.md) · [MOBILE_PENDING_RECEIPTS.md](./MOBILE_PENDING_RECEIPTS.md)

---

## 0) إجابات فريق الموبايل (من الكود)

### 1) مصدر المخزون / المطابقة

| سؤال | الجواب |
|------|--------|
| المرشّحون من وين؟ | من **كتالوج MG الكامل** (`products` ذات `catalog_family`)، **مش** من مخزون البائع فقط. انظر `InvoiceOcrCatalogMatcher::enrich` → `Product::query()->whereNotNull('catalog_family')` (`app/Services/Ocr/InvoiceOcrCatalogMatcher.php:23-27`). |
| منتج مش في مخزون البائع؟ | المطابقة والاعتماد (`approve`) **ما بيتمنعوش**. `apply` كمان **ما بيتحققش** من المخزون. الخصم الفعلي يحصل في `pos/checkout` عبر `NetworkInventoryService` وقد يرجع 422 لو الكمية أكبر من المتوفر. |
| `available_qty`؟ | **مضاف** على كل بند في `draft.lines` وعلى كل عنصر في `candidates[]` و`cart_lines` (+ `in_stock`, `qty_exceeds_stock`). الكمية المتوفرة بالـ **قطع** من مخزون البائع الحالي. |

`qty_exceeds_stock`: يقارن القطع المطلوبة (`qty × pieces_per_box` لو المنتج بالصندوق، وإلا `qty`) مع `available_qty`.

> اقتراح يحتاج موافقة (يغيّر السلوك): رفض `approve`/`apply` لو `qty_exceeds_stock` — **غير مفعّل** حالياً؛ التطبيق يعرض تحذيراً ويترك القرار للبائع قبل checkout.

### 2) استرجاع بعد apply

- سابقاً: `POST apply` مرة تانية → 409 بدون بنود.
- الآن:
  - `GET …/batches/{id}` عند `step=added` يرجّع **`cart_lines`** جاهزة للدمج.
  - `POST apply` المتكرر → 409 مع `error_code=OCR_ALREADY_APPLIED` وداخل `errors`: `cart_lines` + `batch` + `idempotency_token`.

### 3) `gemini_enabled`

موجود أصلاً على `GET …/current` حتى لو `batch=null` (`PosOcrController::current` — `app/Http/Controllers/Api/Mobile/PosOcrController.php:46-51`).  
استخدمه لإخفاء زر الكاميرا بدون إنشاء دفعة.

### 4) الوحدات

من `InvoiceCartSheetService::cartLine` (`app/Services/InvoiceCartSheetService.php:118-131`):

| حقل | المعنى |
|-----|--------|
| `draft.lines[].qty` و`set_qty` | **كمية الورقة** كما قرأها OCR. لو المنتج له `pieces_per_box > 0`، السيرفر يفسّرها كـ **عدد صناديق** عند التحويل للسلة؛ وإلا **قطع**. |
| `cart_lines[].quantity` | دائماً بالـ **قطع** (`boxes × pieces_per_box` أو نفس `qty`). |
| `cart_lines[].boxes` | رقم لو `pieces_per_box > 0`؛ وإلا `null`. |
| `cart_lines[].pieces_per_box` | رقم لو المنتج بالصندوق؛ وإلا `null`. |

للـ checkout: أرسل `boxes` إن وُجد، وإلا `quantity`.

### 5) أشكال ناقصة — انظر القسم 14

### 6) `confirm_understanding` vs `choose_family`

| اعرض | متى (من الحقول) | action |
|------|-----------------|--------|
| تأكيد الفهم | `understanding` موجود + `needs_review` فيه `unconfirmed_term` (مصطلح لهجة/إنجليزي غير مؤكد) | `confirm_understanding` |
| اختيار العائلة | `family_candidates` غير فارغ **أو** `family_chips` موجود **أو** `needs_review` فيه `ambiguous_family` / `unknown_word` | `choose_family` + `lexicon_family` |

الكود: `InvoiceOcrCatalogMatcher` عند `!lexicon_auto_ok` (`:250-266`) و`family_candidates` (`:191-205`) و`family_chips` (`:606-616`).

### 7) `idempotency_token`

خاص بـ **OCR apply فقط** (منع تكرار إضافة نفس الدفعة).  
`POST …/pos/checkout` **لا يقرأ** هذا الحقل حالياً (`Distributor\PosController::checkout` يتحقق من `retail_trader_id` + `lines` فقط).  
**لا ترسله مع checkout.** الرد يحمل `next.send_idempotency_token_on_checkout: false`.

> اقتراح يحتاج موافقة: دعم `ocr_idempotency_token` اختياري في checkout — غير منفَّذ.

### 8) تغيير العميل بعد القراءة

`PATCH …/prefs` مع `{ "customer_user_id": N, "reenrich": true }` **كافٍ** — يحدّث تفضيلات المطابقة/الأسماء المستعارة ويعيد تصنيف البنود بدون Gemini جديد. **لا تحتاج دفعة جديدة.**  
(`MobileOcrPresenter::putPrefs` يزامن `batch.distributor_id`.)

### 9) الترجمة وأكواد الخطأ

| عنصر | السلوك |
|------|--------|
| `message` | نص عربي ثابت في الكنترولر — **لا** يتبع `Accept-Language`. |
| `needs_review` | أكواد آلة إنجليزية (`needs_choice`, …) — التطبيق يترجمها محلياً. |
| صورة مكررة 409 | `error_code: "OCR_DUPLICATE_IMAGE"` |
| apply مكرر 409 | `error_code: "OCR_ALREADY_APPLIED"` |

### 10) تخزين صور الورقة

| قبل | الآن |
|-----|------|
| `preview_url` → `storage/app/public` (عام عبر symlink) | ما زال موجوداً للتوافق |
| لا احتفاظ محدد | تنظيف يومي `ocr:prune-uploads` (افتراضي 30 يوم للدفعات `added`/`abandoned`) |
| — | **`preview_signed_url`** + **`preview_signed_expires_at`** (افتراضي 30 دقيقة) عبر مسار موقّع `ocr.images.preview` |

**سياسة الوصول:** الملف ما زال على قرص `public` (لوحة التحكم تحتاجه). للتطبيق: **فضّل `preview_signed_url`** وجدّده عند انتهاء الصلاحية بـ `GET batches/{id}`.  
الإعدادات: `config/ocr_uploads.php` (`OCR_UPLOAD_RETENTION_DAYS`, `OCR_SIGNED_PREVIEW_MINUTES`).

> اقتراح يحتاج موافقة (يكسر الروابط العامة القديمة): نقل الرفع إلى قرص `private` فقط — غير منفَّذ.

---

## 1) التدفق

```
GET /current  (gemini_enabled؟ resumable؟)
        ↓
POST batches → POST …/images | paste
        ↓
poll GET batches/{id}  (polling → review)
        ↓
مراجعة / approve / approve-ready
        ↓
POST …/apply → cart_lines[]  (أو استرجعها لاحقاً من GET عند step=added)
        ↓
سلة محلية → POST …/pos/checkout
```

---

## 2) Checklist

- [ ] أخفِ زر OCR لو `GET /current` → `gemini_enabled=false` (حتى مع `batch=null`)
- [ ] استئناف عبر `/current` أو تجاهل بـ `abandon`
- [ ] Poll حسب `poll.*`
- [ ] اعرض `available_qty` / حذّر عند `qty_exceeds_stock`
- [ ] بعد apply: احفظ `cart_lines` محلياً؛ لو ضاعت → `GET batches/{id}` أو أعد `apply` واقرأ `errors.cart_lines` من 409
- [ ] استخدم `preview_signed_url` للعرض
- [ ] ترجم `needs_review` محلياً (أكواد آلة)
- [ ] لا ترسل `idempotency_token` إلى checkout

---

## 3) Endpoints

| Method | Path | الغرض |
|--------|------|--------|
| GET | `/current` | دفعة معلّقة + `gemini_enabled` |
| POST | `/batches` | إنشاء / استئناف |
| GET | `/batches/{id}` | حالة + مسودة (+ `cart_lines` لو `added`) |
| POST | `/batches/{id}/images` | رفع صورة |
| POST | `/batches/{id}/paste` | نص ملصوق |
| POST | `/batches/{id}/images/{imageId}/retry` | إعادة محاولة |
| POST | `/batches/{id}/images/{imageId}/cancel` | إلغاء |
| POST | `/batches/{id}/reread` | إعادة قراءة |
| POST | `/batches/{id}/abandon` | تجاهل |
| PATCH | `/batches/{id}/prefs` | نظام / عميل / تذكر |
| PATCH | `/batches/{id}/lines/{lineId}` | تعديل بند |
| POST | `/batches/{id}/approve-ready` | اعتماد الجاهز |
| POST | `/batches/{id}/apply` | إخراج بنود السلة |

---

## 4) `GET /current`

```json
{
  "status": true,
  "message": "لا توجد دفعة معلّقة",
  "data": {
    "batch": null,
    "resumable": false,
    "gemini_enabled": true
  }
}
```

---

## 5) إنشاء دفعة + رفع

```http
POST …/batches
{ "customer_user_id": 55, "system_default": "ask", "remember_for_customer": false }
```

```http
POST …/batches/12/images
Content-Type: multipart/form-data
image: (jpeg/png ≤ 12MB)
```

409 مكرر:

```json
{
  "status": false,
  "message": "الصورة مرفوعة مسبقاً في نفس الدفعة",
  "error_code": "OCR_DUPLICATE_IMAGE",
  "errors": { "image": { "id": 44, "…" : "…" }, "batch": { } }
}
```

---

## 6) Poll — شكل `batch` الكامل (مختصر حقول جديدة)

```json
{
  "batch_id": 12,
  "status": "review",
  "step": "review",
  "settled": true,
  "reading": false,
  "cart_added_at": null,
  "idempotency_token": null,
  "prefs": { "system_default": "us", "customer_user_id": 55, "remember_for_customer": false },
  "poll": { "interval_ms": 2000, "backoff_after_ms": 20000, "backoff_interval_ms": 5000 },
  "limits": { "max_images": 10 },
  "lexicon_families": [
    { "key": "pipe", "label": "ماسورة" },
    { "key": "elbow", "label": "كوع" },
    { "key": "tee", "label": "مثلث" }
  ],
  "images": [
    {
      "id": 44,
      "position": 1,
      "status": "done",
      "lines_count": 3,
      "error_code": null,
      "preview_url": "https://…/storage/ocr-uploads/…",
      "preview_signed_url": "https://…/ocr-images/44/preview?expires=…&signature=…",
      "preview_signed_expires_at": "2026-11-08T22:10:00+02:00",
      "countdown_seconds": null,
      "quality_warning": false,
      "attempts": 1,
      "read_version": 1
    }
  ],
  "draft": { "lines": [], "excluded": [], "source_type": "batch", "ocr_batch_id": 12 },
  "merge_suggestions": [],
  "accounting": { "matched": 2, "needs_review": 1, "excluded": 1, "lines_read": 4 },
  "stats": { "pending": 1, "ready": 2, "approved": 0, "excluded": 1 },
  "cart_lines": null,
  "gemini_enabled": true
}
```

عند `step=added`: `cart_lines` مصفوفة (نفس شكل apply).

---

## 7) تعديل بند

```http
PATCH …/batches/12/lines/901
{ "action": "approve", "product_id": 42 }
```

| action | حقول إضافية |
|--------|-------------|
| `select_product` | `product_id` (مطلوب) |
| `approve` | `product_id` اختياري لو مربوط |
| `unapprove` | — |
| `set_qty` | `qty` ≥ 1 |
| `choose_system` | `system`: `us`\|`eu` |
| `choose_family` | `lexicon_family` من `lexicon_families` أو `family_chips`/`family_candidates` |
| `confirm_understanding` | — |

---

## 8) Apply + استرجاع

**نجاح:**

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
        "image": "…",
        "available_qty": 120,
        "in_stock": true,
        "qty_exceeds_stock": false
      }
    ],
    "approved_count": 3,
    "idempotency_token": "uuid…",
    "batch": { "step": "added", "cart_lines": [ "…" ] },
    "next": {
      "merge_into_local_cart": true,
      "checkout": "POST …/pos/checkout بنفس شكل lines (product_id + boxes|quantity)",
      "send_idempotency_token_on_checkout": false
    }
  }
}
```

**تكرار apply (استرجاع):**

```json
{
  "status": false,
  "message": "تم تطبيق هذه الدفعة مسبقاً — لن تُكرَّر البنود",
  "error_code": "OCR_ALREADY_APPLIED",
  "errors": {
    "batch": { "step": "added", "cart_lines": [ "…" ] },
    "cart_lines": [ { "product_id": 42, "quantity": 4, "available_qty": 120 } ],
    "idempotency_token": "uuid…"
  }
}
```

---

## 9) Checkout (بعد الدمج)

```http
POST /api/v1/mobile/distributor/pos/checkout
{
  "retail_trader_id": 55,
  "lines": [ { "product_id": 42, "quantity": 4 } ]
}
```

لا ترسل `idempotency_token` من OCR.

---

## 14) قاموس الحقول والأشكال

### 14.1 `draft.excluded[]`

```json
{
  "raw_text": "ماسورة 4 بدون علامة",
  "name": "ماسورة 4 بدون علامة",
  "qty": 2,
  "reason": "plumbing_without_mg",
  "exclude_label": "بدون علامة MG",
  "match_status": "not_mg",
  "slots": { }
}
```

| `reason` | معنى |
|----------|------|
| `plumbing_without_mg` | سباكة بدون علامة MG |
| `non_mg_brand` / `known_non_mg` | ماركة غير MG |
| `unreadable_or_non_mg` | مش مقروء / مش كتالوج |

عرض للمعلومة فقط — لا تدخل السلة.

### 14.2 `merge_suggestions[]`

```json
{
  "product_id": 42,
  "size": "1",
  "qtys": [2, 3],
  "sum": 5,
  "line_indexes": [0, 3],
  "message": "نفس المنتج في أكثر من صورة — ادمج (مجموع 5) أو أبقِ منفصلاً"
}
```

**ماذا يفعل التطبيق؟** تنبيه اختياري. عند `apply` السيرفر **يدمج تلقائياً** نفس `product_id` في `cart_lines`. ممكن تخلي المستخدم يلغي اعتماد أحد البنود ويعدّل الكمية يدوياً.

### 14.3 `candidates[]` item

```json
{
  "product_id": 42,
  "name": "كوع 90 – 1 بوصة MG",
  "system": "us",
  "size_value": 1,
  "size_unit": "in",
  "pieces_per_box": null,
  "points_per_unit": 2,
  "main_image": "products/…",
  "flags": {
    "family": true,
    "size": true,
    "angle": true,
    "size2": true,
    "system": true,
    "variant": true
  },
  "rank": 90,
  "similarity": 0.82,
  "available_qty": 120,
  "in_stock": true
}
```

(مرشّح alias قد يختصر إلى `product_id`, `name`, `flags.alias`.)

### 14.4 `needs_review` (أكواد — ترجمها في التطبيق)

| كود | معنى للترجمة |
|-----|----------------|
| `needs_choice` | يحتاج اختيار المستخدم |
| `ambiguous_family` | عائلة غامضة (مثل مثلث / مثلث واي) |
| `unconfirmed_term` | مصطلح لهجة/إنجليزي غير مؤكد — اطلب تأكيد الفهم |
| `unknown_word` | كلمة غير معروفة |
| `mg_family_unknown_variant` | عائلة معروفة بلا مرشح مطابق |
| `low_confidence` | ثقة قراءة منخفضة |
| `qty` | كمية ناقصة/خارج المدى |
| `line_math` | حساب السطر غير متسق |
| `catalog_unmatched` | لم يُطابق الكتالوج |
| `price_missing` / `qty_missing` / `ambiguous_roles` | أعلام قديمة من مسار الفاتورة المطبوعة |

### 14.5 `review_status`

| قيمة | معنى |
|------|------|
| `needs` | يحتاج مراجعة |
| `ready` | جاهز للاعتماد |
| `approved` | معتمد |
| `excluded` | مستبعد |
| `added` | دخل نتيجة apply |

### 14.6 `match_decision`

| قيمة | معنى |
|------|------|
| `auto` | مطابقة فريدة تلقائية |
| `alias` | من اختيارات/تعلّم سابق |
| `manual` | يدوي / بلا مرشح كافٍ |
| `needs_choice` | يحتاج قرار |

### 14.7 `lexicon_family`

السيرفر يرسل قائمة كاملة في `batch.lexicon_families`. المفاتيح من `config/ocr_lexicon.php`:

`pipe`, `tee`, `y_tee`, `access_tee`, `access_elbow`, `elbow`, `coupling`, `reducer`, `end_cap`, `plug`, `floor_trap`, `odor_trap`, `vent_cap`, `cross`

على مستوى البند: `slots.lexicon_family` و/أو `family_candidates` / `family_chips` عند الحاجة.

`family_chips` (للبند المجهول):

```json
[
  { "key": "pipe", "label": "ماسورة" },
  { "key": "elbow", "label": "كوع" },
  { "key": "tee", "label": "مثلث" },
  { "key": "y_tee", "label": "مثلث واي" },
  { "key": "coupling", "label": "وصلة" },
  { "key": "reducer", "label": "مخفض" }
]
```

### 14.8 بند `draft.lines[]` كامل (مثال)

```json
{
  "ocr_line_id": 901,
  "ocr_image_id": 44,
  "raw_text": "كوع ٩٠ ١ بوصه نظام امريك MG",
  "qty": 4,
  "matched_product_id": 42,
  "name": "كوع 90 – 1 بوصة MG",
  "match_decision": "auto",
  "match_status": "matched",
  "match_reason": "من الكتابة",
  "review_status": "ready",
  "approved": false,
  "needs_review": [],
  "candidates": [],
  "understanding": null,
  "family_candidates": [],
  "slots": {
    "family": "elbow_90",
    "lexicon_family": "elbow",
    "size_value": 1,
    "size_unit": "in",
    "system": "us",
    "system_source": "text",
    "mg_brand": true
  },
  "pieces_per_box": null,
  "points_per_unit": 2,
  "main_image": "…",
  "available_qty": 120,
  "in_stock": true,
  "qty_exceeds_stock": false
}
```

---

## 15) حدود وتشغيل

- حتى 10 صور / دفعة؛ ~2 متوازي على السيرفر
- Poll إلزامي
- تنظيف الصور: `php artisan ocr:prune-uploads` يومياً 03:50 (Africa/Tripoli)
- OCR لا يخصم مخزوناً ولا يعطي نقاطاً

---

## 16) اختبار سريع

```bash
TOKEN=…
BASE=https://mg-plastic.com/api/v1/mobile/distributor/pos/ocr

curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" "$BASE/current"
# تحقق: gemini_enabled موجود مع batch=null

curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"system_default":"ask"}' "$BASE/batches"
```
