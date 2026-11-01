<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Concerns\SetsCartQuantity;
use App\Filament\Resources\InvoiceResource;
use App\Jobs\ProcessPosOcrJob;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Ai\GeminiClient;
use App\Services\CustomerWhatsAppNotifier;
use App\Services\InvoiceCartSheetService;
use App\Services\InvoiceNumberService;
use App\Services\Ocr\InvoiceOcrLearningService;
use App\Services\Ocr\InvoiceOcrPipeline;
use App\Services\WholesaleInvoiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
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

    public bool $ocrReading = false;

    public string $ocrStep = 'idle';

    public ?string $ocrJobKey = null;

    public ?string $ocrPreviewUrl = null;

    public ?string $ocrImageSha = null;

    public ?string $ocrError = null;

    /** @var array<string, mixed>|null */
    public ?array $ocrDraft = null;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
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

        if (! app(GeminiClient::class)->enabled()) {
            Notification::make()
                ->danger()
                ->title('Gemini غير مفعّل')
                ->body('أضف GEMINI_API_KEY في إعدادات السيرفر ثم أعد تحميل الإعدادات.')
                ->send();
            $this->invoiceOcrImage = null;

            return;
        }

        $this->validate([
            'invoiceOcrImage' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $stored = $this->invoiceOcrImage->store('ocr-uploads/'.date('Y/m/d'), 'public');
        $this->ocrPreviewUrl = Storage::disk('public')->url($stored);
        $absolute = Storage::disk('public')->path($stored);
        $this->invoiceOcrImage = null;
        $this->ocrDraft = null;
        $this->ocrError = null;
        $this->ocrReading = true;
        $this->ocrStep = 'queued';

        $useQueue = config('queue.default') !== 'sync';

        if ($useQueue) {
            $this->ocrJobKey = (string) Str::uuid();
            Cache::put('ocr:pos:job:'.$this->ocrJobKey, [
                'status' => 'queued',
                'step' => 'queued',
                'message' => 'في الانتظار…',
            ], 600);
            ProcessPosOcrJob::dispatch($this->ocrJobKey, $absolute);
            $this->ocrStep = 'polling';
            Notification::make()->info()->title('بدأت قراءة الصورة')->body('ستظهر المسودة للمراجعة دون حفظ تلقائي.')->send();

            return;
        }

        // Local/sync: run inline (may take up to OCR timeout).
        $this->ocrStep = 'reading';
        $result = app(InvoiceOcrPipeline::class)->run($absolute);
        $this->applyOcrPipelineResult($result);
    }

    public function pollOcrJob(): void
    {
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

        $this->ocrDraft = $result['draft'];
        $this->ocrStep = 'review';
        $this->ocrError = null;
        $cached = ! empty($result['cached']) ? ' (من الذاكرة المؤقتة)' : '';
        Notification::make()
            ->success()
            ->title('مسودة جاهزة للمراجعة'.$cached)
            ->body('راجع البنود ثم اضغط اعتماد — لن تُحفظ في السلة تلقائياً.')
            ->send();
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
        $this->ocrDraft['lines'][$lineIndex]['name'] = localized_name($product, 'name', 'منتج #'.$productId);
        $this->ocrDraft['lines'][$lineIndex]['needs_review'] = array_values(array_filter(
            (array) ($this->ocrDraft['lines'][$lineIndex]['needs_review'] ?? []),
            fn ($f) => $f !== 'low_confidence'
        ));
    }

    public function approveAllGreenOcrLines(): void
    {
        if (! is_array($this->ocrDraft)) {
            return;
        }

        foreach ($this->ocrDraft['lines'] ?? [] as $i => $line) {
            $decision = $line['match_decision'] ?? '';
            $sim = (float) ($line['match_similarity'] ?? 0);
            $flags = (array) ($line['needs_review'] ?? []);
            if (in_array($decision, ['auto', 'alias'], true) && $sim >= 0.9 && $flags === [] && ! empty($line['matched_product_id'])) {
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
    }

    public function applyApprovedOcrToCart(): void
    {
        if (! is_array($this->ocrDraft)) {
            Notification::make()->danger()->title('لا توجد مسودة')->send();

            return;
        }

        $sheet = app(InvoiceCartSheetService::class);
        $learning = app(InvoiceOcrLearningService::class);
        $lines = [];
        $errors = [];

        foreach ((array) ($this->ocrDraft['lines'] ?? []) as $line) {
            // Explicit approval only — never auto-push money/cart without user action.
            if (empty($line['approved'])) {
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

            if ($this->ocrImageSha) {
                $learning->rememberCorrection(
                    $this->ocrImageSha,
                    (string) ($line['raw_text'] ?? $line['name'] ?? ''),
                    $productId,
                    (float) $qty,
                    auth()->user(),
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
        Notification::make()
            ->success()
            ->title('تم تطبيق '.count($lines).' منتج على السلة')
            ->body('راجع الكميات قبل الإصدار. لم يُحفظ أي مبلغ مالياً من OCR دون هذا الاعتماد.')
            ->send();
    }

    public function dismissOcrDraft(): void
    {
        $this->ocrDraft = null;
        $this->ocrStep = 'idle';
        $this->ocrError = null;
        $this->ocrPreviewUrl = null;
        $this->ocrImageSha = null;
        $this->ocrReading = false;
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
