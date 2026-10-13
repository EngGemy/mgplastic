<div
    class="mg-quote-sheet"
    x-data="{
        q: '',
        parent: null,
        category: null,
        children: @js($children),
        show(parentId, categoryId, name, categoryName) {
            if (this.parent && Number(this.parent) !== Number(parentId || 0)) return false;
            if (this.category && Number(this.category) !== Number(categoryId || 0)) return false;
            const needle = this.q.trim().toLowerCase();
            if (!needle) return true;
            return (String(name) + ' ' + String(categoryName)).toLowerCase().includes(needle);
        },
        showGroup(parentId, categoryId) {
            if (this.q) return false;
            if (this.parent && Number(this.parent) !== Number(parentId || 0)) return false;
            if (this.category && Number(this.category) !== Number(categoryId || 0)) return false;
            return true;
        },
        setIncluded(checked) {
            this.$root.querySelectorAll('[data-quote-row]').forEach((row) => {
                if (row.offsetParent === null) return;
                const box = row.querySelector('[data-include]');
                if (!box || box.checked === checked) return;
                box.checked = checked;
                box.dispatchEvent(new Event('input', { bubbles: true }));
                box.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }
    }"
>
    <div class="mg-quote-sheet__head">
        <div>
            <strong>مراجعة العرض</strong>
            <span>{{ count($quoteLines) }} منتج جاهز. عدّل السعر والتفاصيل، وألغِ تحديد ما لا يدخل العرض.</span>
        </div>
        <div class="mg-quote-sheet__actions">
            @unless($quoteIsNew)
                <button type="button" wire:click="addMissingProducts">أضف المنتجات الجديدة</button>
            @endunless
            <button type="button" wire:click="addManualQuoteLine">سطر يدوي</button>
        </div>
    </div>

    <div class="mg-quote-sheet__tools">
        <input type="search" placeholder="ابحث بالاسم أو التصنيف" x-model="q" @keydown.enter.prevent autocomplete="off">
        <button type="button" @click="setIncluded(false)">استبعاد الظاهر</button>
        <button type="button" @click="setIncluded(true)">إرجاع الظاهر</button>
    </div>

    <div class="mg-quote-chips">
        <button type="button" @click="parent = null; category = null" :class="parent ? '' : 'is-active'">الكل</button>
        @foreach($parents as $parent)
            <button
                type="button"
                @click="parent = {{ (int) $parent['id'] }}; category = null"
                :class="Number(parent) === {{ (int) $parent['id'] }} ? 'is-active' : ''"
            >{{ $parent['name'] }}</button>
        @endforeach
    </div>

    <div class="mg-quote-chips mg-quote-chips--sub" x-show="parent" x-cloak>
        <button type="button" @click="category = null" :class="category ? '' : 'is-active'">كل الفرعي</button>
        <template x-for="child in children.filter(item => Number(item.parent_id) === Number(parent))" :key="child.id">
            <button
                type="button"
                @click="category = child.id"
                :class="Number(category) === Number(child.id) ? 'is-active' : ''"
                x-text="child.name"
            ></button>
        </template>
    </div>

    <div class="mg-quote-sheet__scroll">
        <table>
            <thead>
                <tr>
                    <th>داخل العرض</th>
                    <th>الصنف</th>
                    <th>التصنيف</th>
                    <th>العبوة</th>
                    <th>قطعة درهم</th>
                    <th>قطعة دينار</th>
                    <th>صندوق درهم</th>
                    <th>صندوق دينار</th>
                </tr>
            </thead>
            <tbody>
                @php $previousGroup = null; @endphp
                @forelse($quoteLines as $index => $line)
                    @if(($line['category_name'] ?? '') !== $previousGroup)
                        @php $previousGroup = $line['category_name'] ?? ''; @endphp
                        <tr
                            class="mg-quote-sheet__group"
                            x-show="showGroup(@js($line['parent_id']), @js($line['category_id']))"
                        >
                            <td colspan="8">{{ $previousGroup }}</td>
                        </tr>
                    @endif
                    <tr
                        data-quote-row
                        x-show="show(@js($line['parent_id']), @js($line['category_id']), @js($line['item_name']), @js($line['category_name']))"
                    >
                        <td>
                            <input type="checkbox" data-include wire:model="quoteLines.{{ $index }}.included" @checked($line['included'])>
                        </td>
                        <td>
                            <input type="text" value="{{ $line['item_name'] }}" wire:model="quoteLines.{{ $index }}.item_name" autocomplete="off">
                        </td>
                        <td class="mg-quote-sheet__cat">{{ $line['category_name'] }}</td>
                        <td>
                            <input type="number" min="0" step="1" value="{{ $line['pieces_per_box'] }}" wire:model="quoteLines.{{ $index }}.pieces_per_box">
                        </td>
                        <td>
                            <input type="number" min="0" step="any" value="{{ $line['piece_price_aed'] }}" wire:model="quoteLines.{{ $index }}.piece_price_aed">
                        </td>
                        <td>
                            <input type="number" min="0" step="any" value="{{ $line['piece_price_lyd'] }}" wire:model="quoteLines.{{ $index }}.piece_price_lyd">
                        </td>
                        <td>
                            <input type="number" min="0" step="any" value="{{ $line['box_price_aed'] }}" wire:model="quoteLines.{{ $index }}.box_price_aed">
                        </td>
                        <td>
                            <input type="number" min="0" step="any" value="{{ $line['box_price_lyd'] }}" wire:model="quoteLines.{{ $index }}.box_price_lyd">
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">لا توجد منتجات بعد.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
