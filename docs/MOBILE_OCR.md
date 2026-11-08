# POS OCR — دليل مطوّر الموبايل

> الباك إند جاهز. نفّذ الشاشات حسب هذا الملف ثم اربط الـ API.

**Base (جملة):** `/api/v1/mobile/distributor/pos/ocr`  
**Base (قطاعي):** `/api/v1/mobile/trader/pos/ocr`  
**Auth:** `Authorization: Bearer {token}`  
**Checkout:** `POST …/pos/checkout` — OCR لا يُصدر فاتورة.

مرتبط: [MOBILE_POS_UX.md](./MOBILE_POS_UX.md) · [MOBILE_PENDING_RECEIPTS.md](./MOBILE_PENDING_RECEIPTS.md)

---

## 0) قرارات العقد (ملخّص)

| قرار | السلوك |
|------|--------|
| مخزون approve/apply | **غير حاجز**. أعلام فقط. البنود `in_stock=false` تخرج من `cart_lines` إلى `unavailable_lines`. `qty_exceeds_stock` يبقى في `cart_lines`. |
| مصدر `available_qty` | **مخزون POS للبائع** (`stock_source: "seller_pos"`) — جملة أو قطاعي — **ليس** مخزن المصنع. |
| OCR token → checkout | مرفوض. استخدم هيدر عام `Idempotency-Key` على checkout. |
| صور OCR | قرص خاص؛ `preview_url` = رابط موقّع 30 د (يُجدَّد مع كل GET). |
| الوحدات | `qty_unit` + `qty_unit_source` + `set_unit` + `unit_assumed`. |
| UI | `ui_action` واحد لكل بند. |
| ترجمة | `message_code` بجانب كل `message`. |
| prefs بعد `added` | **409** `OCR_BATCH_LOCKED`. |

---

## 1) التدفق

```
GET /current  → gemini_enabled؟ resumable؟
POST batches → images | paste
poll GET batches/{id}
مراجعة (ui_action) → approve / set_unit / set_qty …
POST apply → cart_lines + unavailable_lines
ادمج cart_lines → POST pos/checkout (+ Idempotency-Key)
```

---

## 2) Checklist

- [ ] أخفِ OCR لو `gemini_enabled=false` من `/current` حتى مع `batch=null`
- [ ] اعرض `available_qty` من مخزون **البائع**؛ حذّر عند `qty_exceeds_stock`
- [ ] بعد apply: ادمج فقط `cart_lines`؛ اعرض `unavailable_lines` للمستخدم
- [ ] استرجاع: `GET` عند `step=added` أو 409 apply
- [ ] استخدم `preview_url` الموقّع؛ جدّده عند انتهاء `preview_expires_at`
- [ ] نفّذ `ui_action` كما يرسله السيرفر
- [ ] ترجم عبر `message_code` (النص العربي fallback)
- [ ] checkout: هيدر `Idempotency-Key: {uuid جديد لكل محاولة}`
- [ ] لا ترسل `idempotency_token` الخاص بـ OCR إلى checkout

---

## 3) Endpoints

نفس جدول المسارات السابق تحت `{base}/…`، مع:

| Method | Path | ملاحظة |
|--------|------|--------|
| PATCH | `/batches/{id}/prefs` | **409** لو `step=added` |
| PATCH | `/batches/{id}/lines/{lineId}` | أُضيف `set_unit` |
| POST | `/batches/{id}/apply` | يرجّع `unavailable_lines` أيضاً |
| POST | `…/pos/checkout` | هيدر اختياري `Idempotency-Key` |

---

## 4) `GET /current`

```json
{
  "status": true,
  "message": "لا توجد دفعة معلّقة",
  "message_code": "ocr_no_pending_batch",
  "data": {
    "batch": null,
    "resumable": false,
    "gemini_enabled": true
  }
}
```

---

## 5) Poll — حقول مهمّة جديدة

```json
{
  "batch_id": 12,
  "step": "review",
  "stock_source": "seller_pos",
  "images": [
    {
      "id": 44,
      "preview_url": "https://…/ocr-images/44/preview?expires=…&signature=…",
      "preview_expires_at": "2026-11-08T22:10:00+02:00",
      "status": "done"
    }
  ],
  "draft": { "lines": [], "excluded": [] },
  "cart_lines": null,
  "unavailable_lines": null,
  "lexicon_families": [{ "key": "elbow", "label": "كوع" }],
  "gemini_enabled": true
}
```

عند `step=added`: `cart_lines` و`unavailable_lines` مصفوفتان (قد تكون إحداهما فارغة).

---

## 6) تعديل بند — `set_unit`

```http
PATCH …/batches/12/lines/901
{ "action": "set_unit", "qty_unit": "piece" }
```

actions: `approve` | `unapprove` | `select_product` | `set_qty` | **`set_unit`** | `choose_system` | `choose_family` | `confirm_understanding`

---

## 7) Apply

```json
{
  "status": true,
  "message": "تم تجهيز 3 منتج للسلة",
  "message_code": "ocr_apply_ok",
  "data": {
    "cart_lines": [
      {
        "product_id": 42,
        "name": "كوع 90 – 1 بوصة MG",
        "quantity": 4,
        "boxes": null,
        "pieces_per_box": null,
        "qty_unit": "piece",
        "qty_unit_source": "paper",
        "available_qty": 120,
        "in_stock": true,
        "qty_exceeds_stock": false,
        "points_per_unit": 2,
        "image": "…"
      }
    ],
    "unavailable_lines": [
      {
        "product_id": 99,
        "name": "ماسورة …",
        "quantity": 10,
        "boxes": 1,
        "pieces_per_box": 10,
        "qty_unit": "box",
        "available_qty": 0,
        "in_stock": false,
        "qty_exceeds_stock": true,
        "reason": "out_of_stock"
      }
    ],
    "approved_count": 3,
    "idempotency_token": "uuid…",
    "next": {
      "merge_into_local_cart": true,
      "checkout": "POST …/pos/checkout …",
      "checkout_idempotency_header": "Idempotency-Key",
      "send_ocr_idempotency_token_on_checkout": false
    }
  }
}
```

قواعد التقسيم:

- `in_stock=false` → `unavailable_lines` + `reason: "out_of_stock"`
- `in_stock=true` حتى لو `qty_exceeds_stock=true` → يبقى في `cart_lines` (الـ checkout يحرس)

---

## 8) Checkout + Idempotency-Key

```http
POST /api/v1/mobile/distributor/pos/checkout
Authorization: Bearer …
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
Content-Type: application/json

{
  "retail_trader_id": 55,
  "lines": [{ "product_id": 42, "quantity": 4 }]
}
```

| الحالة | النتيجة |
|--------|---------|
| بدون الهيدر | سلوك قديم تماماً |
| نفس المفتاح + نفس الجسم خلال 24س | نفس رد الفاتورة الأصلي (بدون خصم مزدوج) |
| نفس المفتاح + جسم مختلف | **422** `error_code: IDEMPOTENCY_KEY_REUSED` / `message_code: idempotency_key_reused` |

أنشئ UUID **جديد لكل محاولة checkout** ناجحة من منظور المستخدم (إعادة إرسال نفس المحاولة = نفس المفتاح).

---

## 9) prefs بعد apply

```http
PATCH …/batches/12/prefs
{ "system_default": "us", "reenrich": true }
```

| حالة الدفعة | النتيجة |
|-------------|---------|
| `draft` / `reading` / `review` | يحدّث prefs؛ `reenrich` يعيد تصنيف البنود بدون Gemini |
| `added` (بعد apply) | **409** `OCR_BATCH_LOCKED` — لا يُعاد enrich |

نفس القفل على `PATCH …/lines/…`.

---

## 14) قاموس الحقول (JSON كامل)

### 14.1 بند `draft.lines[]`

```json
{
  "ocr_line_id": 901,
  "ocr_image_id": 44,
  "raw_text": "كوع ٩٠ ١ بوصه صندوق MG",
  "qty": 2,
  "qty_unit": "box",
  "qty_unit_source": "paper",
  "matched_product_id": 42,
  "name": "كوع 90 – 1 بوصة MG",
  "match_decision": "auto",
  "match_status": "matched",
  "match_reason": "من الكتابة",
  "review_status": "ready",
  "approved": false,
  "needs_review": [],
  "ui_action": "none",
  "candidates": [],
  "understanding": null,
  "family_candidates": [],
  "family_chips": [],
  "slots": {
    "family": "elbow_90",
    "lexicon_family": "elbow",
    "size_value": 1,
    "size_unit": "in",
    "system": "us",
    "mg_brand": true
  },
  "pieces_per_box": 10,
  "points_per_unit": 2,
  "main_image": "…",
  "available_qty": 120,
  "in_stock": true,
  "qty_exceeds_stock": false
}
```

### 14.2 `qty_unit` / `qty_unit_source`

| حقل | قيم | معنى |
|-----|------|------|
| `qty_unit` | `box` \| `piece` | وحدة الكمية على الورقة / الافتراض |
| `qty_unit_source` | `paper` \| `default` | من نص الورقة أو افتراض السيرفر |

كشف من الورقة: كرتونة / صندوق / علبة → `box`؛ قطعة / حبة / دستة → `piece`.  
لو المصدر `default` و`pieces_per_box > 1` → `needs_review` يتضمن `unit_assumed`.

عند التحويل للسلة: `box` + ppb → `boxes=qty`, `quantity=qty×ppb`؛ `piece` → `quantity=qty`, `boxes=null` (حتى لو المنتج له صناديق).

### 14.3 `ui_action`

| قيمة | متى |
|------|-----|
| `choose_family` | `ambiguous_family` / `unknown_word` / `family_candidates` / `family_chips` |
| `confirm_understanding` | `unconfirmed_term` + وجود `understanding` |
| `pick_candidate` | يحتاج اختيار ومنتج من `candidates` |
| `fix_qty` | `unit_assumed` / نقص كمية |
| `none` | جاهز أو معتمد |

أولوية السيرفر بهذا الترتيب — **لا تعِد حسابها في التطبيق**.

### 14.4 `needs_review` (+ جديد)

| كود | ترجمة مقترحة |
|-----|----------------|
| `needs_choice` | يحتاج اختيارك |
| `ambiguous_family` | حدّد نوع القطعة |
| `unconfirmed_term` | أكّد الفهم |
| `unknown_word` | كلمة غير معروفة |
| `mg_family_unknown_variant` | لا مرشح مطابق |
| `low_confidence` | قراءة ضعيفة |
| `qty` / `qty_missing` | راجع الكمية |
| **`unit_assumed`** | وحدة افترضها النظام — أكّد صندوق/قطعة |
| `line_math` / `catalog_unmatched` / … | كما السابق |

### 14.5 `review_status` / `match_decision`

بدون تغيير: `needs`|`ready`|`approved`|`excluded`|`added`  
و`auto`|`alias`|`manual`|`needs_choice`.

### 14.6 `draft.excluded[]`

```json
{
  "raw_text": "ماسورة بدون MG",
  "qty": 2,
  "reason": "plumbing_without_mg",
  "exclude_label": "بدون علامة MG",
  "match_status": "not_mg"
}
```

### 14.7 `merge_suggestions[]`

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

تنبيه اختياري؛ `apply` يدمج نفس `product_id` تلقائياً داخل كل قائمة.

### 14.8 `candidates[]` item

```json
{
  "product_id": 42,
  "name": "كوع 90 – 1 بوصة MG",
  "system": "us",
  "size_value": 1,
  "size_unit": "in",
  "pieces_per_box": 10,
  "points_per_unit": 2,
  "main_image": "…",
  "flags": { "family": true, "size": true, "angle": true, "system": true, "variant": true, "size2": true },
  "rank": 90,
  "similarity": 0.82,
  "available_qty": 50,
  "in_stock": true
}
```

### 14.9 `lexicon_families`

من السيرفر في كل batch:  
`pipe`, `tee`, `y_tee`, `access_tee`, `access_elbow`, `elbow`, `coupling`, `reducer`, `end_cap`, `plug`, `floor_trap`, `odor_trap`, `vent_cap`, `cross`

### 14.10 `unavailable_lines[]`

نفس شكل عنصر `cart_lines` + `reason: "out_of_stock"`.

### 14.11 `message` + `message_code`

كل رد API يحمل الاثنين. أمثلة:

| message_code | استخدام |
|--------------|---------|
| `ocr_no_pending_batch` | current فارغ |
| `ocr_apply_ok` | apply نجح |
| `ocr_already_applied` / `OCR_ALREADY_APPLIED` | 409 apply |
| `ocr_batch_locked` / `OCR_BATCH_LOCKED` | prefs/lines بعد added |
| `ocr_duplicate_image` / `OCR_DUPLICATE_IMAGE` | 409 صورة |
| `idempotency_key_reused` | 422 checkout |
| `pos_checkout_pending_retail` | checkout جملة نجح |

---

## 15) تخزين الصور

- رفع جديد → قرص `local` (private)
- `preview_url` = موقّع 30 دقيقة (إعداد `OCR_SIGNED_PREVIEW_MINUTES`)
- تنظيف: `ocr:prune-uploads` يومياً (افتراضي 30 يوم، `OCR_UPLOAD_RETENTION_DAYS`)
- ترحيل القديم: انظر أوامر الإنتاج أدناه

---

## 16) أوامر الإنتاج

```bash
# 1) ترحيل الملفات العامة → الخاص (معاينة أولاً)
php artisan ocr:migrate-uploads-private
php artisan ocr:migrate-uploads-private --apply

# 2) تنظيف يدوي (اختياري)
php artisan ocr:prune-uploads --dry-run
php artisan ocr:prune-uploads

# 3) بعد النشر
php artisan optimize:clear
# أو: php artisan config:cache && php artisan route:cache
```

الجدولة موجودة: `ocr:prune-uploads` يومياً 03:50 (Africa/Tripoli).

---

## 17) ملفات تغيّرت / اختبارات

**كود:**  
`OcrStorage`, `OcrQtyUnitResolver`, `OcrLineUiAction`, `PosCheckoutIdempotency`,  
`MobileOcrPresenter`, `OcrBatchService`, `PosOcrController`,  
`Distributor\PosController`, `Trader\PosController`, `ApiResponds`,  
`OcrImagePreviewController`, `ProcessOcrImageJob`,  
`CreateWholesaleInvoice` (Filament signed preview),  
`OcrPruneUploadsCommand`, `OcrMigrateUploadsPrivateCommand`,  
`config/ocr_uploads.php`, `routes/console.php`, `docs/MOBILE_OCR.md`

**اختبارات:**  
`php artisan test --filter=MobilePosOcrTest`  
`php artisan test --filter=PosCheckoutIdempotencyTest`  
`php artisan test --filter=OcrBatchResilienceTest`  
`php artisan test --filter=InvoiceOcrPosCartTest`
