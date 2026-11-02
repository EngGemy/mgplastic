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
                $mc = (array) ($ocrDraft['match_counts'] ?? []);
                $readyN = (int) ($mc['matched'] ?? 0);
                $choiceN = (int) ($mc['needs_choice'] ?? 0);
                $exclN = count($ocrDraft['excluded'] ?? []);
                $rawN = (int) ($ocrDraft['raw_line_count'] ?? count($ocrDraft['lines'] ?? []) + $exclN);
                $approvedN = $this->ocrApprovedCount;
            @endphp
            <div class="pos-ocr-review" dir="rtl"
                 x-data
                 @keydown.window="
                    const k = $event.key;
                    if (['a','A','e','E','1','2','3','Enter'].includes(k)) {
                      $wire.ocrKeyboard(k === 'Enter' ? 'enter' : k);
                    }
                 "
                 style="display:grid;grid-template-columns:minmax(200px,280px) 1fr;gap:1rem;margin:1rem 0;padding:1rem;border:1px solid #111;background:#fff;color:#111">
                <div>
                    <p class="pos-side-title">الصورة</p>
                    @if($ocrPreviewUrl)
                        <a href="{{ $ocrPreviewUrl }}" target="_blank" rel="noopener">
                            <img src="{{ $ocrPreviewUrl }}" alt="فاتورة" style="width:100%;max-height:420px;object-fit:contain;border:1px solid #111">
                        </a>
                    @endif
                    <p class="pos-invoice-no-hint" style="margin-top:.5rem">اختصارات: A أمريكي · E أوروبي · 1-3 مرشح · Enter اعتماد</p>
                </div>
                <div>
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between;margin-bottom:.75rem">
                        <div>
                            <p class="pos-side-title" style="margin:0">مراجعة OCR</p>
                            <p class="pos-invoice-no-hint" style="margin:0">
                                مقروء {{ $rawN }} · جاهز {{ $readyN }} · يحتاج اختيار {{ $choiceN }} · مستبعد {{ $exclN }}
                            </p>
                        </div>
                        <div style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap">
                            <span style="font-size:.85rem">الافتراضي:</span>
                            <button type="button" class="pos-sheet-btn" style="{{ $ocrSystemDefault==='ask' ? 'background:#111;color:#fff' : '' }}" wire:click="setOcrSystemDefault('ask')">اسأل</button>
                            <button type="button" class="pos-sheet-btn" style="{{ $ocrSystemDefault==='us' ? 'background:#111;color:#fff' : '' }}" wire:click="setOcrSystemDefault('us')">أمريكي</button>
                            <button type="button" class="pos-sheet-btn" style="{{ $ocrSystemDefault==='eu' ? 'background:#111;color:#fff' : '' }}" wire:click="setOcrSystemDefault('eu')">أوروبي</button>
                            <label style="font-size:.85rem;display:flex;gap:.25rem;align-items:center">
                                <input type="checkbox" wire:model.live="ocrRememberForCustomer"> تذكّر للعميل
                            </label>
                        </div>
                    </div>

                    @if(is_array($ocrDraft))
                        <div style="overflow:auto;max-height:480px;display:flex;flex-direction:column;gap:.6rem">
                            @foreach(($ocrDraft['lines'] ?? []) as $i => $line)
                                @php
                                    $slots = (array) ($line['slots'] ?? []);
                                    $status = $line['match_status'] ?? $line['match_decision'] ?? '';
                                    $needs = in_array($status, ['needs_choice', 'mg_family_unknown_variant'], true) || in_array('needs_choice', (array)($line['needs_review'] ?? []), true);
                                    $cands = (array) ($line['candidates'] ?? []);
                                    $usCand = collect($cands)->firstWhere('system', 'us');
                                    $euCand = collect($cands)->firstWhere('system', 'eu');
                                    $systemsPresent = collect($cands)->pluck('system')->filter()->unique()->values();
                                    $focused = $ocrFocusLine === $i;
                                @endphp
                                <div style="border:1px solid #111;padding:.75rem;background:{{ $focused ? '#f5f5f5' : '#fff' }}">
                                    <div style="display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap">
                                        <div>
                                            <div style="font-size:1rem">«{{ $line['raw_text'] ?? '' }}»</div>
                                            <div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.35rem;font-size:.8rem">
                                                <span style="border:1px solid #111;padding:.1rem .35rem">النوع {{ $slots['family'] ?? '?' }} {{ ($slots['family'] ?? null) ? '✓' : '?' }}</span>
                                                <span style="border:1px solid #111;padding:.1rem .35rem">المقاس {{ $slots['size_value'] ?? '?' }}{{ ($slots['size_unit'] ?? '') === 'mm' ? 'مم' : '"' }} {{ ($slots['size_value'] ?? null) ? '✓' : '?' }}</span>
                                                <span style="border:1px solid #111;padding:.1rem .35rem">النظام {{ $slots['system'] === 'us' ? 'أمريكي' : ($slots['system'] === 'eu' ? 'أوروبي' : '?') }} {{ ($slots['system'] ?? null) ? '✓' : '?' }}</span>
                                            </div>
                                            @if(!empty($line['match_reason']))
                                                <div style="font-size:.8rem;margin-top:.25rem;opacity:.75">{{ $line['match_reason'] }}</div>
                                            @endif
                                        </div>
                                        <div style="display:flex;align-items:center;gap:.35rem">
                                            <button type="button" class="pos-sheet-btn" wire:click="adjustOcrQty({{ $i }}, -1)">−</button>
                                            <strong>{{ $line['qty'] ?? 1 }}</strong>
                                            <button type="button" class="pos-sheet-btn" wire:click="adjustOcrQty({{ $i }}, 1)">+</button>
                                            <label style="margin-inline-start:.5rem;display:flex;gap:.25rem;align-items:center">
                                                <input type="checkbox" @checked(!empty($line['approved'])) wire:click="toggleOcrLineApproved({{ $i }})">
                                                اعتماد
                                            </label>
                                        </div>
                                    </div>

                                    @if($needs && $usCand && $euCand)
                                        <div style="display:flex;gap:0;margin-top:.5rem;border:1px solid #111;width:fit-content">
                                            <button type="button" class="pos-sheet-btn" style="border:0;border-left:1px solid #111;border-radius:0;{{ ($line['matched_product_id'] ?? null)==($usCand['product_id'] ?? null) ? 'background:#111;color:#fff' : '' }}"
                                                wire:click="chooseOcrSystem({{ $i }}, 'us')">أمريكي #{{ $usCand['product_id'] }}</button>
                                            <button type="button" class="pos-sheet-btn" style="border:0;border-radius:0;{{ ($line['matched_product_id'] ?? null)==($euCand['product_id'] ?? null) ? 'background:#111;color:#fff' : '' }}"
                                                wire:click="chooseOcrSystem({{ $i }}, 'eu')">أوروبي #{{ $euCand['product_id'] }}</button>
                                        </div>
                                    @elseif($needs && $systemsPresent->count() === 1)
                                        <div style="margin-top:.5rem;font-size:.85rem">
                                            {{ $systemsPresent->first() === 'us' ? 'أمريكي فقط لهذا المقاس' : 'أوروبي فقط لهذا المقاس' }}
                                        </div>
                                    @endif

                                    @if(!empty($line['matched_product_id']))
                                        <div style="display:flex;gap:.75rem;margin-top:.5rem;align-items:center">
                                            @if(!empty($line['main_image']))
                                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($line['main_image']) }}" alt="" style="width:48px;height:48px;object-fit:cover;border:1px solid #111">
                                            @endif
                                            <div>
                                                <strong>{{ $line['name'] ?? '' }}</strong>
                                                <div style="font-size:.85rem">
                                                    وحدة/صندوق: {{ $line['pieces_per_box'] ?? '—' }}
                                                    · نقاط/وحدة: {{ $line['points_per_unit'] ?? '—' }}
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    @if($cands !== [])
                                        <div style="margin-top:.4rem;display:flex;flex-wrap:wrap;gap:.35rem">
                                            @foreach($cands as $ci => $cand)
                                                <button type="button" class="pos-sheet-btn"
                                                    wire:click="selectOcrCandidate({{ $i }}, {{ (int) $cand['product_id'] }})">
                                                    {{ $ci+1 }}) {{ $cand['name'] }}
                                                    @if(!empty($cand['system'])) ({{ $cand['system'] === 'us' ? 'أمريكي' : 'أوروبي' }}) @endif
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach

                            @foreach(($ocrDraft['excluded'] ?? []) as $ei => $ex)
                                <div style="border:1px solid #999;padding:.75rem;color:#666;text-decoration:line-through;background:#fafafa">
                                    <div>«{{ $ex['raw_text'] ?? '' }}» × {{ $ex['qty'] ?? '?' }}</div>
                                    <div style="text-decoration:none;font-size:.85rem;margin-top:.25rem">مستبعد: {{ $ex['reason'] ?? 'not_mg' }}</div>
                                    <button type="button" class="pos-sheet-btn" style="text-decoration:none;margin-top:.35rem" wire:click="restoreExcludedOcrLine({{ $ei }})">إضافة يدوياً</button>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div style="display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap">
                        <button type="button" class="pos-sheet-btn" wire:click="approveAllGreenOcrLines">اعتماد الجاهز</button>
                        <button type="button" class="pos-sheet-btn pos-sheet-btn--upload" wire:click="applyApprovedOcrToCart">اعتمد {{ $approvedN }} وأضِف للسلة</button>
                        <button type="button" class="pos-sheet-btn" wire:click="dismissOcrDraft">إغلاق</button>
                    </div>
                </div>
            </div>
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
