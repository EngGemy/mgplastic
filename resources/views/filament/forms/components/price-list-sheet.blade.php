@php
    $isIncluded = fn (array $line): bool => ! in_array($line['included'] ?? false, [false, 0, '0', 'false', 'off', '', null], true);
    $sectionOn = function (string $kind, int $id) use ($quoteLines, $isIncluded): bool {
        $lines = collect($quoteLines)->filter(function (array $line) use ($kind, $id) {
            return $kind === 'child'
                ? (int) ($line['category_id'] ?? 0) === $id
                : (int) ($line['parent_id'] ?? 0) === $id;
        });

        return $lines->isNotEmpty() && $lines->every($isIncluded);
    };
    $chosen = collect($quoteLines)->filter($isIncluded);
    $money = function (mixed $value): string {
        if ($value === null || $value === '') {
            return '0';
        }
        $number = (float) $value;
        if (abs($number - round($number)) < 0.0005) {
            return (string) (int) round($number);
        }

        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    };
@endphp

<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@600;700;800&display=swap" rel="stylesheet">

<div class="mg-pick">
    <div class="mg-pick__sections">
        <div class="mg-pick__label">أقسام العرض</div>
        @forelse($parents as $parent)
            <button
                type="button"
                class="mg-check {{ $sectionOn('parent', (int) $parent['id']) ? 'is-on' : '' }}"
                wire:click="toggleSection('parent', {{ (int) $parent['id'] }})"
            >
                <span class="mg-check__box">{{ $sectionOn('parent', (int) $parent['id']) ? '✓' : '' }}</span>
                <span>{{ $parent['name'] }}</span>
            </button>
            @foreach(collect($children)->where('parent_id', $parent['id']) as $child)
                <button
                    type="button"
                    class="mg-check is-child {{ $sectionOn('child', (int) $child['id']) ? 'is-on' : '' }}"
                    wire:click="toggleSection('child', {{ (int) $child['id'] }})"
                >
                    <span class="mg-check__box">{{ $sectionOn('child', (int) $child['id']) ? '✓' : '' }}</span>
                    <span>{{ $child['name'] }}</span>
                </button>
            @endforeach
        @empty
            <p class="mg-pick__hint">لا توجد أقسام منتجات بعد.</p>
        @endforelse
    </div>

    @if($chosen->isEmpty())
        <div class="mg-pick__blank">العرض فاضي. علّم قسمًا بعلامة الصح عشان يظهر وتعدّل أسعاره.</div>
    @else
        <div class="mg-pick__bar">
            <span>{{ $chosen->count() }} صنف</span>
            @if($showQuotePreview)
                <button type="button" wire:click="closeQuotePreview">رجوع للتعديل</button>
            @else
                <button type="button" class="is-preview" wire:click="openQuotePreview">معاينة الشكل</button>
            @endif
            <button type="button" wire:click="clearQuoteScope">تفريغ العرض</button>
        </div>

        @if($showQuotePreview)
            <article class="mg-paper" dir="rtl">
                <div class="mg-paper__rule"></div>
                <header class="mg-paper__head">
                    <img src="{{ asset('images/logo-light.png') }}" alt="MG Plastic">
                    <div>
                        <strong>MG Plastic</strong>
                        <span>قائمة أسعار</span>
                    </div>
                </header>
                <h2>{{ $quoteTitle ?: 'قائمة أسعار' }}</h2>
                @if(filled($quoteSubtitle))
                    <p class="mg-paper__sub">{{ $quoteSubtitle }}</p>
                @endif
                <table>
                    <thead>
                        <tr>
                            <th>ترتيب</th>
                            <th>نوع الصنف</th>
                            <th>عبوة الصندوق</th>
                            <th>سعر القطعة<br>درهم</th>
                            <th>سعر القطعة<br>دينار</th>
                            <th>سعر الصندوق<br>درهم</th>
                            <th>سعر الصندوق<br>دينار</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $section = null; $number = 0; @endphp
                        @foreach($quoteLines as $line)
                            @continue(! $isIncluded($line))
                            @if(($line['category_name'] ?? '') !== '' && ($line['category_name'] ?? '') !== $section)
                                @php $section = $line['category_name']; @endphp
                                <tr class="is-section"><td colspan="7">{{ $section }}</td></tr>
                            @endif
                            @php
                                $number++;
                                $pieces = (int) ($line['pieces_per_box'] ?? 0);
                            @endphp
                            <tr>
                                <td>{{ $number }}</td>
                                <td class="is-name">{{ $line['item_name'] }}</td>
                                <td>{{ $pieces > 0 ? $pieces.'-قطعة' : '—' }}</td>
                                <td>{{ $money($line['piece_price_aed'] ?? 0) }}</td>
                                <td>{{ $money($line['piece_price_lyd'] ?? 0) }}</td>
                                <td>{{ $money($line['box_price_aed'] ?? 0) }}</td>
                                <td>{{ $money($line['box_price_lyd'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </article>
        @else
            @php $previous = null; @endphp
            @foreach($quoteLines as $index => $line)
                @continue(! $isIncluded($line))
                @if(($line['category_name'] ?? '') !== $previous)
                    @php $previous = $line['category_name'] ?? ''; @endphp
                    <div class="mg-edit__section">{{ $previous }}</div>
                    <div class="mg-edit__head">
                        <span>الصنف</span>
                        <span>العبوة</span>
                        <span>قطعة درهم</span>
                        <span>قطعة دينار</span>
                        <span>صندوق درهم</span>
                        <span>صندوق دينار</span>
                    </div>
                @endif
                <div class="mg-edit__row">
                    <button type="button" class="mg-check is-on" wire:click="toggleLine({{ $index }})" title="إخراج الصنف">
                        <span class="mg-check__box">✓</span>
                    </button>
                    <input type="text" value="{{ $line['item_name'] }}" wire:model="quoteLines.{{ $index }}.item_name" autocomplete="off">
                    <input type="number" min="0" step="1" value="{{ $line['pieces_per_box'] }}" wire:model="quoteLines.{{ $index }}.pieces_per_box">
                    <input type="number" min="0" step="any" value="{{ $line['piece_price_aed'] }}" wire:model="quoteLines.{{ $index }}.piece_price_aed">
                    <input type="number" min="0" step="any" value="{{ $line['piece_price_lyd'] }}" wire:model="quoteLines.{{ $index }}.piece_price_lyd">
                    <input type="number" min="0" step="any" value="{{ $line['box_price_aed'] }}" wire:model="quoteLines.{{ $index }}.box_price_aed">
                    <input type="number" min="0" step="any" value="{{ $line['box_price_lyd'] }}" wire:model="quoteLines.{{ $index }}.box_price_lyd">
                </div>
            @endforeach
        @endif
    @endif
</div>
