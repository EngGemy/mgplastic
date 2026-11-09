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
| مخزون | مخزون بائع نقطة البيع | مخزون **المورّد** في السياق (المصنع: بدون بوابة مخزون) |
| `in_stock=false` عند apply | يخرج إلى `unavailable_lines` | **يبقى في `cart_lines`** مع الأعلام — البنود المطلوبة للتوريد |
| دور السباك | ممنوع (422) | مسموح على `…/plumber/orders/ocr` فقط مع `source=shop` |
| `/current` | دفعات `purpose=pos` فقط | يفلتر بـ `purpose` + `source` + معرف المورّد (مخزن/محل/جملة) |
| بعد apply | `POST …/pos/checkout` + `Idempotency-Key` | `POST …/orders` بنفس شكل `product_id` + `quantity` (قطع) |

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
| جملة | `warehouse` | `warehouse_id` | `warehouse_stock` | مخزون ذلك المخزن (user جملة) |
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
| GET | `/current` | استعلام السياق إلزامي لطلب التوريد |
| POST | `/batches` | جسم السياق أدناه |
| GET | `/batches/{id}` | poll |
| POST | `/batches/{id}/images` | multipart |
| POST | `/batches/{id}/paste` | `{ text }` |
| PATCH | `/batches/{id}/prefs` | **409** لو `step=added` |
| PATCH | `/batches/{id}/lines/{lineId}` | أُضيف `set_unit` |
| POST | `/batches/{id}/approve-ready` | |
| POST | `/batches/{id}/apply` | يجهّز السلة فقط |
| POST | `/batches/{id}/abandon` | |
| POST | `/batches/{id}/reread` | |

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
  "pieces_per_box": 10
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

### 12.4 `message_code` شائع

| code | سياق |
|------|------|
| `ocr_no_pending_batch` | لا دفعة معلّقة |
| `ocr_apply_ok` | apply نجح |
| `ocr_already_applied` | 409 apply مكرّر |
| `ocr_batch_locked` | 409 prefs/lines بعد added |
| `ocr_duplicate_image` | 409 صورة مكررة |
| `idempotency_key_reused` | checkout |

---

## 13) أخطاء شائعة

| HTTP | متى |
|------|-----|
| 403 | دور غير مصرّح على المسار (مثلاً سباك على `trader/…`) |
| 422 | `source`/معرف مورّد ناقص أو غير متوافق مع الدور |
| 409 | صورة مكررة / apply مكرّر / دفعة مقفلة |
| 503 | `GEMINI_API_KEY` غير مفعّل |

---

## 14) تشغيل / صيانة

```bash
php artisan migrate
# أو: php artisan config:cache && php artisan route:cache
```

الجدولة موجودة: `ocr:prune-uploads` يومياً 03:50 (Africa/Tripoli).

---

## 15) ملفات / اختبارات

**كود:**  
`OcrBatchContext`, migration `purpose/source/supplier_user_id`,  
`MobileOcrPresenter`, `OcrBatchService`, `PosOcrController`,  
`NetworkInventoryService` (qty maps), `routes/api/mobile.php`,  
`docs/MOBILE_OCR.md`

**اختبارات:**  
`php artisan test --filter=MobilePosOcrTest`  
`php artisan test --filter=PosCheckoutIdempotencyTest`  
`php artisan test --filter=OcrBatchResilienceTest`  
`php artisan test --filter=InvoiceOcrPosCartTest`
