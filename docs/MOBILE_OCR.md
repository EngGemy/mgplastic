# OCR — دليل مطوّر الموبايل (POS + طلب توريد)

> الباك إند جاهز. نفّذ الشاشات حسب هذا الملف ثم اربط الـ API.

## Base paths

| الشاشة | الدور | Base |
|--------|--------|------|
| نقطة البيع | جملة | `/api/v1/mobile/distributor/pos/ocr` |
| نقطة البيع | قطاعي | `/api/v1/mobile/trader/pos/ocr` |
| طلب توريد | جملة ← مصنع/مخزن | `/api/v1/mobile/distributor/orders/ocr` |
| طلب توريد | قطاعي ← جملة | `/api/v1/mobile/trader/orders/ocr` |
| طلب توريد | سباك ← محل | `/api/v1/mobile/plumber/orders/ocr` |

**Auth:** `Authorization: Bearer {token}`  
**OCR لا يخصم مخزون ولا يمنح نقاط** (`affects_inventory=false`, `affects_points=false`).  
التوريد يُكمَل بـ `POST …/orders`؛ البيع بـ `POST …/pos/checkout`.

مرتبط: [MOBILE_POS_UX.md](./MOBILE_POS_UX.md) · [MOBILE_PENDING_RECEIPTS.md](./MOBILE_PENDING_RECEIPTS.md)

---

## 0) قرارات العقد

| قرار | POS (`purpose=pos`) | طلب توريد (`purpose=supply_order`) |
|------|---------------------|-------------------------------------|
| مسار الـ API | `…/pos/ocr` | `…/orders/ocr` |
| `source` | لا يُرسل | **مطلوب:** `factory` \| `warehouse` \| `wholesale` \| `shop` |
| هوية المورّد | — | `warehouse_id` / `wholesaler_id` / `retail_trader_id` حسب المصدر |
| `stock_source` | `seller_pos` | `factory_catalog` / `warehouse_stock` / `wholesaler_stock` / `shop_stock` |
| مخزون | مخزون بائع نقطة البيع — **`available_qty` ظاهر** | أعلام فقط افتراضياً: `in_stock` + `qty_exceeds_stock` + `stock_level` (`none`\|`low`\|`available`). الكمية الدقيقة خلف `ocr.expose_supplier_qty=false` |
| مورّد غير مسموح | — | **403** `ocr_supplier_not_allowed` (نفس قواعد `POST …/orders`) |
| `in_stock=false` عند apply | يخرج إلى `unavailable_lines` | **يبقى في `cart_lines`** مع الأعلام — البنود المطلوبة للتوريد |
| دور السباك | ممنوع (422) | مسموح على `…/plumber/orders/ocr` فقط مع `source=shop` |
| `/current` | دفعات `purpose=pos` فقط + `quota_remaining` | يفلتر بـ `purpose` + `source` + معرف المورّد + `quota_remaining` |
| بعد apply | `POST …/pos/checkout` + `Idempotency-Key` | `POST …/orders` + اختياري `Idempotency-Key` و`ocr_batch_id` |
| حدود يومية | حصة صور حسب الدور؛ سقف Gemini عام | 429 `ocr_daily_limit_reached` / 503 `ocr_capacity_reached` |

باقي القرارات المشتركة:

| قرار | السلوك |
|------|--------|
| OCR token → checkout/order | مرفوض. |
| صور OCR | قرص خاص؛ `preview_url` موقّع 30 د. |
| الوحدات | `qty_unit` + `qty_unit_source` + `set_unit` + `unit_assumed`. |
| UI | `ui_action` واحد لكل بند. |
| ترجمة | `message_code` بجانب كل `message`. |
| prefs بعد `added` | **409** `OCR_BATCH_LOCKED`. |

---

## 1) مصفوفة الأدوار × المصدر

| الدور | `source` | حقول مطلوبة مع `POST /batches` و`GET /current` | `stock_source` | ملاحظات مخزون |
|-------|----------|-----------------------------------------------|----------------|----------------|
| جملة | `factory` | — | `factory_catalog` | كتالوج كامل؛ `available_qty=null`؛ لا يُشال بند بسبب المخزون |
| جملة | `warehouse` | `warehouse_id` (= `supplier_user_id`) | `warehouse_stock` | **مخزن مركزي فقط** (`is_central_warehouse=true`). `supplier_user_id` في الرد = معرف مستخدم المخزن |
| قطاعي | `wholesale` | `wholesaler_id` (أو `wholesale_distributor_id` / `supplier_id`) | `wholesaler_stock` | مخزون الموزّع |
| سباك | `shop` | `retail_trader_id` | `shop_stock` | كتالوج المطابقة كامل؛ الأعلام من مخزون المحل؛ OOS يبقى في `cart_lines` |

---

## 2) التدفق

```
GET /current?…     → gemini_enabled؟ resumable لهذا السياق فقط؟
POST batches       → purpose + source + ids
images | paste
poll GET batches/{id}
مراجعة (ui_action) → approve / set_unit / set_qty …
POST apply         → cart_lines (+ unavailable_lines في POS فقط)
ادمج cart_lines → POST pos/checkout  أو  POST orders
```

---

## 3) Checklist

### مشترك
- [ ] أخفِ OCR لو `gemini_enabled=false` من `/current` حتى مع `batch=null`
- [ ] استخدم `preview_url` الموقّع؛ جدّده عند انتهاء `preview_expires_at`
- [ ] نفّذ `ui_action` كما يرسله السيرفر
- [ ] ترجم عبر `message_code`
- [ ] لا ترسل `idempotency_token` الخاص بـ OCR إلى checkout/orders

### نقطة البيع
- [ ] Base = `…/pos/ocr` — لا تمرّر `purpose=supply_order`
- [ ] بعد apply: ادمج فقط `cart_lines`؛ اعرض `unavailable_lines`
- [ ] checkout: هيدر `Idempotency-Key`

### طلب توريد
- [ ] Base = `…/orders/ocr` (سباك: `…/plumber/orders/ocr`)
- [ ] مرّر دائماً `source` + معرف المورّد في `POST /batches` و`GET /current`
- [ ] لا تعتمد على `…/pos/ocr/current` لعرض «متابعة» شاشة الطلب — السياقات منفصلة
- [ ] عند الموزّع: `source=factory` منفصل عن كل `warehouse_id`
- [ ] بعد apply: كل البنود المعتمدة في `cart_lines` (حتى `in_stock=false`)؛ أظهر تحذير مخزون في الواجهة إن لزم
- [ ] أكمل بـ `POST …/orders` — OCR لا يخصم مخزون

---

## 4) Endpoints

نفس المسارات تحت `{base}/…`:

| Method | Path | ملاحظة |
|--------|------|--------|
| GET | `/current` | استعلام السياق إلزامي لطلب التوريد؛ يرجّع `quota_remaining` |
| GET | `/meta` | enums + limits + `contract_version` + `min_app_version` + قائمة `message_codes` |
| POST | `/batches` | جسم السياق أدناه |
| GET | `/batches/{id}` | poll + `poll_after_ms` + ETag / If-None-Match → **304** |
| POST | `/batches/{id}/images` | multipart jpg/png/webp (heic إن دعم Imagick) |
| POST | `/batches/{id}/paste` | `{ text }` |
| PATCH | `/batches/{id}/prefs` | **409** لو `step=added` |
| PATCH | `/batches/{id}/lines/{lineId}` | أُضيف `set_unit` |
| POST | `/batches/{id}/approve-ready` | |
| POST | `/batches/{id}/apply` | يجهّز السلة فقط |
| POST | `/batches/{id}/abandon` | |
| POST | `/batches/{id}/reread` | بعد `ocr_reading_timeout` |

---

## 5) إنشاء دفعة — `POST /batches`

### POS

```http
POST /api/v1/mobile/distributor/pos/ocr/batches
{ "customer_user_id": 55, "system_default": "ask" }
```

`purpose` يُفترض `pos` تلقائياً على مسار `pos/ocr`.

### طلب توريد — أمثلة

```http
POST /api/v1/mobile/distributor/orders/ocr/batches
{ "source": "factory" }
```

```http
POST /api/v1/mobile/distributor/orders/ocr/batches
{ "source": "warehouse", "warehouse_id": 88 }
```

```http
POST /api/v1/mobile/trader/orders/ocr/batches
{ "source": "wholesale", "wholesaler_id": 12 }
```

```http
POST /api/v1/mobile/plumber/orders/ocr/batches
{ "source": "shop", "retail_trader_id": 40 }
```

يمكن إرسال `purpose: "supply_order"` صراحة؛ على مسار `orders/ocr` هو الافتراضي.

Aliases للمورّد عند `wholesale`: `wholesaler_id` | `wholesale_distributor_id` | `supplier_id` | `supplier_user_id`.  
Aliases عند `warehouse`/`shop`: `supplier_user_id` بدل الحقل المخصّص.

---

## 6) `GET /current` — عزل السياق

### POS

```http
GET …/pos/ocr/current
```

يرجع فقط دفعة `purpose=pos`. ورقة طلب توريد **لن** تظهر كـ «متابعة».

### طلب توريد

```http
GET …/orders/ocr/current?source=factory
GET …/orders/ocr/current?source=warehouse&warehouse_id=88
GET …/orders/ocr/current?source=wholesale&wholesaler_id=12
GET …/orders/ocr/current?source=shop&retail_trader_id=40
```

بدون `source` (وفي warehouse/wholesale/shop بدون المعرف) → **422**.

```json
{
  "status": true,
  "message": "لا توجد دفعة معلّقة",
  "message_code": "ocr_no_pending_batch",
  "data": {
    "batch": null,
    "resumable": false,
    "purpose": "supply_order",
    "source": "warehouse",
    "supplier_user_id": 88,
    "gemini_enabled": true
  }
}
```

---

## 7) شكل الدفعة (poll / create)

حقول سياق جديدة على `data.batch`:

```json
{
  "batch_id": 12,
  "step": "review",
  "purpose": "supply_order",
  "source": "shop",
  "supplier_user_id": 40,
  "stock_source": "shop_stock",
  "affects_inventory": false,
  "affects_points": false,
  "draft": { "lines": [], "excluded": [] },
  "cart_lines": null,
  "unavailable_lines": null,
  "gemini_enabled": true
}
```

عند `step=added`: `cart_lines` و`unavailable_lines` مصفوفتان.

أعلام البند في `draft.lines[]` / المرشّحين:

| الحقل | POS / مخزن / جملة / محل | مصنع |
|-------|-------------------------|------|
| `available_qty` | عدد قطع متاح عند المصدر | `null` |
| `in_stock` | `available_qty > 0` | `true` إن وُجد منتج |
| `qty_exceeds_stock` | الكمية المطلوبة > المتاح | دائماً `false` |

---

## 8) تعديل بند — `set_unit`

```http
PATCH …/batches/12/lines/901
{ "action": "set_unit", "qty_unit": "piece" }
```

actions: `approve` | `unapprove` | `select_product` | `set_qty` | **`set_unit`** | `choose_system` | `choose_family` | `confirm_understanding`

---

## 9) Apply

### POS — تقسيم متاح / غير متاح

```json
{
  "cart_lines": [ { "product_id": 42, "quantity": 4, "boxes": null, "pieces_per_box": null, "in_stock": true } ],
  "unavailable_lines": [ { "product_id": 99, "quantity": 10, "in_stock": false, "reason": "out_of_stock" } ],
  "next": {
    "merge_into_local_cart": true,
    "checkout": "POST …/pos/checkout …",
    "checkout_idempotency_header": "Idempotency-Key",
    "send_ocr_idempotency_token_on_checkout": false
  }
}
```

قواعد POS:

- `in_stock=false` → `unavailable_lines` + `reason: "out_of_stock"`
- `in_stock=true` حتى لو `qty_exceeds_stock=true` → يبقى في `cart_lines`

### طلب توريد — كل المعتمد في `cart_lines`

```json
{
  "status": true,
  "message": "تم تجهيز 2 منتج للسلة",
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
        "available_qty": 0,
        "in_stock": false,
        "qty_exceeds_stock": true
      }
    ],
    "unavailable_lines": [],
    "approved_count": 2,
    "idempotency_token": "uuid…",
    "affects_inventory": false,
    "affects_points": false,
    "purpose": "supply_order",
    "source": "shop",
    "supplier_user_id": 40,
    "stock_source": "shop_stock",
    "next": {
      "merge_into_local_cart": true,
      "place_order": "POST …/orders بنفس شكل lines (product_id + quantity بالقطع، boxes اختياري)",
      "send_ocr_idempotency_token_on_order": false
    }
  }
}
```

السباك/القطاعي/الجملة: اعرض تحذير «غير متوفر عند المورّد» من `in_stock` / `qty_exceeds_stock` داخل `cart_lines` — لا تعتمد على `unavailable_lines` في طلب التوريد.

شكل السطر للدمج مع شاشة الطلب: `product_id`, `quantity` (قطع), `boxes`, `pieces_per_box`.

---

## 10) Checkout / Order بعد OCR

### POS

```http
POST /api/v1/mobile/distributor/pos/checkout
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
{ "retail_trader_id": 55, "lines": [{ "product_id": 42, "quantity": 4 }] }
```

| الحالة | النتيجة |
|--------|---------|
| بدون الهيدر | سلوك قديم تماماً |
| نفس المفتاح + نفس الجسم خلال 24س | نفس رد الفاتورة الأصلي |
| نفس المفتاح + جسم مختلف | **422** `IDEMPOTENCY_KEY_REUSED` |

### طلب توريد

استخدم عقود الطلب الحالية لكل دور (`POST …/plumber/orders`, `…/trader/orders`, `…/distributor/orders`) مع `lines` من `cart_lines`. OCR لا يغيّر مخزوناً ولا نقاطاً.

---

## 11) prefs بعد apply

```http
PATCH …/batches/12/prefs
{ "system_default": "us", "reenrich": true }
```

| حالة الدفعة | النتيجة |
|-------------|---------|
| `draft` / `reading` / `review` | يحدّث prefs؛ `reenrich` يعيد تصنيف البنود بدون Gemini |
| `added` (بعد apply) | **409** `OCR_BATCH_LOCKED` |

نفس القفل على `PATCH …/lines/…`.

---

## 12) قاموس الحقول (JSON كامل)

### 12.1 بند `draft.lines[]`

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
    "angle": 90,
    "system": "us"
  },
  "available_qty": 120,
  "in_stock": true,
  "qty_exceeds_stock": false,
  "stock_level": "available",
  "pieces_per_box": 10
}
```

في طلب التوريد مع `expose_supplier_qty=false`: `available_qty` يكون `null` ويبقى `stock_level` / `in_stock` / `qty_exceeds_stock`.

### 12.1b مثال `cart_lines` كامل (POS)

```json
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
  "stock_level": "available",
  "points_per_unit": 2,
  "image": "https://…/products/42.jpg",
  "ocr_line_id": 901
}
```

### 12.2 صورة

```json
{
  "id": 44,
  "status": "done",
  "preview_url": "https://…/ocr-images/44/preview?expires=…&signature=…",
  "preview_expires_at": "2026-11-08T22:10:00+02:00",
  "error": null,
  "lines_count": 3
}
```

### 12.3 `ui_action`

| قيمة | معنى مختصر |
|------|------------|
| `none` | جاهز للاعتماد أو معتمد |
| `choose_system` | اختيار نظام |
| `choose_family` | اختيار عائلة |
| `confirm_understanding` | تأكيد الفهم |
| `select_product` | اختيار منتج من مرشّحين |
| `set_qty` | ضبط كمية |
| `set_unit` | ضبط وحدة (قطعة/صندوق) |
| `review` | مراجعة عامة |

### 12.4 قائمة `message_code` كاملة

| code | HTTP | معنى |
|------|------|------|
| `ocr_no_pending_batch` | 200 | لا دفعة معلّقة |
| `ocr_pending_batch` | 200 | دفعة معلّقة |
| `ocr_batch_ready` | 201 | دفعة جاهزة |
| `ocr_image_reading` | 201 | بدأت القراءة |
| `ocr_paste_started` | 201 | بدأ تحليل النص |
| `ocr_image_retried` | 200 | إعادة محاولة |
| `ocr_image_canceled` | 200 | أُلغيت الصورة |
| `ocr_reread_started` | 200 | بدأت إعادة القراءة |
| `ocr_batch_abandoned` | 200 | تم التجاهل |
| `ocr_prefs_updated` | 200 | تحديث prefs |
| `ocr_line_updated` | 200 | تحديث بند |
| `ocr_approve_ready_done` | 200 | اعتماد الجاهز |
| `ocr_apply_ok` | 200 | apply نجح |
| `ocr_already_applied` | 409 | apply مكرّر |
| `ocr_no_approved_lines` | 422 | لا بنود معتمدة |
| `ocr_batch_not_found` | 404 | دفعة غير موجودة/ليست ملكك |
| `ocr_image_not_found` | 404 | صورة غير موجودة |
| `ocr_line_not_found` | 404 | بند غير موجود |
| `ocr_invalid_image` | 422 | صورة غير صالحة |
| `ocr_duplicate_image` | 409 | صورة مكررة — الجسم فيه `image_id` + `image_status` |
| `ocr_gemini_disabled` | 503 | لا يوجد `GEMINI_API_KEY` |
| `ocr_batch_closed` | 422 | دفعة مغلقة |
| `ocr_batch_locked` | 409 | بعد apply |
| `ocr_supplier_not_allowed` | 403 | مورّد خارج قواعد الطلبات |
| `ocr_daily_limit_reached` | 429 | حصة المستخدم اليومية |
| `ocr_capacity_reached` | 503 | سقف Gemini العام |
| `ocr_image_too_large` | 413 | أكبر من 8MB |
| `ocr_image_type_unsupported` | 415 | نوع غير مدعوم / HEIC بدون Imagick |
| `ocr_batch_image_limit` | 422 | أكثر من 10 صور |
| `ocr_reading_timeout` | — | صورة `reading` > 5 دقائق → `failed` (استدعِ `/reread`) |
| `idempotency_key_invalid` | 422 | مفتاح تكرار غير صالح |
| `idempotency_key_reused` | 422 | نفس المفتاح بجسم مختلف |
| `validation_failed` | 422 | تحقق عام |

`GET …/meta` يرجّع نفس القائمة تحت `data.message_codes`.

---

## 13) أخطاء شائعة

| HTTP | متى |
|------|-----|
| 403 | دور غير مصرّح **أو** `ocr_supplier_not_allowed` |
| 404 | دفعة/صورة/بند ليست للمستخدم الحالي |
| 413 | `ocr_image_too_large` |
| 415 | `ocr_image_type_unsupported` |
| 422 | تحقق / حد صور الدفعة / IDEMPOTENCY_KEY_REUSED |
| 429 | `ocr_daily_limit_reached` (+ `retry_after`) |
| 409 | صورة مكررة / apply مكرّر / دفعة مقفلة |
| 503 | Gemini معطّل أو `ocr_capacity_reached` |

عند 409 duplicate: استمر على `errors.image_id` / `errors.image_status` بدل رفع جديد.

---

## 14) تشغيل / صيانة (إنتاج — إلزامي بالترتيب)

```bash
# 1) Migrations (إلزامي)
php artisan migrate --force

# 2) نقل صور OCR القديمة للقرص الخاص (جرّب dry-run أولاً)
php artisan ocr:migrate-uploads-private --dry-run
php artisan ocr:migrate-uploads-private

# 3) إعادة بناء الكاش — لا تستخدم optimize:clear في الإنتاج
# (يمسح نبض الطابور وتنبيهات المراقبة وقاطع واتساب وحصص OCR اليومية)
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4) إعادة تشغيل عمّال الطوابير (cPanel)
php artisan queue:restart
# Worker A: whatsapp,default  --timeout=60
# Worker B: ocr              --timeout=180
# DB_QUEUE_RETRY_AFTER=300
# فترة سماح 3 دقائق بعد restart قبل اعتبار غياب النبض CRITICAL
```

**Cron / Schedule**

| الأمر | التكرار |
|-------|---------|
| `ocr:maintain` | كل 5 دقائق — فشل `reading` العالق + abandon بعد 7 أيام |
| `ocr:prune-uploads` | يومياً 03:50 — احتفاظ 90 يوم للمرتبط بطلب/فاتورة، 7 أيام لغير المطبّق |
| `health:monitor` (إن وُجد) | يتضمن فحوصات OCR backlog / error rate / daily cap |

**متغيرات بيئة جديدة (اختياري — لها افتراضيات في `config/ocr.php`)**

| Key | افتراضي |
|-----|---------|
| `OCR_EXPOSE_SUPPLIER_QTY` | `false` |
| `OCR_DAILY_QUOTA_PLUMBER` | `20` |
| `OCR_DAILY_QUOTA_TRADER` | `50` |
| `OCR_DAILY_QUOTA_DISTRIBUTOR` | `100` |
| `OCR_GLOBAL_GEMINI_DAILY_CAP` | `800` |
| `OCR_JOB_TIMEOUT` | `150` |
| `OCR_MAX_UPLOAD_BYTES` | `8388608` |
| `OCR_MAX_IMAGE_SIDE` | `2000` |
| `OCR_MIN_APP_VERSION` | null |
| `OCR_ACCEPT_HEIC` | `true` |

لا تغيّر قيم WAHA في `.env`. لا Redis/Horizon — `QUEUE_CONNECTION=database` و`CACHE_STORE=file`.

**ملاحظات تشغيلية**

- طابور OCR مستقل: `ocr` (timeout job=150 < worker=180 < `DB_QUEUE_RETRY_AFTER`=300).
- إعادة المحاولة لا تستدعي Gemini مرتين إن وُجد `result_json` على الصورة.
- `supplier_user_id` عند `source=warehouse` = معرف مستخدم المخزن المركزي (ليس صف مخزون منفصل).

---

## 15) ملفات / اختبارات

**كود أساسي:**  
`config/ocr.php`, `OcrBatchContext`, `OcrQuotaService`, `OcrImageIngest`, `ApiIdempotency`,  
`OcrBatchInsights`, `ProcessOcrImageJob` (queue `ocr`), `OcrMaintainCommand`,  
`SystemHealthService` (فحوصات OCR), Filament `OcrKpis`,  
migrations `ocr_usage` + أعمدة hardening، `docs/MOBILE_OCR.md`

**اختبارات:**  
`php artisan test --filter=MobilePosOcrTest`  
`php artisan test --filter=MobileOcrHardeningTest`  
`php artisan test --filter=PosCheckoutIdempotencyTest`  
`php artisan ocr:eval` (golden set في `tests/Fixtures/ocr-golden`)
