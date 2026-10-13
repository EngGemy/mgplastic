@php
    $isIncluded = fn (array $line): bool => ! in_array($line['included'] ?? false, [false, 0, '0', 'false', 'off', '', null], true);
    $chosen = collect($quoteLines)->filter($isIncluded);
    $sectionCount = $chosen->pluck('category_name')->unique()->count();
    $searching = trim((string) $catalogSearch) !== '';
    $addLabel = $searching ? 'إضافة النتائج' : ($browseCategoryId ? 'إضافة القسم' : 'إضافة التصنيف');
    $canAdd = $searching || $browseParentId || $browseCategoryId;
@endphp

<div class="mg-studio">
    <section class="mg-pane mg-pane--quote">
        <header class="mg-pane__head">
            <div>
                <strong>العرض</strong>
                <span>
                    @if($chosen->isEmpty())
                        فاضي. أضف من الكتالوج.
                    @else
                        {{ $chosen->count() }} صنف في {{ $sectionCount }} {{ $sectionCount === 1 ? 'قسم' : 'أقسام' }}
                    @endif
                </span>
            </div>
            <div class="mg-pane__actions">
                @if($chosen->isNotEmpty())
                    <button type="button" wire:click="clearQuoteScope">تفريغ</button>
                @endif
                <button type="button" wire:click="addManualQuoteLine">سطر يدوي</button>
            </div>
        </header>

        <div class="mg-pane__body">
            @if($chosen->isEmpty())
                <p class="mg-studio__empty">اختَر تصنيفًا من الكتالوج، ثم أضف القسم كله أو صنفًا واحدًا. السعر يتراجع هنا، والطباعة تطلع بنفس الأقسام.</p>
            @endif

            @php $previous = null; @endphp
            @foreach($quoteLines as $index => $line)
                @continue(! $isIncluded($line))
                @if(($line['category_name'] ?? '') !== $previous)
                    @php $previous = $line['category_name'] ?? ''; @endphp
                    <div class="mg-q-section">
                        <span>{{ $previous }}</span>
                        <button type="button" wire:click="removeSectionAt({{ $index }})">حذف القسم</button>
                    </div>
                @endif
                <article class="mg-q-line">
                    <div class="mg-q-line__top">
                        <input type="text" value="{{ $line['item_name'] }}" wire:model="quoteLines.{{ $index }}.item_name" autocomplete="off">
                        <button type="button" wire:click="toggleLine({{ $index }})">إزالة</button>
                    </div>
                    <div class="mg-q-prices">
                        <label>العبوة<input type="number" min="0" step="1" value="{{ $line['pieces_per_box'] }}" wire:model="quoteLines.{{ $index }}.pieces_per_box"></label>
                        <label>قطعة درهم<input type="number" min="0" step="any" value="{{ $line['piece_price_aed'] }}" wire:model="quoteLines.{{ $index }}.piece_price_aed"></label>
                        <label>قطعة دينار<input type="number" min="0" step="any" value="{{ $line['piece_price_lyd'] }}" wire:model="quoteLines.{{ $index }}.piece_price_lyd"></label>
                        <label>صندوق درهم<input type="number" min="0" step="any" value="{{ $line['box_price_aed'] }}" wire:model="quoteLines.{{ $index }}.box_price_aed"></label>
                        <label>صندوق دينار<input type="number" min="0" step="any" value="{{ $line['box_price_lyd'] }}" wire:model="quoteLines.{{ $index }}.box_price_lyd"></label>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="mg-pane mg-pane--catalog">
        <header class="mg-pane__head">
            <div>
                <strong>الكتالوج</strong>
                <span>تصنيف كامل، أو أصناف من جواه.</span>
            </div>
        </header>

        <div class="mg-tree">
            @foreach($parents as $parent)
                <button
                    type="button"
                    wire:click="openCatalogParent({{ (int) $parent['id'] }})"
                    class="{{ (int) $browseParentId === (int) $parent['id'] && ! $browseCategoryId ? 'is-active' : '' }}"
                >{{ $parent['name'] }}</button>
                @foreach(collect($children)->where('parent_id', $parent['id']) as $child)
                    <button
                        type="button"
                        class="is-child {{ (int) $browseCategoryId === (int) $child['id'] ? 'is-active' : '' }}"
                        wire:click="openCatalogCategory({{ (int) $child['parent_id'] }}, {{ (int) $child['id'] }})"
                    >{{ $child['name'] }}</button>
                @endforeach
            @endforeach
            <button type="button" class="is-all" wire:click="applyAllProducts">إضافة كل المنتجات</button>
            @unless($quoteIsNew)
                <button type="button" class="is-all" wire:click="addMissingProducts">إضافة المنتجات الجديدة</button>
            @endunless
        </div>

        <div class="mg-catalog__tools">
            <input
                type="search"
                placeholder="ابحث عن صنف"
                wire:model.live.debounce.300ms="catalogSearch"
                wire:keydown.enter.prevent
                autocomplete="off"
            >
            @if($canAdd)
                <button type="button" wire:click="addBrowsedProducts">{{ $addLabel }}</button>
            @endif
        </div>

        <div class="mg-pane__body">
            @forelse($catalogRows as $row)
                <div class="mg-cat-row {{ $row['included'] ? 'is-in' : '' }}">
                    <div>
                        <strong>{{ $row['item_name'] }}</strong>
                        <small>
                            @if($searching){{ $row['category_name'] }} · @endif
                            {{ (int) ($row['pieces_per_box'] ?? 0) > 0 ? $row['pieces_per_box'].'-قطعة' : 'بدون عبوة' }}
                            · قطعة {{ $row['piece_price_lyd'] }} د.ل / {{ $row['piece_price_aed'] }} د.إ
                        </small>
                    </div>
                    <button type="button" wire:click="toggleLine({{ (int) $row['_index'] }})" class="{{ $row['included'] ? 'is-in' : '' }}">
                        {{ $row['included'] ? 'في العرض' : 'إضافة' }}
                    </button>
                </div>
            @empty
                <p class="mg-studio__empty">
                    @if($searching)
                        لا يوجد صنف بهذا الاسم.
                    @else
                        اختَر تصنيفًا من القائمة.
                    @endif
                </p>
            @endforelse
        </div>
    </section>
</div>
