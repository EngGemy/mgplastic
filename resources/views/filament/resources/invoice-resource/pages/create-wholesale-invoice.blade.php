<x-filament-panels::page dir="rtl">
    <div class="pos-wrap">

        {{-- ── Header: رقم الفاتورة + الموزع ── --}}
        <div class="pos-header">
            <div class="pos-header-field pos-invoice-no">
                <label class="pos-label">رقم الفاتورة (التالي)</label>
                <div class="pos-invoice-number">{{ $this->nextInvoiceNumber }}</div>
                <p class="pos-invoice-no-hint">يُثبَّت عند الإصدار ويُستخدم في التوزيعات والتقارير</p>
            </div>
            @if(auth()->user()?->isWholesaleDistributor())
                <div class="pos-header-field">
                    <label class="pos-label">موزع الجملة</label>
                    <div class="pos-invoice-number" style="font-size:1rem">{{ auth()->user()->name }}</div>
                </div>
            @else
                <div class="pos-header-field">
                    <label class="pos-label">موزع الجملة (المستلم)</label>
                    <select wire:model.live="wholesaleDistributorId" class="pos-select">
                        <option value="">— اختر موزع الجملة —</option>
                        @foreach($this->wholesalers as $w)
                            <option value="{{ $w->id }}">{{ $w->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="pos-header-field pos-search-wrap">
                <label class="pos-label">بحث منتج</label>
                <input type="text" wire:model.live.debounce.300ms="search" class="pos-input" placeholder="ابحث بالاسم...">
            </div>
            <div class="pos-header-field pos-sheet-field">
                <label class="pos-label">شيت المنتجات والكميات</label>
                <div class="pos-sheet-actions">
                    <button type="button" wire:click="downloadInvoiceTemplate" class="pos-sheet-btn">تنزيل النموذج</button>
                    <label class="pos-sheet-btn pos-sheet-btn--upload">
                        رفع الشيت
                        <input type="file" wire:model="invoiceSheet" accept=".xlsx,.xls,.csv">
                    </label>
                </div>
                <p class="pos-invoice-no-hint" wire:loading.remove wire:target="invoiceSheet">الكمية = عدد الصناديق لو المنتج له صندوق، وإلا عدد القطع. بعد الرفع راجع الفاتورة قبل الحفظ.</p>
                <p class="pos-invoice-no-hint" wire:loading wire:target="invoiceSheet">جاري قراءة الشيت...</p>
                @error('invoiceSheet')
                    <p class="pos-sheet-error">{{ $message }}</p>
                @enderror
            </div>
            <div class="pos-header-field pos-sheet-field">
                <label class="pos-label">قراءة فاتورة من صورة (OCR)</label>
                <div class="pos-sheet-actions">
                    <label class="pos-sheet-btn pos-sheet-btn--upload">
                        رفع صورة الفاتورة
                        <input type="file" wire:model="invoiceOcrImage" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
                    </label>
                </div>
                <p class="pos-invoice-no-hint" wire:loading.remove wire:target="invoiceOcrImage">
                    ارفع صورة (خط يد / حراري / مطبوع) — تظهر مسودة للمراجعة أولاً، ولا تُضاف للسلة إلا بعد الاعتماد.
                </p>
                <p class="pos-invoice-no-hint" wire:loading wire:target="invoiceOcrImage">جاري رفع الصورة...</p>
                @error('invoiceOcrImage')
                    <p class="pos-sheet-error">{{ $message }}</p>
                @enderror
                @if($ocrReading || in_array($ocrStep, ['queued', 'polling', 'reading', 'processing'], true))
                    <p class="pos-invoice-no-hint">الحالة: {{ $ocrStep }} — جاري الاستخراج…</p>
                    @if($ocrJobKey)
                        <div wire:poll.2s="pollOcrJob"></div>
                    @endif
                @endif
                @if($ocrError)
                    <p class="pos-sheet-error">{{ $ocrError }}</p>
                @endif
            </div>
        </div>

        @if($ocrPreviewUrl || $ocrDraft)
            @php
                $familyLabels = [
                    'coupling' => 'وصلة عادية', 'elbow_90' => 'كوع 90', 'elbow_45' => 'كوع 45',
                    'tee' => 'مثلث', 'wye' => 'واي', 'end_cap' => 'نهاية خط',
                    'male_thread_coupling' => 'وصلة سن خارجي', 'female_thread_coupling' => 'وصلة سن داخلي',
                    'pipe' => 'ماسورة', 'reducer' => 'مخفض', 'cross' => 'صليبة', 'trap' => 'مانع رائحة',
                    'cleanout' => 'طابة نظافة', 'manifold' => 'مجمع', 'vent' => 'هاوية',
                ];
                $linesAll = (array) ($ocrDraft['lines'] ?? []);
                $excludedAll = (array) ($ocrDraft['excluded'] ?? []);
                $pendingLines = [];
                $readyLines = [];
                $approvedLines = [];
                foreach ($linesAll as $i => $line) {
                    if (! empty($line['approved']) && ! empty($line['matched_product_id'])) {
                        $approvedLines[$i] = $line;
                    } elseif (! empty($line['matched_product_id']) && empty($line['needs_review'])) {
                        $readyLines[$i] = $line;
                    } else {
                        $pendingLines[$i] = $line;
                    }
                }
                $approvedN = $this->ocrApprovedCount;
                $pendingN = count($pendingLines);
                $readyN = count($readyLines);
                $exclN = count($excludedAll);
            @endphp
            <section class="ocr-desk" dir="rtl"
                x-data="{ showExcluded: false }"
                @keydown.window="
                    if (['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName)) return;
                    const k = $event.key;
                    if (['a','A','e','E','1','2','3','Enter'].includes(k)) {
                        $event.preventDefault();
                        $wire.ocrKeyboard(k === 'Enter' ? 'enter' : k);
                    }
                ">
                <header class="ocr-desk__head">
                    <div>
                        <p class="ocr-desk__eyebrow">من الصورة إلى السلة</p>
                        <h2 class="ocr-desk__title">راجع البنود قبل الإضافة</h2>
                        <p class="ocr-desk__hint">① اختَر المنتج المناسب ← ② اضغط «اعتماد هذا» ← ③ أضِف المعتمد للسلة</p>
                    </div>
                    <div class="ocr-desk__stats" aria-label="ملخص الحالة">
                        <span class="ocr-stat ocr-stat--warn"><b>{{ $pendingN }}</b> يحتاجك</span>
                        <span class="ocr-stat ocr-stat--ok"><b>{{ $readyN }}</b> جاهز</span>
                        <span class="ocr-stat ocr-stat--done"><b>{{ $approvedN }}</b> معتمد</span>
                        <span class="ocr-stat"><b>{{ $exclN }}</b> مستبعد</span>
                    </div>
                </header>

                <div class="ocr-desk__toolbar">
                    <div class="ocr-seg" role="group" aria-label="نظام افتراضي">
                        <span class="ocr-seg__label">لو المقاس مش واضح:</span>
                        <button type="button" class="ocr-seg__btn {{ $ocrSystemDefault==='ask' ? 'is-on' : '' }}" wire:click="setOcrSystemDefault('ask')">اسألني</button>
                        <button type="button" class="ocr-seg__btn {{ $ocrSystemDefault==='us' ? 'is-on' : '' }}" wire:click="setOcrSystemDefault('us')">أمريكي</button>
                        <button type="button" class="ocr-seg__btn {{ $ocrSystemDefault==='eu' ? 'is-on' : '' }}" wire:click="setOcrSystemDefault('eu')">أوروبي</button>
                    </div>
                    <label class="ocr-remember">
                        <input type="checkbox" wire:model.live="ocrRememberForCustomer">
                        احفظ اختياري لهذا الموزع
                    </label>
                </div>

                <div class="ocr-desk__grid">
                    <aside class="ocr-photo">
                        <p class="ocr-photo__label">الصورة الأصلية</p>
                        @if($ocrPreviewUrl)
                            <a href="{{ $ocrPreviewUrl }}" target="_blank" rel="noopener" class="ocr-photo__frame">
                                <img src="{{ $ocrPreviewUrl }}" alt="فاتورة مكتوبة">
                            </a>
                        @else
                            <div class="ocr-photo__empty">لا توجد معاينة</div>
                        @endif
                        <p class="ocr-photo__keys">اختصار: 1–3 اختيار · Enter اعتماد · A/E نظام</p>
                    </aside>

                    <div class="ocr-queue">
                        @if($pendingN > 0)
                            <h3 class="ocr-queue__title ocr-queue__title--warn">يحتاج قرارك <span>{{ $pendingN }}</span></h3>
                            @foreach($pendingLines as $i => $line)
                                @php
                                    $slots = (array) ($line['slots'] ?? []);
                                    $cands = (array) ($line['candidates'] ?? []);
                                    $usCand = collect($cands)->firstWhere('system', 'us');
                                    $euCand = collect($cands)->firstWhere('system', 'eu');
                                    $familyAr = $familyLabels[$slots['family'] ?? ''] ?? ($slots['family'] ?? 'غير معروف');
                                    $sizeLabel = isset($slots['size_value'])
                                        ? rtrim(rtrim(number_format((float)$slots['size_value'], 2, '.', ''), '0'), '.').(($slots['size_unit'] ?? '') === 'mm' ? ' مم' : ' بوصة')
                                        : '؟';
                                    $sysLabel = ($slots['system'] ?? null) === 'us' ? 'أمريكي' : (($slots['system'] ?? null) === 'eu' ? 'أوروبي' : 'غير محدد');
                                    $matchedId = (int) ($line['matched_product_id'] ?? 0);
                                    $focused = $ocrFocusLine === $i;
                                @endphp
                                <article class="ocr-card ocr-card--pending {{ $focused ? 'is-focus' : '' }}">
                                    <div class="ocr-card__top">
                                        <div class="ocr-card__write">
                                            <span class="ocr-card__badge">مكتوب</span>
                                            <p class="ocr-card__raw">«{{ $line['raw_text'] ?? '' }}»</p>
                                            <p class="ocr-card__meta">{{ $familyAr }} · {{ $sizeLabel }} · {{ $sysLabel }}</p>
                                        </div>
                                        <div class="ocr-qty" aria-label="الكمية">
                                            <button type="button" wire:click="adjustOcrQty({{ $i }}, -1)" aria-label="إنقاص">−</button>
                                            <span>{{ (int) ($line['qty'] ?? 1) }}</span>
                                            <button type="button" wire:click="adjustOcrQty({{ $i }}, 1)" aria-label="زيادة">+</button>
                                        </div>
                                    </div>

                                    @if($usCand && $euCand)
                                        <div class="ocr-sys">
                                            <span>اختَر النظام:</span>
                                            <button type="button" class="ocr-sys__btn {{ $matchedId === (int)($usCand['product_id'] ?? 0) ? 'is-on' : '' }}" wire:click="chooseOcrSystem({{ $i }}, 'us')">أمريكي</button>
                                            <button type="button" class="ocr-sys__btn {{ $matchedId === (int)($euCand['product_id'] ?? 0) ? 'is-on' : '' }}" wire:click="chooseOcrSystem({{ $i }}, 'eu')">أوروبي</button>
                                        </div>
                                    @endif

                                    <p class="ocr-card__ask">أي منتج تقصد؟</p>
                                    <div class="ocr-picks" role="radiogroup">
                                        @forelse($cands as $ci => $cand)
                                            @php $pid = (int) ($cand['product_id'] ?? 0); $on = $matchedId === $pid; @endphp
                                            <button type="button"
                                                class="ocr-pick {{ $on ? 'is-on' : '' }}"
                                                wire:click="selectOcrCandidate({{ $i }}, {{ $pid }})"
                                                role="radio"
                                                aria-checked="{{ $on ? 'true' : 'false' }}">
                                                <span class="ocr-pick__num">{{ $ci + 1 }}</span>
                                                <span class="ocr-pick__body">
                                                    <strong>{{ $cand['name'] ?? ('منتج #'.$pid) }}</strong>
                                                    <small>
                                                        @if(($cand['system'] ?? null) === 'us') أمريكي
                                                        @elseif(($cand['system'] ?? null) === 'eu') أوروبي
                                                        @endif
                                                        @if(!empty($cand['pieces_per_box'])) · {{ $cand['pieces_per_box'] }} / صندوق @endif
                                                        @if(isset($cand['points_per_unit'])) · {{ $cand['points_per_unit'] }} نقطة @endif
                                                    </small>
                                                </span>
                                                <span class="ocr-pick__check" aria-hidden="true">{{ $on ? '✓' : '' }}</span>
                                            </button>
                                        @empty
                                            <p class="ocr-card__empty">لا مرشحين — أضِف المنتج من الشبكة يدوياً بعد الإغلاق.</p>
                                        @endforelse
                                    </div>

                                    <div class="ocr-card__actions">
                                        @if($matchedId > 0)
                                            <span class="ocr-card__picked">اخترت: {{ $line['name'] ?? '' }}</span>
                                            <button type="button" class="ocr-btn ocr-btn--primary" wire:click="confirmOcrLine({{ $i }})">
                                                اعتماد هذا البند
                                            </button>
                                        @else
                                            <p class="ocr-card__nudge">اضغط على منتج من القائمة فوق، ثم اعتمد</p>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        @endif

                        @if($readyN > 0)
                            <h3 class="ocr-queue__title ocr-queue__title--ok">جاهز للاعتماد السريع <span>{{ $readyN }}</span></h3>
                            @foreach($readyLines as $i => $line)
                                <article class="ocr-card ocr-card--ready">
                                    <div class="ocr-card__top">
                                        <div>
                                            <p class="ocr-card__raw">«{{ $line['raw_text'] ?? '' }}»</p>
                                            <p class="ocr-card__matched">→ {{ $line['name'] ?? '' }}</p>
                                        </div>
                                        <div class="ocr-qty">
                                            <button type="button" wire:click="adjustOcrQty({{ $i }}, -1)">−</button>
                                            <span>{{ (int) ($line['qty'] ?? 1) }}</span>
                                            <button type="button" wire:click="adjustOcrQty({{ $i }}, 1)">+</button>
                                        </div>
                                    </div>
                                    <button type="button" class="ocr-btn ocr-btn--primary" wire:click="confirmOcrLine({{ $i }})">اعتماد هذا</button>
                                </article>
                            @endforeach
                            <button type="button" class="ocr-btn ocr-btn--ghost" wire:click="approveAllGreenOcrLines">اعتماد كل الجاهز دفعة واحدة</button>
                        @endif

                        @if($approvedN > 0)
                            <h3 class="ocr-queue__title ocr-queue__title--done">معتمد — هيتحط في السلة <span>{{ $approvedN }}</span></h3>
                            @foreach($approvedLines as $i => $line)
                                <article class="ocr-card ocr-card--done">
                                    <div class="ocr-card__top">
                                        <div>
                                            <p class="ocr-card__raw">«{{ $line['raw_text'] ?? '' }}»</p>
                                            <p class="ocr-card__matched">✓ {{ $line['name'] ?? '' }} × {{ (int) ($line['qty'] ?? 1) }}</p>
                                        </div>
                                        <button type="button" class="ocr-btn ocr-btn--ghost" wire:click="unconfirmOcrLine({{ $i }})">إلغاء الاعتماد</button>
                                    </div>
                                </article>
                            @endforeach
                        @endif

                        @if($exclN > 0)
                            <button type="button" class="ocr-excluded-toggle" @click="showExcluded = !showExcluded">
                                <span>مستبعد من السلة ({{ $exclN }}) — مش منتجات MG أو مش مقروء</span>
                                <span x-text="showExcluded ? 'إخفاء' : 'عرض'"></span>
                            </button>
                            <div x-show="showExcluded" x-cloak class="ocr-excluded">
                                @foreach($excludedAll as $ei => $ex)
                                    <div class="ocr-excluded__row">
                                        <div>
                                            <s>«{{ $ex['raw_text'] ?? '' }}»</s>
                                            <small>× {{ $ex['qty'] ?? '?' }} — لن يُضاف تلقائياً</small>
                                        </div>
                                        <button type="button" class="ocr-btn ocr-btn--ghost" wire:click="restoreExcludedOcrLine({{ $ei }})">إرجاع للمراجعة</button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($pendingN === 0 && $readyN === 0 && $approvedN === 0)
                            <p class="ocr-card__empty">لا بنود للمراجعة.</p>
                        @endif
                    </div>
                </div>

                <footer class="ocr-desk__foot">
                    <button type="button" class="ocr-btn ocr-btn--ghost" wire:click="dismissOcrDraft">إغلاق بدون إضافة</button>
                    <button type="button"
                        class="ocr-btn ocr-btn--cta"
                        wire:click="applyApprovedOcrToCart"
                        @disabled($approvedN < 1)>
                        أضِف المعتمد للسلة ({{ $approvedN }})
                    </button>
                </footer>
            </section>
        @endif

        <div class="pos-body">

            {{-- ── التصنيفات ── --}}
            <aside class="pos-categories">
                <p class="pos-side-title">التصنيفات</p>
                <button type="button"
                    wire:click="selectCategory(null)"
                    class="pos-cat-btn {{ is_null($selectedCategoryId) ? 'pos-cat-btn--active' : '' }}">
                    الكل
                </button>
                @foreach($this->categories as $parent)
                    <button type="button"
                        wire:click="selectCategory({{ $parent->id }})"
                        class="pos-cat-btn {{ $selectedCategoryId === $parent->id ? 'pos-cat-btn--active' : '' }}">
                        {{ $parent->name }}
                    </button>
                    @foreach($parent->children as $child)
                        <button type="button"
                            wire:click="selectCategory({{ $child->id }})"
                            class="pos-cat-btn pos-cat-btn--child {{ $selectedCategoryId === $child->id ? 'pos-cat-btn--active' : '' }}">
                            ↳ {{ $child->name }}
                        </button>
                    @endforeach
                @endforeach
            </aside>

            {{-- ── المنتجات ── --}}
            <section class="pos-products">
                <p class="pos-side-title">المنتجات — اضغط للإضافة</p>
                <div class="pos-product-grid">
                    @forelse($this->products as $product)
                        <button type="button"
                            wire:click="addProduct({{ $product->id }})"
                            class="pos-product-card">
                            @if($product->main_image)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->main_image) }}" alt="" class="pos-product-img">
                            @else
                                <div class="pos-product-img pos-product-img--empty">📦</div>
                            @endif
                            <span class="pos-product-name">{{ $product->display_name }}</span>
                            <span class="pos-product-points">{{ number_format($product->points_per_unit, 2) }} نقطة/وحدة</span>
                        </button>
                    @empty
                        <p class="pos-empty">لا توجد منتجات في هذا التصنيف</p>
                    @endforelse
                </div>
            </section>

            {{-- ── الفاتورة / السلة ── --}}
            <aside class="pos-cart">
                <p class="pos-side-title">🧾 الفاتورة</p>

                @if(empty($cart))
                    <p class="pos-empty">السلة فارغة — اختر منتجات</p>
                @else
                    <div class="pos-cart-lines">
                        @foreach($cart as $key => $line)
                            <div class="pos-cart-line" wire:key="cart-{{ $key }}">
                                <div class="pos-cart-line-top">
                                    <span class="pos-cart-name">{{ $line['name'] }}</span>
                                    <button type="button" wire:click="removeLine('{{ $key }}')" class="pos-remove">×</button>
                                </div>
                                <div class="pos-cart-line-mid">
                                    <div class="pos-qty">
                                        <button type="button" wire:click="decrementQty('{{ $key }}')">−</button>
                                        @if(!empty($line['pieces_per_box']))
                                            <input type="number" min="1"
                                                value="{{ $line['boxes'] }}"
                                                wire:change="setBoxes('{{ $key }}', $event.target.value)">
                                        @else
                                            <input type="number" min="1"
                                                value="{{ $line['quantity'] }}"
                                                wire:change="setQuantity('{{ $key }}', $event.target.value)">
                                        @endif
                                        <button type="button" wire:click="incrementQty('{{ $key }}')">+</button>
                                    </div>
                                </div>
                                <div class="pos-cart-line-bot">
                                    @if(!empty($line['pieces_per_box']))
                                        <span>{{ $line['boxes'] }} صندوق × {{ $line['pieces_per_box'] }} = {{ number_format($line['quantity']) }} قطعة</span>
                                    @else
                                        <span>{{ number_format($line['quantity']) }} وحدة</span>
                                    @endif
                                    <span class="pos-line-points">
                                        ⭐ {{ (int) floor($line['quantity'] * $line['points_per_unit']) }} نقطة
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="pos-totals">
                    <div class="pos-total-row pos-total-row--points">
                        <span>إجمالي النقاط (للتوزيع)</span>
                        <strong>{{ number_format($this->totalPoints) }} نقطة</strong>
                    </div>
                </div>

                <button type="button"
                    wire:click="openReview"
                    class="pos-issue-btn"
                    @disabled(empty($cart))>
                    مراجعة الفاتورة قبل الحفظ
                </button>

                <p class="pos-hint">الحفظ يتم بعد مراجعة الكميات والنقاط وموزع الجملة</p>
            </aside>
        </div>
    </div>

    @if($reviewing)
        <div class="pos-review-backdrop" dir="rtl">
            <div class="pos-review" role="dialog" aria-modal="true" aria-labelledby="pos-review-title">
                <header class="pos-review-head">
                    <div>
                        <p class="pos-review-kicker">مراجعة قبل الحفظ</p>
                        <h2 id="pos-review-title">فاتورة {{ $this->nextInvoiceNumber }}</h2>
                    </div>
                    <button type="button" wire:click="closeReview" class="pos-review-close">رجوع</button>
                </header>

                <div class="pos-review-meta">
                    <div>
                        <span>موزع الجملة</span>
                        <strong>{{ $this->selectedWholesalerName ?: 'لم يُختر' }}</strong>
                    </div>
                    <div>
                        <span>الأصناف</span>
                        <strong>{{ count($cart) }}</strong>
                    </div>
                    <div>
                        <span>الوحدات</span>
                        <strong>{{ number_format($this->totalUnits) }}</strong>
                    </div>
                    <div>
                        <span>إجمالي النقاط</span>
                        <strong>{{ number_format($this->totalPoints) }}</strong>
                    </div>
                </div>

                @if($this->reviewIssues !== [])
                    <ul class="pos-review-issues">
                        @foreach($this->reviewIssues as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                @endif

                <div class="pos-review-table-wrap">
                    <table class="pos-review-table">
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th>العدد</th>
                                <th>العبوة</th>
                                <th>الكمية</th>
                                <th>نقطة / وحدة</th>
                                <th>النقاط</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($cart as $key => $line)
                                <tr wire:key="review-{{ $key }}" @class(['pos-review-row--warn' => (float) $line['points_per_unit'] <= 0 || (int) $line['quantity'] < 1])>
                                    <td>
                                        <div class="pos-review-product">
                                            <span>{{ $line['name'] }}</span>
                                            <button type="button" wire:click="removeLine('{{ $key }}')" class="pos-remove">×</button>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="pos-qty">
                                            <button type="button" wire:click="decrementQty('{{ $key }}')">−</button>
                                            @if(!empty($line['pieces_per_box']))
                                                <input type="number" min="1"
                                                    value="{{ $line['boxes'] }}"
                                                    wire:change="setBoxes('{{ $key }}', $event.target.value)">
                                            @else
                                                <input type="number" min="1"
                                                    value="{{ $line['quantity'] }}"
                                                    wire:change="setQuantity('{{ $key }}', $event.target.value)">
                                            @endif
                                            <button type="button" wire:click="incrementQty('{{ $key }}')">+</button>
                                        </div>
                                    </td>
                                    <td>
                                        @if(!empty($line['pieces_per_box']))
                                            {{ $line['boxes'] }} صندوق × {{ $line['pieces_per_box'] }}
                                        @else
                                            بالوحدة
                                        @endif
                                    </td>
                                    <td>{{ number_format($line['quantity']) }}</td>
                                    <td>{{ number_format((float) $line['points_per_unit'], 2) }}</td>
                                    <td>{{ number_format((int) floor($line['quantity'] * $line['points_per_unit'])) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <footer class="pos-review-actions">
                    <button type="button" wire:click="closeReview" class="pos-review-back">رجوع للتعديل</button>
                    <button type="button"
                        wire:click="confirmIssue"
                        wire:loading.attr="disabled"
                        class="pos-issue-btn"
                        @disabled($this->reviewIssues !== [])>
                        <span wire:loading.remove wire:target="confirmIssue">تأكيد وحفظ الفاتورة</span>
                        <span wire:loading wire:target="confirmIssue">جاري الحفظ...</span>
                    </button>
                </footer>
            </div>
        </div>
    @endif
</x-filament-panels::page>
