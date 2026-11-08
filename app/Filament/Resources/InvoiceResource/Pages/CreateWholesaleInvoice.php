<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Concerns\SetsCartQuantity;
use App\Filament\Resources\InvoiceResource;
use App\Models\OcrBatch;
use App\Models\OcrImage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Ai\GeminiClient;
use App\Services\CustomerWhatsAppNotifier;
use App\Services\InvoiceCartSheetService;
use App\Services\InvoiceNumberService;
use App\Services\Ocr\InvoiceOcrCatalogMatcher;
use App\Services\Ocr\InvoiceOcrLearningService;
use App\Services\Ocr\OcrBatchService;
use App\Services\WholesaleInvoiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class CreateWholesaleInvoice extends Page
{
    use SetsCartQuantity {
        setQuantity as applyCartQuantity;
    }
    use WithFileUploads;

    protected static string $resource = InvoiceResource::class;

    protected static string $view = 'filament.resources.invoice-resource.pages.create-wholesale-invoice';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'فاتورة وارد — مصنع → جملة';

    protected static ?string $title = 'إصدار فاتورة وارد — من المصنع لموزع الجملة';

    protected static ?int $navigationSort = 5;

    protected static bool $shouldRegisterNavigation = false;

    public ?int $wholesaleDistributorId = null;

    public ?int $selectedCategoryId = null;

    public string $search = '';

    /** @var array<string, array{product_id:int, name:string, quantity:int, points_per_unit:float, image:?string}> */
    public array $cart = [];

    public bool $reviewing = false;

    public $invoiceSheet = null;

    /** Photo of a paper invoice — OCR draft for review (not auto-saved to cart). */
    public $invoiceOcrImage = null;

    /** @var array<int, TemporaryUploadedFile>|TemporaryUploadedFile|null */
    public $invoiceOcrImages = null;

    public bool $ocrReading = false;

    public string $ocrStep = 'idle';

    public ?string $ocrJobKey = null;

    public ?string $ocrPreviewUrl = null;

    public ?string $ocrImageSha = null;

    public ?string $ocrError = null;

    /** @var array<string, mixed>|null */
    public ?array $ocrDraft = null;

    public ?int $ocrBatchId = null;

    /** @var list<array<string, mixed>> */
    public array $ocrImageStrip = [];

    /** @var list<array<string, mixed>> */
    public array $ocrMergeSuggestions = [];

    public bool $ocrResumeBanner = false;

    public bool $ocrAddingToCart = false;

    public string $ocrPasteText = '';

    public int $ocrPollTicks = 0;

    /** ask|us|eu — invoice-level default for ambiguous sizes */
    public string $ocrSystemDefault = 'ask';

    public int $ocrFocusLine = 0;

    public bool $ocrRememberForCustomer = false;

    public static function getNavigationGroup(): ?string
    {
        return 'التشغيل اليومي';
    }

    public static function canAccess(array $parameters = []): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    public function mount(): void
    {
        if (auth()->user()?->isWholesaleDistributor()) {
            $this->wholesaleDistributorId = auth()->id();
        }

        $user = auth()->user();
        if ($user) {
            $batch = app(OcrBatchService::class)->findResumableDraft($user);
            if ($batch) {
                $this->ocrBatchId = $batch->id;
                $this->ocrResumeBanner = true;
                $this->syncOcrBatchUi($batch);
            }
        }
    }

    public function getNextInvoiceNumberProperty(): string
    {
        return app(InvoiceNumberService::class)->previewNext('wholesale_pos');
    }

    public function getCategoriesProperty(): Collection
    {
        return ProductCategory::query()
            ->with(['translations', 'children.translations'])
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get();
    }

    public function getProductsProperty(): Collection
    {
        return Product::query()
            ->with(['translations', 'category.translations'])
            ->when($this->selectedCategoryId, function ($q) {
                $q->where(function ($qq) {
                    $qq->where('product_category_id', $this->selectedCategoryId)
                        ->orWhereIn('product_category_id', function ($sub) {
                            $sub->from('product_categories')
                                ->select('id')
                                ->where('parent_id', $this->selectedCategoryId);
                        });
                });
            })
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->whereHas('translations', fn ($t) => $t->where('locale', 'ar')->where('name', 'like', $term));
            })
            ->orderBy('id')
            ->limit(60)
            ->get()
            ->map(function (Product $p) {
                $p->display_name = localized_name($p, 'name', "منتج #{$p->id}");

                return $p;
            });
    }

    public function getWholesalersProperty(): Collection
    {
        return User::where('role', 'wholesale_distributor')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function selectCategory(?int $categoryId): void
    {
        $this->selectedCategoryId = $categoryId;
    }

    public function addProduct(int $productId): void
    {
        $product = Product::with('translations')->findOrFail($productId);
        $key = (string) $productId;
        $name = localized_name($product, 'name', "منتج #{$productId}");

        if (isset($this->cart[$key])) {
            if ($this->isBoxed($key)) {
                $this->setBoxes($key, (int) $this->cart[$key]['boxes'] + 1);
            } else {
                $this->cart[$key]['quantity']++;
            }
        } else {
            $piecesPerBox = (int) ($product->pieces_per_box ?? 0);
            $boxed = $piecesPerBox > 0;

            $this->cart[$key] = [
                'product_id' => $productId,
                'name' => $name,
                'quantity' => $boxed ? $piecesPerBox : 1,
                'boxes' => $boxed ? 1 : null,
                'pieces_per_box' => $boxed ? $piecesPerBox : null,
                'points_per_unit' => (float) ($product->points_per_unit ?? 0),
                'image' => $product->main_image,
            ];
        }
    }

    public function isBoxed(string $key): bool
    {
        return (int) ($this->cart[$key]['pieces_per_box'] ?? 0) > 0;
    }

    public function setBoxes(string $key, mixed $value): void
    {
        if (! isset($this->cart[$key]) || ! $this->isBoxed($key)) {
            return;
        }

        $boxes = (int) $value;
        if ($boxes < 1) {
            unset($this->cart[$key]);
            if ($this->cart === []) {
                $this->reviewing = false;
            }

            return;
        }

        $pieces = (int) $this->cart[$key]['pieces_per_box'];
        $this->cart[$key]['boxes'] = $boxes;
        $this->cart[$key]['quantity'] = $boxes * $pieces;
    }

    public function setQuantity(string $key, mixed $value): void
    {
        $this->applyCartQuantity($key, $value);

        if ($this->cart === []) {
            $this->reviewing = false;
        }
    }

    public function incrementQty(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        if ($this->isBoxed($key)) {
            $this->setBoxes($key, (int) $this->cart[$key]['boxes'] + 1);

            return;
        }

        $this->setQuantity($key, (int) $this->cart[$key]['quantity'] + 1);
    }

    public function decrementQty(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        if ($this->isBoxed($key)) {
            $this->setBoxes($key, (int) $this->cart[$key]['boxes'] - 1);

            return;
        }

        $this->setQuantity($key, (int) $this->cart[$key]['quantity'] - 1);
    }

    public function removeLine(string $key): void
    {
        unset($this->cart[$key]);

        if ($this->cart === []) {
            $this->reviewing = false;
        }
    }

    public function getTotalPointsProperty(): int
    {
        return (int) collect($this->cart)->sum(
            fn ($line) => (int) floor($line['quantity'] * $line['points_per_unit'])
        );
    }

    public function getTotalUnitsProperty(): int
    {
        return (int) collect($this->cart)->sum(fn ($line) => (int) $line['quantity']);
    }

    public function getSelectedWholesalerNameProperty(): string
    {
        if (! $this->wholesaleDistributorId) {
            return '';
        }

        return (string) (User::query()->whereKey($this->wholesaleDistributorId)->value('name') ?? '');
    }

    /** @return array<int, string> */
    public function getReviewIssuesProperty(): array
    {
        $issues = [];

        if (! $this->wholesaleDistributorId) {
            $issues[] = 'اختر موزع الجملة المستلم';
        }

        if ($this->cart === []) {
            $issues[] = 'الفاتورة لا تحتوي على منتجات';
        }

        foreach ($this->cart as $line) {
            $name = (string) ($line['name'] ?? 'منتج');

            if ((int) ($line['quantity'] ?? 0) < 1) {
                $issues[] = "كمية «{$name}» يجب أن تكون 1 على الأقل";
            }

            if ((float) ($line['points_per_unit'] ?? 0) <= 0) {
                $issues[] = "«{$name}» بدون نقاط للوحدة — احذفه أو راجع نقاط المنتج";
            }
        }

        return $issues;
    }

    public function downloadInvoiceTemplate()
    {
        $path = app(InvoiceCartSheetService::class)->templatePath();

        return response()->download($path, 'invoice-lines-template.xlsx')->deleteFileAfterSend(true);
    }

    public function updatedInvoiceSheet(): void
    {
        if (! $this->invoiceSheet instanceof TemporaryUploadedFile) {
            return;
        }

        $this->validate([
            'invoiceSheet' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $result = app(InvoiceCartSheetService::class)->import($this->invoiceSheet->getRealPath());
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title('تعذّر قراءة الشيت')->body($e->getMessage())->send();
            $this->invoiceSheet = null;

            return;
        }

        $this->invoiceSheet = null;
        $this->applyImportedCartLines($result['lines'], $result['errors'], 'الشيت');
    }

    public function updatedInvoiceOcrImage(): void
    {
        if (! $this->invoiceOcrImage instanceof TemporaryUploadedFile) {
            return;
        }

        $this->ingestOcrUpload($this->invoiceOcrImage);
        $this->invoiceOcrImage = null;
    }

    public function updatedInvoiceOcrImages(): void
    {
        $files = $this->invoiceOcrImages;
        if ($files instanceof TemporaryUploadedFile) {
            $files = [$files];
        }
        if (! is_array($files) || $files === []) {
            return;
        }

        foreach ($files as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $this->ingestOcrUpload($file);
            }
        }
        $this->invoiceOcrImages = null;
    }

    protected function ingestOcrUpload(TemporaryUploadedFile $file): void
    {
        if (! app(GeminiClient::class)->enabled()) {
            Notification::make()
                ->danger()
                ->title('Gemini غير مفعّل')
                ->body('أضف GEMINI_API_KEY في إعدادات السيرفر ثم أعد تحميل الإعدادات.')
                ->send();

            return;
        }

        $user = auth()->user();
        if (! $user) {
            return;
        }

        $batches = app(OcrBatchService::class);
        $batch = $this->ocrBatchId
            ? OcrBatch::query()->where('user_id', $user->id)->find($this->ocrBatchId)
            : null;
        $batch ??= $batches->createOrResume(
            $user,
            $this->wholesaleDistributorId ? (int) $this->wholesaleDistributorId : null,
        );
        $this->ocrBatchId = $batch->id;
        $this->ocrResumeBanner = false;
        $this->ocrError = null;

        $result = $batches->addUploadedImage($batch, $file);
        if ($result['duplicate'] ?? false) {
            Notification::make()->warning()->title('الصورة مرفوعة مسبقاً')->body('already uploaded')->send();
            $this->syncOcrBatchUi($batch->fresh());

            return;
        }
        if (! ($result['ok'] ?? false)) {
            Notification::make()->danger()->title('تعذّر رفع الصورة')->body((string) ($result['error'] ?? ''))->send();

            return;
        }

        /** @var OcrImage $image */
        $image = $result['image'];
        $this->ocrPreviewUrl = Storage::disk('public')->url($image->path);
        $this->ocrImageSha = $image->sha256;
        $this->ocrReading = true;
        $this->ocrStep = 'polling';
        $this->ocrPollTicks = 0;
        $this->syncOcrBatchUi($batch->fresh());

        if (config('queue.default') === 'sync') {
            Notification::make()->info()->title('تمت قراءة الصورة')->body('راجع المسودة قبل الإضافة للسلة.')->send();

            return;
        }

        Notification::make()->info()->title('بدأت قراءة الصورة')->body('يمكنك مراجعة البنود الجاهزة قبل انتهاء كل الصور.')->send();
    }

    public function pollOcrJob(): void
    {
        if ($this->ocrBatchId) {
            $this->pollOcrBatch();

            return;
        }

        if (! $this->ocrJobKey) {
            return;
        }

        $payload = Cache::get('ocr:pos:job:'.$this->ocrJobKey);
        if (! is_array($payload)) {
            return;
        }

        $this->ocrStep = (string) ($payload['step'] ?? 'processing');
        if (($payload['status'] ?? '') === 'ready' && is_array($payload['result'] ?? null)) {
            $this->applyOcrPipelineResult($payload['result']);
            $this->ocrJobKey = null;
        } elseif (($payload['status'] ?? '') === 'failed') {
            $this->ocrReading = false;
            $this->ocrStep = 'failed';
            $this->ocrError = (string) ($payload['message'] ?? 'فشلت القراءة');
            $this->ocrJobKey = null;
            Notification::make()->danger()->title('تعذّرت قراءة الصورة')->body($this->ocrError)->send();
        }
    }

    public function pollOcrBatch(): void
    {
        if (! $this->ocrBatchId) {
            return;
        }

        $batch = OcrBatch::query()->with('images')->find($this->ocrBatchId);
        if (! $batch) {
            return;
        }

        $this->ocrPollTicks++;
        $this->syncOcrBatchUi($batch);

        if ($batch->isSettled()) {
            $this->ocrReading = false;
            if ($this->ocrDraft) {
                $this->ocrStep = 'review';
            }
        } elseif ($this->ocrPollTicks * 2 >= 90) {
            $this->ocrStep = 'still_processing';
        }
    }

    protected function syncOcrBatchUi(OcrBatch $batch): void
    {
        $batch->load(['images' => fn ($q) => $q->whereNull('replaced_by_id')->orderBy('position')]);
        $this->ocrImageStrip = $batch->images->map(function (OcrImage $img) {
            $countdown = null;
            if ($img->status === 'retrying' && $img->next_retry_at) {
                $countdown = $img->next_retry_at->isFuture()
                    ? (int) now()->diffInSeconds($img->next_retry_at)
                    : 0;
            }

            return [
                'id' => $img->id,
                'position' => $img->position,
                'status' => $img->status,
                'lines_count' => $img->lines_count,
                'error' => $img->last_error_code,
                'preview' => Storage::disk('public')->url($img->path),
                'countdown' => $countdown,
                'quality_warning' => (bool) $img->quality_warning,
                'attempts' => $img->attempts,
            ];
        })->values()->all();

        $built = app(OcrBatchService::class)->buildPosDraft($batch);
        $this->ocrMergeSuggestions = $built['merge_suggestions'];
        if (is_array($built['draft'] ?? null)) {
            $draft = $built['draft'];
            $draft['system_default'] = in_array($this->ocrSystemDefault, ['us', 'eu'], true) ? $this->ocrSystemDefault : null;
            $draft['customer_user_id'] = $this->wholesaleDistributorId ? (int) $this->wholesaleDistributorId : null;
            $this->ocrDraft = $draft;
            if ($batch->isSettled()) {
                $this->ocrStep = 'review';
            } elseif ($this->ocrStep !== 'still_processing') {
                $this->ocrStep = 'polling';
            }
        }

        $pending = $batch->images->contains(fn (OcrImage $i) => in_array($i->status, ['queued', 'reading', 'retrying'], true));
        $this->ocrReading = $pending;
        if ($pending && $this->ocrStep !== 'still_processing') {
            $this->ocrStep = 'polling';
        }
    }

    public function retryOcrImage(int $imageId): void
    {
        $image = $this->ownedOcrImage($imageId);
        if (! $image) {
            return;
        }
        app(OcrBatchService::class)->retryNow($image);
        $this->ocrReading = true;
        $this->ocrStep = 'polling';
        $this->syncOcrBatchUi($image->batch->fresh());
    }

    /** Bust OCR draft cache and re-queue every active image in the current batch. */
    public function forceRereadOcr(): void
    {
        $batch = $this->ownedOcrBatch();
        if (! $batch) {
            Notification::make()->warning()->title('لا توجد دفعة لإعادة قراءتها')->send();

            return;
        }

        $svc = app(OcrBatchService::class);
        foreach ($batch->images()->whereNull('replaced_by_id')->get() as $image) {
            if ($image->sha256) {
                Cache::forget(\App\Services\Ocr\InvoiceOcrPipeline::CACHE_PREFIX.$image->sha256);
            }
            $image->attempts = 0;
            $image->last_error_code = null;
            $image->started_at = null;
            $image->finished_at = null;
            $image->status = 'queued';
            $image->save();
            $svc->dispatchIfCapacity($image->fresh());
        }

        $this->ocrDraft = null;
        $this->ocrReading = true;
        $this->ocrStep = 'polling';
        $this->ocrPollTicks = 0;
        $this->syncOcrBatchUi($batch->fresh());
        Notification::make()->info()->title('بدأت إعادة القراءة')->body('تم تجاهل النتيجة القديمة المخزّنة.')->send();
    }

    public function cancelOcrImage(int $imageId): void
    {
        $image = $this->ownedOcrImage($imageId);
        if (! $image) {
            return;
        }
        app(OcrBatchService::class)->cancelImage($image);
        $this->syncOcrBatchUi($image->batch->fresh());
    }

    public function removeOcrImage(int $imageId): void
    {
        $this->cancelOcrImage($imageId);
    }

    public function submitOcrPasteText(): void
    {
        $text = trim($this->ocrPasteText);
        if ($text === '') {
            return;
        }
        $user = auth()->user();
        if (! $user) {
            return;
        }
        $batches = app(OcrBatchService::class);
        $batch = $this->ownedOcrBatch() ?? $batches->createOrResume(
            $user,
            $this->wholesaleDistributorId ? (int) $this->wholesaleDistributorId : null,
        );
        $this->ocrBatchId = $batch->id;
        $batches->addPasteText($batch, $text);
        $this->ocrPasteText = '';
        $this->ocrReading = true;
        $this->ocrStep = 'polling';
        $this->syncOcrBatchUi($batch->fresh());
    }

    public function dismissOcrResumeBanner(): void
    {
        $this->ocrResumeBanner = false;
    }

    public function abandonOcrBatch(): void
    {
        $batch = $this->ownedOcrBatch();
        if ($batch) {
            $batch->status = 'abandoned';
            $batch->save();
        }
        $this->dismissOcrDraft();
    }

    protected function ownedOcrBatch(): ?OcrBatch
    {
        if (! $this->ocrBatchId || ! auth()->id()) {
            return null;
        }

        return OcrBatch::query()->where('user_id', auth()->id())->find($this->ocrBatchId);
    }

    protected function ownedOcrImage(int $imageId): ?OcrImage
    {
        $batch = $this->ownedOcrBatch();
        if (! $batch) {
            return null;
        }

        return OcrImage::query()->where('batch_id', $batch->id)->find($imageId);
    }

    /** @param  array<string, mixed>  $result */
    protected function applyOcrPipelineResult(array $result): void
    {
        $this->ocrReading = false;
        $this->ocrImageSha = $result['image_sha256'] ?? null;

        if (! ($result['ok'] ?? false) || ! is_array($result['draft'] ?? null)) {
            $this->ocrStep = 'failed';
            $this->ocrError = (string) ($result['error'] ?? 'تعذّرت القراءة');
            Notification::make()->danger()->title('تعذّرت قراءة صورة الفاتورة')->body($this->ocrError)->send();

            return;
        }

        $draft = $result['draft'];
        $draft['system_default'] = in_array($this->ocrSystemDefault, ['us', 'eu'], true) ? $this->ocrSystemDefault : null;
        $draft['customer_user_id'] = $this->wholesaleDistributorId ? (int) $this->wholesaleDistributorId : null;
        $this->ocrDraft = $draft;
        $this->ocrFocusLine = 0;
        $this->ocrStep = 'review';
        $this->ocrError = null;
        $cached = ! empty($result['cached']) ? ' (من الذاكرة المؤقتة)' : '';
        Notification::make()
            ->success()
            ->title('مسودة جاهزة للمراجعة'.$cached)
            ->body('راجع البنود ثم اضغط اعتماد — لن تُحفظ في السلة تلقائياً.')
            ->send();
    }

    public function setOcrSystemDefault(string $value): void
    {
        if (! in_array($value, ['ask', 'us', 'eu'], true)) {
            return;
        }
        $this->ocrSystemDefault = $value;
        if (! is_array($this->ocrDraft)) {
            return;
        }

        // Re-run matcher only (no Gemini) with new default.
        $draft = $this->ocrDraft;
        $draft['system_default'] = $value === 'ask' ? null : $value;
        $draft['customer_user_id'] = $this->wholesaleDistributorId ? (int) $this->wholesaleDistributorId : null;
        // Restore excluded back into lines for re-classification when default changes.
        $restored = (array) ($draft['lines'] ?? []);
        foreach ((array) ($draft['excluded'] ?? []) as $ex) {
            if (is_array($ex)) {
                $restored[] = [
                    'raw_text' => $ex['raw_text'] ?? '',
                    'name' => $ex['name'] ?? ($ex['raw_text'] ?? ''),
                    'qty' => $ex['qty'] ?? 1,
                    'brand' => null,
                ];
            }
        }
        $draft['lines'] = $restored;
        $draft['excluded'] = [];
        $this->ocrDraft = app(InvoiceOcrCatalogMatcher::class)->enrich(
            $draft,
            $value === 'ask' ? null : $value,
            $this->wholesaleDistributorId ? (int) $this->wholesaleDistributorId : null,
        );
    }

    public function selectOcrCandidate(int $lineIndex, int $productId): void
    {
        if (! is_array($this->ocrDraft) || ! isset($this->ocrDraft['lines'][$lineIndex])) {
            return;
        }

        $product = Product::query()->with('translations')->find($productId);
        if (! $product) {
            return;
        }

        $this->ocrDraft['lines'][$lineIndex]['matched_product_id'] = $productId;
        $this->ocrDraft['lines'][$lineIndex]['match_decision'] = 'manual';
        $this->ocrDraft['lines'][$lineIndex]['match_status'] = 'matched';
        $this->ocrDraft['lines'][$lineIndex]['name'] = localized_name($product, 'name', 'منتج #'.$productId);
        $this->ocrDraft['lines'][$lineIndex]['pieces_per_box'] = $product->pieces_per_box;
        $this->ocrDraft['lines'][$lineIndex]['points_per_unit'] = $product->points_per_unit;
        $this->ocrDraft['lines'][$lineIndex]['main_image'] = $product->main_image;
        $this->ocrDraft['lines'][$lineIndex]['approved'] = false;
        $this->ocrDraft['lines'][$lineIndex]['needs_review'] = array_values(array_filter(
            (array) ($this->ocrDraft['lines'][$lineIndex]['needs_review'] ?? []),
            fn ($f) => ! in_array($f, ['low_confidence', 'needs_choice', 'catalog_unmatched', 'mg_family_unknown_variant'], true)
        ));
        $this->ocrFocusLine = $lineIndex;
    }

    /** Pick product (optional) + mark approved in one clear action. */
    public function confirmOcrLine(int $lineIndex, ?int $productId = null): void
    {
        if (! isset($this->ocrDraft['lines'][$lineIndex])) {
            return;
        }

        if ($productId) {
            $this->selectOcrCandidate($lineIndex, $productId);
        }

        if (empty($this->ocrDraft['lines'][$lineIndex]['matched_product_id'])) {
            Notification::make()->warning()->title('اختَر منتجاً أولاً')->send();

            return;
        }

        $this->ocrDraft['lines'][$lineIndex]['approved'] = true;
        $this->ocrDraft['lines'][$lineIndex]['review_status'] = 'approved';
        $this->persistOcrLineReview($lineIndex, 'approved');
        $this->ocrFocusLine = $lineIndex + 1;
    }

    public function unconfirmOcrLine(int $lineIndex): void
    {
        if (! isset($this->ocrDraft['lines'][$lineIndex])) {
            return;
        }
        $this->ocrDraft['lines'][$lineIndex]['approved'] = false;
        $this->ocrDraft['lines'][$lineIndex]['review_status'] = 'ready';
        $this->persistOcrLineReview($lineIndex, 'ready');
        $this->ocrFocusLine = $lineIndex;
    }

    protected function persistOcrLineReview(int $lineIndex, string $status): void
    {
        $lineId = (int) ($this->ocrDraft['lines'][$lineIndex]['ocr_line_id'] ?? 0);
        if ($lineId < 1) {
            return;
        }
        \App\Models\OcrImageLine::query()->where('id', $lineId)->update([
            'review_status' => $status,
            'resolved_product_id' => $this->ocrDraft['lines'][$lineIndex]['matched_product_id'] ?? null,
            'qty' => $this->ocrDraft['lines'][$lineIndex]['qty'] ?? null,
        ]);
    }

    public function chooseOcrSystem(int $lineIndex, string $system): void
    {
        if (! in_array($system, ['us', 'eu'], true) || ! isset($this->ocrDraft['lines'][$lineIndex])) {
            return;
        }
        $candidates = (array) ($this->ocrDraft['lines'][$lineIndex]['candidates'] ?? []);
        foreach ($candidates as $c) {
            if (($c['system'] ?? null) === $system && ! empty($c['product_id'])) {
                $this->selectOcrCandidate($lineIndex, (int) $c['product_id']);
                $this->ocrDraft['lines'][$lineIndex]['slots']['system'] = $system;
                $this->ocrDraft['lines'][$lineIndex]['slots']['system_source'] = 'text';
                $this->ocrDraft['lines'][$lineIndex]['match_reason'] = 'من الكتابة';

                return;
            }
        }
    }

    /** Confirm dialect/English understanding chip (keep current family suggestion). */
    public function confirmOcrUnderstanding(int $lineIndex): void
    {
        if (! isset($this->ocrDraft['lines'][$lineIndex])) {
            return;
        }

        $line = $this->ocrDraft['lines'][$lineIndex];
        $line['understanding_confirmed'] = true;
        if (! empty($line['slots']['lexicon_family'])) {
            $line['force_lexicon_family'] = (string) $line['slots']['lexicon_family'];
        }

        $enriched = app(\App\Services\Ocr\InvoiceOcrCatalogMatcher::class)->enrich(
            ['lines' => [$line]],
            $this->ocrSystemDefault === 'ask' ? null : $this->ocrSystemDefault,
            $this->wholesaleDistributorId ? (int) $this->wholesaleDistributorId : null,
        );
        if (! empty($enriched['lines'][0])) {
            $this->ocrDraft['lines'][$lineIndex] = array_merge($enriched['lines'][0], [
                'understanding_confirmed' => true,
                'force_lexicon_family' => $line['force_lexicon_family'] ?? null,
            ]);
        }
    }

    /** User picked a lexicon family when مثلثات (etc.) was ambiguous. */
    public function chooseOcrLexiconFamily(int $lineIndex, string $lexiconFamily): void
    {
        if (! isset($this->ocrDraft['lines'][$lineIndex]) || $lexiconFamily === '') {
            return;
        }

        $line = $this->ocrDraft['lines'][$lineIndex];
        $line['force_lexicon_family'] = $lexiconFamily;
        $line['understanding_confirmed'] = true;

        $enriched = app(\App\Services\Ocr\InvoiceOcrCatalogMatcher::class)->enrich(
            ['lines' => [$line]],
            $this->ocrSystemDefault === 'ask' ? null : $this->ocrSystemDefault,
            $this->wholesaleDistributorId ? (int) $this->wholesaleDistributorId : null,
        );
        if (! empty($enriched['lines'][0])) {
            $this->ocrDraft['lines'][$lineIndex] = array_merge($enriched['lines'][0], [
                'force_lexicon_family' => $lexiconFamily,
                'understanding_confirmed' => true,
            ]);
        } else {
            $this->ocrDraft['lines'][$lineIndex]['match_status'] = 'needs_choice';
            $this->ocrDraft['lines'][$lineIndex]['needs_review'] = ['needs_choice'];
            $this->ocrDraft['lines'][$lineIndex]['force_lexicon_family'] = $lexiconFamily;
        }
    }

    public function adjustOcrQty(int $lineIndex, int $delta): void
    {
        if (! isset($this->ocrDraft['lines'][$lineIndex])) {
            return;
        }
        $qty = (float) ($this->ocrDraft['lines'][$lineIndex]['qty'] ?? 1);
        $this->ocrDraft['lines'][$lineIndex]['qty'] = max(1, $qty + $delta);
    }

    public function approveAllGreenOcrLines(): void
    {
        if (! is_array($this->ocrDraft)) {
            return;
        }

        foreach ($this->ocrDraft['lines'] ?? [] as $i => $line) {
            $decision = $line['match_decision'] ?? '';
            $flags = (array) ($line['needs_review'] ?? []);
            if (in_array($decision, ['auto', 'alias'], true) && $flags === [] && ! empty($line['matched_product_id'])) {
                $this->ocrDraft['lines'][$i]['approved'] = true;
            }
        }
    }

    public function toggleOcrLineApproved(int $lineIndex): void
    {
        if (! isset($this->ocrDraft['lines'][$lineIndex])) {
            return;
        }
        $current = (bool) ($this->ocrDraft['lines'][$lineIndex]['approved'] ?? false);
        $this->ocrDraft['lines'][$lineIndex]['approved'] = ! $current;
        if (! $current) {
            $this->ocrFocusLine = $lineIndex + 1;
        }
    }

    public function ocrKeyboard(string $key): void
    {
        $i = $this->ocrFocusLine;
        $key = strtolower($key);
        if ($key === 'a') {
            $this->chooseOcrSystem($i, 'us');
        } elseif ($key === 'e') {
            $this->chooseOcrSystem($i, 'eu');
        } elseif (in_array($key, ['1', '2', '3'], true)) {
            $c = $this->ocrDraft['lines'][$i]['candidates'][((int) $key) - 1] ?? null;
            if ($c && ! empty($c['product_id'])) {
                $this->confirmOcrLine($i, (int) $c['product_id']);
            }
        } elseif ($key === 'enter') {
            $this->confirmOcrLine($i);
        }
    }

    public function restoreExcludedOcrLine(int $excludedIndex): void
    {
        if (! isset($this->ocrDraft['excluded'][$excludedIndex])) {
            return;
        }
        $ex = $this->ocrDraft['excluded'][$excludedIndex];
        unset($this->ocrDraft['excluded'][$excludedIndex]);
        $this->ocrDraft['excluded'] = array_values($this->ocrDraft['excluded']);
        $this->ocrDraft['lines'][] = [
            'raw_text' => $ex['raw_text'] ?? '',
            'name' => $ex['name'] ?? '',
            'qty' => $ex['qty'] ?? 1,
            'match_decision' => 'manual',
            'match_status' => 'manual_add',
            'needs_review' => ['manual_add'],
            'candidates' => [],
        ];
    }

    public function applyApprovedOcrToCart(): void
    {
        if ($this->ocrAddingToCart) {
            return;
        }

        if (! is_array($this->ocrDraft)) {
            Notification::make()->danger()->title('لا توجد مسودة')->send();

            return;
        }

        $this->ocrAddingToCart = true;

        try {
            $batch = $this->ownedOcrBatch();
            if ($batch && $batch->cart_added_at && $batch->idempotency_token) {
                Notification::make()->warning()->title('تمّت الإضافة مسبقاً')->body('نفس الدفعة — لن تُكرَّر البنود.')->send();

                return;
            }

            $token = $batch?->ensureIdempotencyToken();

            $sheet = app(InvoiceCartSheetService::class);
            $learning = app(InvoiceOcrLearningService::class);
            $lines = [];
            $errors = [];
            $approvedCount = 0;
            $addedLineIds = [];

            foreach ((array) ($this->ocrDraft['lines'] ?? []) as $idx => $line) {
                if (empty($line['approved']) || ! empty($line['added'])) {
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
                $cartLine = $sheet->cartLine($product, $qty);
                $id = (string) $productId;
                if (isset($lines[$id])) {
                    if (! empty($cartLine['boxes'])) {
                        $lines[$id]['boxes'] = (int) $lines[$id]['boxes'] + (int) $cartLine['boxes'];
                        $lines[$id]['quantity'] = (int) $lines[$id]['boxes'] * (int) $lines[$id]['pieces_per_box'];
                    } else {
                        $lines[$id]['quantity'] = (int) $lines[$id]['quantity'] + (int) $cartLine['quantity'];
                    }
                } else {
                    $lines[$id] = $cartLine;
                }

                if (! empty($line['ocr_line_id'])) {
                    $addedLineIds[] = (int) $line['ocr_line_id'];
                }
                $this->ocrDraft['lines'][$idx]['added'] = true;
                $this->ocrDraft['lines'][$idx]['review_status'] = 'added';

                if ($this->ocrImageSha) {
                    $learning->rememberCorrection(
                        $this->ocrImageSha,
                        (string) ($line['raw_text'] ?? $line['name'] ?? ''),
                        $productId,
                        (float) $qty,
                        auth()->user(),
                        (string) ($line['slots']['system'] ?? $product->system ?? ''),
                        $this->wholesaleDistributorId ? (int) $this->wholesaleDistributorId : null,
                        $this->ocrRememberForCustomer,
                    );
                }
            }

            if ($lines === []) {
                Notification::make()
                    ->warning()
                    ->title('لا بنود معتمدة')
                    ->body('علّم البنود أو استخدم «اعتماد كل الأخضر» ثم طبّق على السلة.'.($errors ? "\n".implode("\n", array_slice($errors, 0, 5)) : ''))
                    ->send();

                return;
            }

            $this->cart = $lines;
            $this->reviewing = false;

            if ($batch && $addedLineIds !== []) {
                app(OcrBatchService::class)->markLinesAdded($batch, $addedLineIds);
            } elseif ($batch) {
                $batch->status = 'added';
                $batch->cart_added_at = now();
                $batch->idempotency_token = $token;
                $batch->save();
            }

            Notification::make()
                ->success()
                ->title('تم تطبيق '.$approvedCount.' منتج على السلة')
                ->body('راجع الكميات قبل الإصدار. لم يُحفظ أي مبلغ مالياً من OCR دون هذا الاعتماد.')
                ->send();
        } finally {
            $this->ocrAddingToCart = false;
        }
    }

    public function dismissOcrDraft(): void
    {
        $this->ocrDraft = null;
        $this->ocrStep = 'idle';
        $this->ocrError = null;
        $this->ocrPreviewUrl = null;
        $this->ocrImageSha = null;
        $this->ocrReading = false;
        $this->ocrFocusLine = 0;
        $this->ocrBatchId = null;
        $this->ocrImageStrip = [];
        $this->ocrMergeSuggestions = [];
        $this->ocrResumeBanner = false;
        $this->ocrPollTicks = 0;
    }

    public function getOcrApprovedCountProperty(): int
    {
        if (! is_array($this->ocrDraft)) {
            return 0;
        }
        $n = 0;
        foreach ((array) ($this->ocrDraft['lines'] ?? []) as $line) {
            if (! empty($line['approved']) && ! empty($line['matched_product_id'])) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $errors
     */
    protected function applyImportedCartLines(
        array $lines,
        array $errors,
        string $sourceLabel,
        string $sourceType = '',
    ): void {
        if ($lines === []) {
            Notification::make()
                ->danger()
                ->title('لم تُضف بنود من '.$sourceLabel)
                ->body(implode("\n", array_slice($errors, 0, 8)) ?: 'لم يُتعرّف على منتجات من الكتالوج.')
                ->send();

            return;
        }

        $this->cart = [];
        $this->reviewing = false;
        foreach ($lines as $line) {
            $this->cart[(string) $line['product_id']] = $line;
        }

        $loaded = count($lines);
        $hint = $sourceType !== '' ? " (نوع المصدر: {$sourceType})" : '';
        $notice = Notification::make()
            ->success()
            ->title("تم تحميل {$loaded} منتج من {$sourceLabel}{$hint}")
            ->body('راجع الكميات والأسماء في السلة ثم أكمل الإصدار. البنود غير المطابقة أضفها يدوياً.');

        if ($errors !== []) {
            $notice->warning()
                ->title("تم تحميل {$loaded} منتج، وبعض البنود لم تُطابق")
                ->body(implode("\n", array_slice($errors, 0, 8)));
        }

        $notice->send();
    }

    public function openReview(): void
    {
        if ($this->cart === []) {
            Notification::make()->danger()->title('أضف منتجات للفاتورة أولاً')->send();

            return;
        }

        $this->reviewing = true;
    }

    public function closeReview(): void
    {
        $this->reviewing = false;
    }

    public function confirmIssue(): void
    {
        if (! $this->reviewing) {
            $this->openReview();

            return;
        }

        if ($this->reviewIssues !== []) {
            Notification::make()
                ->danger()
                ->title('لا يمكن الحفظ قبل معالجة الملاحظات')
                ->body(implode(' — ', $this->reviewIssues))
                ->send();

            return;
        }

        $this->issueInvoice();
    }

    public function issueInvoice(): void
    {
        if (! $this->reviewing || $this->reviewIssues !== []) {
            $this->openReview();

            return;
        }

        $user = auth()->user();

        if ($user?->isWholesaleDistributor()) {
            $this->wholesaleDistributorId = $user->id;
        }

        if (! $this->wholesaleDistributorId) {
            Notification::make()->danger()->title('اختر موزع الجملة')->send();

            return;
        }

        $wholesaler = User::findOrFail($this->wholesaleDistributorId);

        if ($user?->isWholesaleDistributor() && (int) $wholesaler->id !== (int) $user->id) {
            Notification::make()->danger()->title('لا يمكنك إصدار فاتورة لموزع آخر')->send();

            return;
        }

        try {
            $invoice = app(WholesaleInvoiceService::class)->issueFromCart(
                wholesaler: $wholesaler,
                lines: array_values($this->cart),
                issuedBy: $user,
            );

            Notification::make()
                ->success()
                ->title('تم إصدار الفاتورة')
                ->body("رقم {$invoice->number} — إجمالي {$this->totalPoints} نقطة")
                ->send();

            if ((string) config('services.whatsapp.driver', 'log') === 'log') {
                $chatUrl = app(CustomerWhatsAppNotifier::class)->invoiceChatUrl($invoice);
                if ($chatUrl) {
                    $this->js('window.open('.Js::from($chatUrl).', "_blank", "noopener")');
                }
            }

            $this->reviewing = false;
            $this->redirect(route('admin.invoices.print', $invoice).'?auto=1');
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
        }
    }
}
