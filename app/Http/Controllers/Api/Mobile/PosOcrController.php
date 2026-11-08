<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\OcrBatch;
use App\Models\OcrImage;
use App\Models\OcrImageLine;
use App\Models\Product;
use App\Services\Ai\GeminiClient;
use App\Services\Ocr\InvoiceOcrCatalogMatcher;
use App\Services\Ocr\InvoiceOcrLearningService;
use App\Services\Ocr\InvoiceOcrPipeline;
use App\Services\Ocr\MobileOcrPresenter;
use App\Services\Ocr\OcrBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * POS OCR for mobile (distributor + retail trader).
 *
 * Same batch pipeline as Filament admin — upload → poll → review → apply cart lines.
 * Checkout stays on existing POST …/pos/checkout.
 */
class PosOcrController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected OcrBatchService $batches,
        protected MobileOcrPresenter $presenter,
        protected InvoiceOcrCatalogMatcher $matcher,
        protected InvoiceOcrLearningService $learning,
        protected GeminiClient $gemini,
    ) {}

    /** GET …/pos/ocr/current */
    public function current(Request $request): JsonResponse
    {
        $batch = $this->batches->findResumableDraft($request->user());
        if (! $batch) {
            return $this->success([
                'batch' => null,
                'resumable' => false,
                'gemini_enabled' => $this->gemini->enabled(),
            ], 'لا توجد دفعة معلّقة');
        }

        return $this->success([
            'batch' => $this->presenter->present($batch, $request->user()),
            'resumable' => true,
            'gemini_enabled' => $this->gemini->enabled(),
        ], 'دفعة OCR معلّقة');
    }

    /** POST …/pos/ocr/batches */
    public function store(Request $request): JsonResponse
    {
        if (! $this->gemini->enabled()) {
            return $this->error('قراءة الفواتير غير مفعّلة على السيرفر (GEMINI_API_KEY)', 503);
        }

        $v = Validator::make($request->all(), [
            'customer_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'system_default' => ['nullable', 'in:ask,us,eu'],
            'remember_for_customer' => ['nullable', 'boolean'],
        ]);
        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        $customerId = $request->filled('customer_user_id') ? $request->integer('customer_user_id') : null;
        $batch = $this->batches->createOrResume($request->user(), $customerId);

        $system = $request->input('system_default', 'ask');
        $this->presenter->putPrefs($batch, [
            'system_default' => $system === 'ask' ? null : $system,
            'customer_user_id' => $customerId,
            'remember_for_customer' => (bool) $request->boolean('remember_for_customer'),
        ]);

        return $this->created([
            'batch' => $this->presenter->present($batch->fresh(), $request->user()),
        ], 'دفعة OCR جاهزة');
    }

    /** GET …/pos/ocr/batches/{batch} */
    public function show(Request $request, int $batch): JsonResponse
    {
        $owned = $this->ownedBatch($request, $batch);
        if (! $owned) {
            return $this->error('الدفعة غير موجودة', 404);
        }

        return $this->success([
            'batch' => $this->presenter->present($owned, $request->user()),
        ]);
    }

    /** POST …/pos/ocr/batches/{batch}/images  multipart: image|file */
    public function uploadImage(Request $request, int $batch): JsonResponse
    {
        if (! $this->gemini->enabled()) {
            return $this->error('قراءة الفواتير غير مفعّلة على السيرفر (GEMINI_API_KEY)', 503);
        }

        $owned = $this->ownedBatch($request, $batch);
        if (! $owned) {
            return $this->error('الدفعة غير موجودة', 404);
        }

        if (in_array($owned->status, ['added', 'abandoned'], true)) {
            return $this->error('هذه الدفعة مغلقة — أنشئ دفعة جديدة', 422);
        }

        $v = Validator::make($request->all(), [
            'image' => ['required_without:file', 'file', 'image', 'max:12288'],
            'file' => ['required_without:image', 'file', 'image', 'max:12288'],
            'force_duplicate' => ['nullable', 'boolean'],
        ]);
        if ($v->fails()) {
            return $this->error('صورة غير صالحة', 422, $v->errors());
        }

        $file = $request->file('image') ?? $request->file('file');
        $result = $this->batches->addUploadedImage(
            $owned,
            $file,
            (bool) $request->boolean('force_duplicate'),
        );

        if ($result['duplicate'] ?? false) {
            return $this->error('الصورة مرفوعة مسبقاً في نفس الدفعة', 409, [
                'image' => $result['image'] ? $this->presenter->presentImage($result['image']) : null,
                'batch' => $this->presenter->present($owned->fresh(), $request->user()),
            ], 'OCR_DUPLICATE_IMAGE');
        }

        if (! ($result['ok'] ?? false) || ! $result['image']) {
            return $this->error((string) ($result['error'] ?? 'تعذّر رفع الصورة'), 422);
        }

        return $this->created([
            'image' => $this->presenter->presentImage($result['image']),
            'batch' => $this->presenter->present($owned->fresh(), $request->user()),
        ], 'بدأت قراءة الصورة');
    }

    /** POST …/pos/ocr/batches/{batch}/paste  { text } */
    public function pasteText(Request $request, int $batch): JsonResponse
    {
        $owned = $this->ownedBatch($request, $batch);
        if (! $owned) {
            return $this->error('الدفعة غير موجودة', 404);
        }

        $v = Validator::make($request->all(), [
            'text' => ['required', 'string', 'min:1', 'max:20000'],
        ]);
        if ($v->fails()) {
            return $this->error('النص مطلوب', 422, $v->errors());
        }

        $image = $this->batches->addPasteText($owned, trim((string) $request->input('text')));

        return $this->created([
            'image' => $this->presenter->presentImage($image),
            'batch' => $this->presenter->present($owned->fresh(), $request->user()),
        ], 'بدأت تحليل النص');
    }

    /** POST …/pos/ocr/batches/{batch}/images/{image}/retry */
    public function retryImage(Request $request, int $batch, int $image): JsonResponse
    {
        $ownedImage = $this->ownedImage($request, $batch, $image);
        if (! $ownedImage) {
            return $this->error('الصورة غير موجودة', 404);
        }

        $this->batches->retryNow($ownedImage);

        return $this->success([
            'batch' => $this->presenter->present($ownedImage->batch->fresh(), $request->user()),
        ], 'أُعيدت المحاولة');
    }

    /** POST …/pos/ocr/batches/{batch}/images/{image}/cancel */
    public function cancelImage(Request $request, int $batch, int $image): JsonResponse
    {
        $ownedImage = $this->ownedImage($request, $batch, $image);
        if (! $ownedImage) {
            return $this->error('الصورة غير موجودة', 404);
        }

        $this->batches->cancelImage($ownedImage);

        return $this->success([
            'batch' => $this->presenter->present($ownedImage->batch->fresh(), $request->user()),
        ], 'أُلغيت الصورة');
    }

    /** POST …/pos/ocr/batches/{batch}/reread */
    public function reread(Request $request, int $batch): JsonResponse
    {
        $owned = $this->ownedBatch($request, $batch);
        if (! $owned) {
            return $this->error('الدفعة غير موجودة', 404);
        }

        foreach ($owned->images()->whereNull('replaced_by_id')->get() as $image) {
            if ($image->sha256) {
                Cache::forget(InvoiceOcrPipeline::CACHE_PREFIX.$image->sha256);
            }
            $image->attempts = 0;
            $image->last_error_code = null;
            $image->started_at = null;
            $image->finished_at = null;
            $image->status = 'queued';
            $image->save();
            $this->batches->dispatchIfCapacity($image->fresh());
        }

        $owned->status = 'reading';
        $owned->save();

        return $this->success([
            'batch' => $this->presenter->present($owned->fresh(), $request->user()),
        ], 'بدأت إعادة القراءة');
    }

    /** POST …/pos/ocr/batches/{batch}/abandon */
    public function abandon(Request $request, int $batch): JsonResponse
    {
        $owned = $this->ownedBatch($request, $batch);
        if (! $owned) {
            return $this->error('الدفعة غير موجودة', 404);
        }

        $owned->status = 'abandoned';
        $owned->save();
        $this->presenter->clearPrefs($owned->id);

        return $this->success([
            'batch_id' => $owned->id,
            'status' => 'abandoned',
        ], 'تم تجاهل الدفعة');
    }

    /** PATCH …/pos/ocr/batches/{batch}/prefs */
    public function updatePrefs(Request $request, int $batch): JsonResponse
    {
        $owned = $this->ownedBatch($request, $batch);
        if (! $owned) {
            return $this->error('الدفعة غير موجودة', 404);
        }

        $v = Validator::make($request->all(), [
            'system_default' => ['sometimes', 'nullable', 'in:ask,us,eu'],
            'customer_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'remember_for_customer' => ['sometimes', 'boolean'],
            'reenrich' => ['sometimes', 'boolean'],
        ]);
        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        $prefs = $this->presenter->putPrefs($owned, [
            'system_default' => $request->has('system_default')
                ? ($request->input('system_default') === 'ask' ? null : $request->input('system_default'))
                : ($this->presenter->prefs($owned)['system_default'] ?? null),
            'customer_user_id' => $request->has('customer_user_id')
                ? $request->input('customer_user_id')
                : ($this->presenter->prefs($owned)['customer_user_id'] ?? null),
            'remember_for_customer' => $request->has('remember_for_customer')
                ? $request->boolean('remember_for_customer')
                : ($this->presenter->prefs($owned)['remember_for_customer'] ?? false),
        ]);

        if ($request->boolean('reenrich', true) && $request->has('system_default')) {
            $this->reenrichBatchDraft($owned, $prefs['system_default'], $prefs['customer_user_id']);
        }

        return $this->success([
            'batch' => $this->presenter->present($owned->fresh(), $request->user()),
        ], 'تم تحديث الإعدادات');
    }

    /**
     * PATCH …/pos/ocr/batches/{batch}/lines/{line}
     *
     * Body: action + optional fields
     * actions: approve | unapprove | select_product | set_qty | choose_system | choose_family | confirm_understanding
     */
    public function updateLine(Request $request, int $batch, int $line): JsonResponse
    {
        $owned = $this->ownedBatch($request, $batch);
        if (! $owned) {
            return $this->error('الدفعة غير موجودة', 404);
        }

        $row = $this->ownedLine($owned, $line);
        if (! $row) {
            return $this->error('البند غير موجود', 404);
        }

        $v = Validator::make($request->all(), [
            'action' => ['required', 'in:approve,unapprove,select_product,set_qty,choose_system,choose_family,confirm_understanding'],
            'product_id' => ['required_if:action,select_product', 'nullable', 'integer', 'exists:products,id'],
            'qty' => ['required_if:action,set_qty', 'nullable', 'numeric', 'min:1'],
            'system' => ['required_if:action,choose_system', 'nullable', 'in:us,eu'],
            'lexicon_family' => ['required_if:action,choose_family', 'nullable', 'string', 'max:64'],
        ]);
        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        $action = (string) $request->input('action');
        $prefs = $this->presenter->prefs($owned);
        $payload = (array) ($row->payload ?? []);

        switch ($action) {
            case 'select_product':
            case 'approve':
                $productId = $request->integer('product_id') ?: (int) ($row->resolved_product_id ?? 0);
                if ($productId < 1) {
                    return $this->error('اختَر منتجاً أولاً', 422);
                }
                $product = Product::query()->with('translations')->find($productId);
                if (! $product) {
                    return $this->error('المنتج غير موجود', 404);
                }
                $payload = $this->applyProductToPayload($payload, $product);
                $row->resolved_product_id = $productId;
                if ($action === 'approve') {
                    $row->review_status = 'approved';
                    $payload['approved'] = true;
                    $payload['review_status'] = 'approved';
                } else {
                    $payload['approved'] = false;
                    if ($row->review_status === 'approved') {
                        $row->review_status = 'ready';
                        $payload['review_status'] = 'ready';
                    }
                }
                break;

            case 'unapprove':
                $row->review_status = 'ready';
                $payload['approved'] = false;
                $payload['review_status'] = 'ready';
                break;

            case 'set_qty':
                $qty = (float) $request->input('qty');
                $row->qty = $qty;
                $payload['qty'] = $qty;
                break;

            case 'choose_system':
                $system = (string) $request->input('system');
                $candidates = (array) ($payload['candidates'] ?? []);
                $picked = null;
                foreach ($candidates as $c) {
                    if (($c['system'] ?? null) === $system && ! empty($c['product_id'])) {
                        $picked = (int) $c['product_id'];
                        break;
                    }
                }
                if (! $picked) {
                    return $this->error('لا يوجد مرشّح لهذا النظام', 422);
                }
                $product = Product::query()->with('translations')->find($picked);
                if (! $product) {
                    return $this->error('المنتج غير موجود', 404);
                }
                $payload = $this->applyProductToPayload($payload, $product);
                $payload['slots'] = array_merge((array) ($payload['slots'] ?? []), [
                    'system' => $system,
                    'system_source' => 'text',
                ]);
                $payload['match_reason'] = 'من الكتابة';
                $row->resolved_product_id = $picked;
                break;

            case 'choose_family':
            case 'confirm_understanding':
                $lineForEnrich = array_merge($payload, [
                    'raw_text' => $row->raw_fragment,
                    'qty' => $row->qty ?? ($payload['qty'] ?? 1),
                    'understanding_confirmed' => true,
                ]);
                if ($action === 'choose_family') {
                    $lineForEnrich['force_lexicon_family'] = (string) $request->input('lexicon_family');
                } elseif (! empty($payload['slots']['lexicon_family'])) {
                    $lineForEnrich['force_lexicon_family'] = (string) $payload['slots']['lexicon_family'];
                }
                $enriched = $this->matcher->enrich(
                    ['lines' => [$lineForEnrich]],
                    $prefs['system_default'],
                    $prefs['customer_user_id'],
                );
                $payload = array_merge($enriched['lines'][0] ?? $lineForEnrich, [
                    'understanding_confirmed' => true,
                    'force_lexicon_family' => $lineForEnrich['force_lexicon_family'] ?? null,
                    'ocr_line_id' => $row->id,
                    'ocr_image_id' => $row->image_id,
                ]);
                if (! empty($payload['matched_product_id'])) {
                    $row->resolved_product_id = (int) $payload['matched_product_id'];
                }
                break;
        }

        $row->payload = $payload;
        $row->save();

        return $this->success([
            'line' => $this->presentLine($row->fresh()),
            'batch' => $this->presenter->present($owned->fresh(), $request->user()),
        ], 'تم تحديث البند');
    }

    /** POST …/pos/ocr/batches/{batch}/approve-ready */
    public function approveReady(Request $request, int $batch): JsonResponse
    {
        $owned = $this->ownedBatch($request, $batch);
        if (! $owned) {
            return $this->error('الدفعة غير موجودة', 404);
        }

        $built = $this->batches->buildPosDraft($owned);
        $count = 0;
        foreach ((array) ($built['draft']['lines'] ?? []) as $line) {
            $decision = $line['match_decision'] ?? '';
            $flags = (array) ($line['needs_review'] ?? []);
            $lineId = (int) ($line['ocr_line_id'] ?? 0);
            if ($lineId < 1 || empty($line['matched_product_id'])) {
                continue;
            }
            if (! in_array($decision, ['auto', 'alias'], true) || $flags !== []) {
                continue;
            }
            if (! empty($line['approved']) || ($line['review_status'] ?? '') === 'approved') {
                continue;
            }
            $row = OcrImageLine::query()->find($lineId);
            if (! $row) {
                continue;
            }
            $row->review_status = 'approved';
            $row->resolved_product_id = (int) $line['matched_product_id'];
            $row->qty = $line['qty'] ?? $row->qty;
            $row->payload = array_merge((array) ($row->payload ?? []), (array) $line, [
                'approved' => true,
                'review_status' => 'approved',
            ]);
            $row->save();
            $count++;
        }

        return $this->success([
            'approved_count' => $count,
            'batch' => $this->presenter->present($owned->fresh(), $request->user()),
        ], 'تم اعتماد البنود الجاهزة');
    }

    /**
     * POST …/pos/ocr/batches/{batch}/apply
     *
     * Returns cart-ready lines for the app to merge into local POS cart.
     * Does NOT create an invoice — use existing pos/checkout after review.
     */
    public function apply(Request $request, int $batch): JsonResponse
    {
        $owned = $this->ownedBatch($request, $batch);
        if (! $owned) {
            return $this->error('الدفعة غير موجودة', 404);
        }

        if ($owned->cart_added_at && $owned->idempotency_token) {
            $presented = $this->presenter->present($owned, $request->user());

            return $this->error('تم تطبيق هذه الدفعة مسبقاً — لن تُكرَّر البنود', 409, [
                'batch' => $presented,
                'cart_lines' => $presented['cart_lines'] ?? [],
                'idempotency_token' => $owned->idempotency_token,
            ], 'OCR_ALREADY_APPLIED');
        }

        $prefs = $this->presenter->prefs($owned);
        $built = $this->batches->buildPosDraft($owned);
        $draftLines = (array) ($built['draft']['lines'] ?? []);
        $errors = [];
        $approvedCount = 0;
        $addedLineIds = [];
        $approvedForCart = [];
        $sha = $owned->images()->whereNull('replaced_by_id')->orderByDesc('id')->value('sha256');

        foreach ($draftLines as $line) {
            $approved = ! empty($line['approved']) || ($line['review_status'] ?? '') === 'approved';
            if (! $approved || ! empty($line['added']) || ($line['review_status'] ?? '') === 'added') {
                continue;
            }

            $productId = (int) ($line['matched_product_id'] ?? 0);
            $qty = (int) round((float) ($line['qty'] ?? 0));
            if ($productId < 1 || $qty < 1) {
                $errors[] = 'بند بدون منتج/كمية: '.($line['raw_text'] ?? '');

                continue;
            }

            $product = Product::query()->with('translations')->find($productId);
            if (! $product) {
                $errors[] = 'منتج غير موجود #'.$productId;

                continue;
            }

            $approvedCount++;
            $approvedForCart[] = $line;
            if (! empty($line['ocr_line_id'])) {
                $addedLineIds[] = (int) $line['ocr_line_id'];
            }

            if ($sha) {
                $this->learning->rememberCorrection(
                    (string) $sha,
                    (string) ($line['raw_text'] ?? $line['name'] ?? ''),
                    $productId,
                    (float) $qty,
                    $request->user(),
                    (string) ($line['slots']['system'] ?? $product->system ?? ''),
                    $prefs['customer_user_id'],
                    (bool) $prefs['remember_for_customer'],
                );
            }
        }

        $stock = $this->presenter->stockQtyByProduct($request->user());
        $cartLines = $this->presenter->buildCartLinesFromApproved($approvedForCart, $stock);

        if ($cartLines === []) {
            return $this->error(
                'لا بنود معتمدة — اعتمد البنود ثم طبّق على السلة',
                422,
                ['errors' => $errors, 'batch' => $this->presenter->present($owned, $request->user())],
            );
        }

        $token = $owned->ensureIdempotencyToken();
        if ($addedLineIds !== []) {
            $this->batches->markLinesAdded($owned, $addedLineIds);
        } else {
            $owned->status = 'added';
            $owned->cart_added_at = now();
            $owned->idempotency_token = $token;
            $owned->save();
        }

        return $this->success([
            'cart_lines' => $cartLines,
            'approved_count' => $approvedCount,
            'idempotency_token' => $token,
            'batch' => $this->presenter->present($owned->fresh(), $request->user()),
            'next' => [
                'merge_into_local_cart' => true,
                'checkout' => 'POST …/pos/checkout بنفس شكل lines (product_id + boxes|quantity)',
                'send_idempotency_token_on_checkout' => false,
            ],
        ], 'تم تجهيز '.$approvedCount.' منتج للسلة');
    }

    protected function ownedBatch(Request $request, int $batchId): ?OcrBatch
    {
        return OcrBatch::query()
            ->where('user_id', $request->user()->id)
            ->find($batchId);
    }

    protected function ownedImage(Request $request, int $batchId, int $imageId): ?OcrImage
    {
        $batch = $this->ownedBatch($request, $batchId);
        if (! $batch) {
            return null;
        }

        return OcrImage::query()->where('batch_id', $batch->id)->find($imageId);
    }

    protected function ownedLine(OcrBatch $batch, int $lineId): ?OcrImageLine
    {
        return OcrImageLine::query()
            ->where('id', $lineId)
            ->whereHas('image', fn ($q) => $q->where('batch_id', $batch->id))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function applyProductToPayload(array $payload, Product $product): array
    {
        $payload['matched_product_id'] = (int) $product->id;
        $payload['match_decision'] = 'manual';
        $payload['match_status'] = 'matched';
        $payload['name'] = localized_name($product, 'name', 'منتج #'.$product->id);
        $payload['pieces_per_box'] = $product->pieces_per_box;
        $payload['points_per_unit'] = $product->points_per_unit;
        $payload['main_image'] = $product->main_image;
        $payload['needs_review'] = array_values(array_filter(
            (array) ($payload['needs_review'] ?? []),
            fn ($f) => ! in_array($f, ['low_confidence', 'needs_choice', 'catalog_unmatched', 'mg_family_unknown_variant'], true)
        ));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentLine(OcrImageLine $row): array
    {
        $payload = (array) ($row->payload ?? []);
        $payload['ocr_line_id'] = $row->id;
        $payload['ocr_image_id'] = $row->image_id;
        $payload['raw_text'] = $row->raw_fragment;
        $payload['qty'] = $row->qty ?? ($payload['qty'] ?? 1);
        $payload['matched_product_id'] = $row->resolved_product_id ?? ($payload['matched_product_id'] ?? null);
        $payload['review_status'] = $row->review_status;
        $payload['approved'] = in_array($row->review_status, ['approved', 'added'], true);

        return $payload;
    }

    protected function reenrichBatchDraft(OcrBatch $batch, ?string $systemDefault, ?int $customerUserId): void
    {
        $built = $this->batches->buildPosDraft($batch);
        if (! is_array($built['draft'] ?? null)) {
            return;
        }

        $restored = (array) ($built['draft']['lines'] ?? []);
        foreach ((array) ($built['draft']['excluded'] ?? []) as $ex) {
            if (is_array($ex)) {
                $restored[] = [
                    'raw_text' => $ex['raw_text'] ?? '',
                    'name' => $ex['name'] ?? ($ex['raw_text'] ?? ''),
                    'qty' => $ex['qty'] ?? 1,
                    'brand' => null,
                    'ocr_line_id' => $ex['ocr_line_id'] ?? null,
                    'ocr_image_id' => $ex['ocr_image_id'] ?? null,
                ];
            }
        }

        $enriched = $this->matcher->enrich(
            ['lines' => $restored, 'excluded' => []],
            $systemDefault,
            $customerUserId,
        );

        foreach ((array) ($enriched['lines'] ?? []) as $line) {
            $lineId = (int) ($line['ocr_line_id'] ?? 0);
            if ($lineId < 1) {
                continue;
            }
            OcrImageLine::query()->where('id', $lineId)->update([
                'resolved_product_id' => $line['matched_product_id'] ?? null,
                'qty' => $line['qty'] ?? null,
                'review_status' => ! empty($line['needs_review']) ? 'needs' : 'ready',
                'payload' => $line,
            ]);
        }
        foreach ((array) ($enriched['excluded'] ?? []) as $ex) {
            $lineId = (int) ($ex['ocr_line_id'] ?? 0);
            if ($lineId < 1) {
                continue;
            }
            OcrImageLine::query()->where('id', $lineId)->update([
                'resolved_product_id' => null,
                'review_status' => 'excluded',
                'payload' => $ex,
            ]);
        }
    }
}
