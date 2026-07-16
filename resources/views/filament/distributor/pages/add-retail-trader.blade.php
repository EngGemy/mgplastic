<x-filament-panels::page>
<style>
.mg-add{direction:rtl;font-family:'Cairo',sans-serif;max-width:920px;margin:0 auto}
.mg-add-hero{background:linear-gradient(135deg,#0f3d91,#1a56db);color:#fff;border-radius:16px;padding:20px 22px;margin-bottom:18px}
.mg-add-hero h2{margin:0;font-size:1.25rem;font-weight:900}
.mg-add-hero p{margin:6px 0 0;opacity:.9;font-size:13px;line-height:1.6}
.mg-tabs{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.mg-tab{border:1.5px solid #e2e8f0;background:#fff;border-radius:14px;padding:14px 16px;cursor:pointer;text-align:right;transition:.15s}
.mg-tab:hover{border-color:#93c5fd}
.mg-tab.active{border-color:#1a56db;background:#eff6ff;box-shadow:0 0 0 3px rgba(26,86,219,.12)}
.mg-tab-title{font-weight:800;font-size:14px;color:#0f172a}
.mg-tab-sub{font-size:12px;color:#64748b;margin-top:4px}
.mg-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;padding:18px}
.mg-label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px}
.mg-input,.mg-select{width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:inherit;font-size:13px}
.mg-input:focus,.mg-select:focus{outline:none;border-color:#1a56db}
.mg-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:700px){.mg-grid,.mg-tabs{grid-template-columns:1fr}}
.mg-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 18px;border:none;border-radius:11px;font-family:inherit;font-weight:800;font-size:13px;cursor:pointer}
.mg-btn-primary{background:linear-gradient(135deg,#0f3d91,#1a56db);color:#fff;width:100%;margin-top:14px}
.mg-btn-soft{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.mg-preview{margin-top:14px;border:1.5px dashed #93c5fd;background:#f8fbff;border-radius:14px;padding:14px}
.mg-preview-code{font-size:1.35rem;font-weight:900;color:#1a56db;letter-spacing:.04em}
.mg-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
.mg-badge-ok{background:#d1fae5;color:#065f46}
.mg-badge-warn{background:#fef3c7;color:#92400e}
.mg-hint{font-size:12px;color:#64748b;margin-top:8px;line-height:1.5}
</style>

<div class="mg-add">
    <div class="mg-add-hero">
        <h2>شبكة تجار القطاعي</h2>
        <p>
            التاجر القطاعي حساب واحد في النظام — يمكن ربطه بعدة موزّعي جملة عبر
            <strong>الرقم الموحّد</strong>. اختر: مسجّل من قبل، أو تسجيل جديد.
        </p>
    </div>

    <div class="mg-tabs">
        <button type="button" class="mg-tab {{ $mode === 'existing' ? 'active' : '' }}" wire:click="setMode('existing')">
            <div class="mg-tab-title">مسجّل من قبل</div>
            <div class="mg-tab-sub">أدخل الرقم الموحّد أو الهاتف وابحث ثم أضفه لشبكتك</div>
        </button>
        <button type="button" class="mg-tab {{ $mode === 'new' ? 'active' : '' }}" wire:click="setMode('new')">
            <div class="mg-tab-title">تسجيل تاجر جديد</div>
            <div class="mg-tab-sub">إنشاء حساب قطاعي جديد وربطه بمتجرك مباشرة</div>
        </button>
    </div>

    @if($mode === 'existing')
        <div class="mg-card">
            <label class="mg-label">الرقم الموحّد أو الهاتف</label>
            <div style="display:flex;gap:8px">
                <input type="text" class="mg-input" wire:model="lookupCode" placeholder="مثال: MG-R-000012 أو 091XXXXXXX" style="flex:1">
                <button type="button" class="mg-btn mg-btn-soft" wire:click="lookup" wire:loading.attr="disabled">بحث</button>
            </div>
            <p class="mg-hint">اطلب من التاجر رقمه الموحّد الظاهر أعلى لوحة التحكم بجانب الشعار.</p>

            @if($preview)
                <div class="mg-preview">
                    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
                        <div>
                            <div style="font-weight:800;font-size:15px">{{ $preview['name'] }}</div>
                            @if($preview['brand_name'])
                                <div style="font-size:12px;color:#64748b">{{ $preview['brand_name'] }}</div>
                            @endif
                            <div style="font-size:12px;margin-top:4px">📞 {{ $preview['phone'] }}</div>
                        </div>
                        <div style="text-align:left">
                            <div class="mg-preview-code">{{ $preview['network_code'] }}</div>
                            @if($preview['already_linked'])
                                <span class="mg-badge mg-badge-ok">مرتبط بشبكتك</span>
                            @else
                                <span class="mg-badge mg-badge-warn">غير مرتبط بعد</span>
                            @endif
                        </div>
                    </div>

                    @if(! $preview['is_active'])
                        <p class="mg-hint" style="color:#b91c1c">هذا الحساب موقوف — لا يمكن إضافته.</p>
                    @elseif($preview['already_linked'])
                        <button type="button" class="mg-btn mg-btn-primary" disabled>موجود في شبكتك بالفعل</button>
                    @else
                        <button type="button" class="mg-btn mg-btn-primary" wire:click="confirmLink" wire:loading.attr="disabled">
                            إضافة لشبكتي ✓
                        </button>
                    @endif
                </div>
            @endif
        </div>
    @else
        <div class="mg-card">
            <div class="mg-grid">
                <div>
                    <label class="mg-label">اسم التاجر *</label>
                    <input type="text" class="mg-input" wire:model="name">
                    @error('name') <div style="color:#dc2626;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mg-label">الهاتف *</label>
                    <input type="text" class="mg-input" wire:model="phone" placeholder="رقم فريد — إن وُجد سيُطلب الرقم الموحّد">
                    @error('phone') <div style="color:#dc2626;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mg-label">كلمة المرور *</label>
                    <input type="password" class="mg-input" wire:model="password">
                    @error('password') <div style="color:#dc2626;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mg-label">اسم المتجر / البراند</label>
                    <input type="text" class="mg-input" wire:model="brand_name">
                </div>
                <div>
                    <label class="mg-label">البريد (اختياري)</label>
                    <input type="email" class="mg-input" wire:model="email">
                </div>
                <div>
                    <label class="mg-label">العنوان</label>
                    <input type="text" class="mg-input" wire:model="address">
                </div>
                <div>
                    <label class="mg-label">الدولة</label>
                    <select class="mg-select" wire:model.live="country_id">
                        <option value="">—</option>
                        @foreach($this->countries as $c)
                            <option value="{{ $c->id }}">{{ $c->name_ar ?? $c->name_en }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mg-label">المدينة</label>
                    <select class="mg-select" wire:model="city_id">
                        <option value="">—</option>
                        @foreach($this->cities as $city)
                            <option value="{{ $city->id }}">{{ $city->name_ar ?? $city->name_en }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="mg-hint">بعد التسجيل يُنشأ رقم موحّد تلقائياً (مثل MG-R-000045) ويظهر للتاجر في أعلى لوحته.</p>
            <button type="button" class="mg-btn mg-btn-primary" wire:click="registerNew" wire:loading.attr="disabled">
                تسجيل وإضافة لشبكتي
            </button>
        </div>
    @endif
</div>
</x-filament-panels::page>
