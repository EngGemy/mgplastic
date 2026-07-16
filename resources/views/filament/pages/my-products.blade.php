<x-filament-panels::page>
@php
    $items = $this->myProducts;
@endphp

<style>
.mp-shell { direction:rtl; font-family:'Cairo',sans-serif; color:#0f172a; }
.mp-hero {
    background: linear-gradient(125deg, #0f766e 0%, #115e59 50%, #134e4a 100%);
    color:#fff; border-radius:20px; padding:20px 22px; margin-bottom:18px;
    display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;
}
.mp-hero h2 { margin:0; font-size:1.35rem; font-weight:900; }
.mp-hero p { margin:6px 0 0; opacity:.88; font-size:13px; font-weight:600; max-width:520px; line-height:1.55; }
.mp-add {
    border:none; background:#fff; color:#0f766e; font:inherit; font-weight:900; font-size:13px;
    padding:11px 18px; border-radius:12px; cursor:pointer; box-shadow:0 8px 24px rgba(0,0,0,.12);
}
.mp-grid {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px;
}
.mp-card {
    background:#fff; border:1px solid #e2e8f0; border-radius:18px; overflow:hidden;
    box-shadow:0 10px 28px -18px rgba(15,23,42,.35); transition:transform .15s ease;
}
.mp-card:hover { transform:translateY(-3px); }
.mp-card.inactive { opacity:.55; }
.mp-img {
    aspect-ratio:1; width:100%; object-fit:cover; display:block; background:#f1f5f9;
}
.mp-body { padding:12px 14px 14px; }
.mp-name { font-size:14px; font-weight:900; margin:0 0 4px; line-height:1.35; }
.mp-desc { font-size:12px; color:#64748b; font-weight:600; margin:0; line-height:1.5; min-height:36px; }
.mp-badge {
    display:inline-block; margin-top:8px; font-size:10px; font-weight:800;
    background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; padding:3px 8px; border-radius:999px;
}
.mp-actions { display:flex; gap:6px; margin-top:10px; }
.mp-btn {
    flex:1; border:1px solid #e2e8f0; background:#f8fafc; color:#334155; border-radius:10px;
    padding:7px 8px; font:inherit; font-size:11px; font-weight:800; cursor:pointer;
}
.mp-btn.danger { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
.mp-empty {
    text-align:center; padding:48px 20px; background:#fff; border:1.5px dashed #cbd5e1; border-radius:20px;
}
.mp-empty h3 { margin:0 0 6px; font-size:1.1rem; font-weight:900; }
.mp-empty p { margin:0 0 16px; color:#64748b; font-size:13px; font-weight:600; }

.mp-modal-bg {
    position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:80;
    display:flex; align-items:center; justify-content:center; padding:16px;
}
.mp-modal {
    width:min(440px,100%); background:#fff; border-radius:20px; padding:18px;
    box-shadow:0 24px 60px rgba(15,23,42,.25);
}
.mp-modal h3 { margin:0 0 14px; font-size:1.05rem; font-weight:900; }
.mp-field { margin-bottom:12px; }
.mp-field label { display:block; font-size:11px; font-weight:800; color:#64748b; margin-bottom:6px; }
.mp-field input, .mp-field textarea {
    width:100%; border:1.5px solid #e2e8f0; border-radius:12px; padding:10px 12px;
    font:inherit; font-size:13px; font-weight:600;
}
.mp-field input:focus, .mp-field textarea:focus {
    outline:none; border-color:#0f766e; box-shadow:0 0 0 3px rgba(15,118,110,.12);
}
.mp-preview {
    width:100%; aspect-ratio:1; object-fit:cover; border-radius:14px; margin-top:8px; background:#f1f5f9;
}
.mp-modal-actions { display:flex; gap:8px; margin-top:14px; }
.mp-modal-actions button {
    flex:1; border:none; border-radius:12px; padding:11px; font:inherit; font-weight:900; font-size:13px; cursor:pointer;
}
.mp-save { background:#0f766e; color:#fff; }
.mp-cancel { background:#f1f5f9; color:#475569; }
.mp-error { color:#dc2626; font-size:11px; font-weight:700; margin-top:4px; }
</style>

<div class="mp-shell">
    <div class="mp-hero">
        <div>
            <h2>منتجاتي</h2>
            <p>معرض صور لمتجرك — صورة + اسم + وصف بسيط. تظهر للعملاء في صفحة المتجر عبر الـ API. بدون نقاط.</p>
        </div>
        <button type="button" class="mp-add" wire:click="openCreateForm">＋ إضافة منتج</button>
    </div>

    @if($items->isEmpty())
        <div class="mp-empty">
            <div style="font-size:42px;margin-bottom:8px">🖼️</div>
            <h3>لا منتجات بعد</h3>
            <p>أضف أول منتج بصورة واسم — يظهر كمعرض في متجرك</p>
            <button type="button" class="mp-add" wire:click="openCreateForm">إضافة أول منتج</button>
        </div>
    @else
        <div class="mp-grid">
            @foreach($items as $item)
                <article class="mp-card {{ $item->is_active ? '' : 'inactive' }}">
                    <img class="mp-img" src="{{ $item->url }}" alt="{{ $item->title }}">
                    <div class="mp-body">
                        <h3 class="mp-name">{{ $item->title ?: 'بدون اسم' }}</h3>
                        <p class="mp-desc">{{ $item->description ?: '—' }}</p>
                        <span class="mp-badge">بدون نقاط</span>
                        <div class="mp-actions">
                            <button type="button" class="mp-btn" wire:click="openEditForm({{ $item->id }})">تعديل</button>
                            <button type="button" class="mp-btn" wire:click="toggleActive({{ $item->id }})">
                                {{ $item->is_active ? 'إخفاء' : 'إظهار' }}
                            </button>
                            <button type="button" class="mp-btn danger"
                                wire:click="deleteProduct({{ $item->id }})"
                                wire:confirm="حذف هذا المنتج؟">حذف</button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>

@if($showForm)
    <div class="mp-modal-bg" wire:click.self="closeForm">
        <div class="mp-modal" wire:click.stop>
            <h3>{{ $editingId ? 'تعديل منتج' : 'إضافة منتج جديد' }}</h3>

            <div class="mp-field">
                <label>صورة المنتج {{ $editingId ? '(اختياري للتغيير)' : '*' }}</label>
                <input type="file" accept="image/*" wire:model="photo">
                @error('photo') <div class="mp-error">{{ $message }}</div> @enderror
                @if($photo)
                    <img class="mp-preview" src="{{ $photo->temporaryUrl() }}" alt="">
                @elseif($editingId)
                    @php $editItem = $items->firstWhere('id', $editingId); @endphp
                    @if($editItem)
                        <img class="mp-preview" src="{{ $editItem->url }}" alt="">
                    @endif
                @endif
            </div>

            <div class="mp-field">
                <label>اسم المنتج *</label>
                <input type="text" wire:model="productName" placeholder="مثال: محبس نحاس ½">
                @error('productName') <div class="mp-error">{{ $message }}</div> @enderror
            </div>

            <div class="mp-field">
                <label>ما هو المنتج؟ (وصف قصير)</label>
                <textarea rows="3" wire:model="productDescription" placeholder="وصف بسيط يظهر تحت الصورة..."></textarea>
                @error('productDescription') <div class="mp-error">{{ $message }}</div> @enderror
            </div>

            <div class="mp-modal-actions">
                <button type="button" class="mp-cancel" wire:click="closeForm">إلغاء</button>
                <button type="button" class="mp-save" wire:click="saveProduct" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveProduct">حفظ</span>
                    <span wire:loading wire:target="saveProduct">جاري الحفظ...</span>
                </button>
            </div>
        </div>
    </div>
@endif
</x-filament-panels::page>
