{{-- Styles for invoice return modal — injected once via ViewField --}}
<style>
.ret-shell { direction: rtl; font-family: 'Cairo', sans-serif; color: #0f172a; }

.ret-banner {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 4px;
}
@media (max-width: 640px) {
    .ret-banner { grid-template-columns: 1fr; }
}
.ret-banner-card {
    border-radius: 14px;
    padding: 12px 14px;
    border: 1px solid transparent;
}
.ret-banner-card .k { font-size: 11px; font-weight: 800; opacity: .85; margin-bottom: 4px; }
.ret-banner-card .v { font-size: 13px; font-weight: 900; line-height: 1.35; }
.ret-b-info { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
.ret-b-warn { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
.ret-b-ok   { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }

.ret-hint {
    margin: 10px 0 14px;
    padding: 10px 12px;
    border-radius: 12px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    line-height: 1.55;
}

.fi-modal-content .fi-fo-repeater-item,
.ret-shell .fi-fo-repeater-item {
    border: 1.5px solid #e2e8f0 !important;
    border-radius: 16px !important;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%) !important;
    box-shadow: none !important;
    overflow: hidden;
    margin-bottom: 10px !important;
}
.fi-modal-content .fi-fo-repeater-item-content,
.ret-shell .fi-fo-repeater-item-content {
    padding: 14px !important;
}

.ret-line {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) repeat(2, minmax(88px, .55fr));
    gap: 10px;
    align-items: center;
    margin-bottom: 4px;
}
@media (max-width: 720px) {
    .ret-line { grid-template-columns: 1fr 1fr; }
}

.ret-prod-name {
    font-size: 15px;
    font-weight: 900;
    color: #0f172a;
    margin-bottom: 6px;
}
.ret-pills { display: flex; flex-wrap: wrap; gap: 6px; }
.ret-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
}
.ret-pill-avail { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.ret-pill-ppu   { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }

.ret-meta-box {
    border-radius: 12px;
    padding: 10px 10px;
    text-align: center;
    border: 1px solid transparent;
}
.ret-meta-box .lbl { font-size: 10px; font-weight: 800; margin-bottom: 2px; opacity: .8; }
.ret-meta-box .val { font-size: 15px; font-weight: 900; }
.ret-meta-avail { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
.ret-meta-ppu   { background: #fffbeb; border-color: #fde68a; color: #b45309; }

.ret-pts-live {
    border-radius: 12px;
    padding: 10px 12px;
    text-align: center;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 64px;
}
.ret-pts-live .lbl { font-size: 10px; font-weight: 800; margin-bottom: 2px; opacity: .85; }
.ret-pts-live .val { font-size: 16px; font-weight: 900; }

.ret-qty-wrap .fi-fo-field-wrp-label span,
.ret-qty-wrap label span {
    font-size: 11px !important;
    font-weight: 800 !important;
    color: #64748b !important;
}
.fi-modal-content .ret-qty-wrap input,
.ret-shell .ret-qty-wrap input {
    text-align: center;
    font-weight: 900 !important;
    font-size: 18px !important;
    min-height: 44px;
    border-radius: 12px !important;
    border-color: #fca5a5 !important;
    background: #fff !important;
}
.fi-modal-content .ret-qty-wrap input:focus {
    border-color: #ef4444 !important;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, .15) !important;
}

.ret-totals {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 16px;
    background: linear-gradient(120deg, #7f1d1d 0%, #b91c1c 45%, #9a3412 100%);
    color: #fff;
    margin: 4px 0 12px;
}
.ret-totals-title { font-size: 12px; font-weight: 800; opacity: .9; margin-bottom: 4px; }
.ret-totals-vals { display: flex; flex-wrap: wrap; gap: 8px; }
.ret-totals-chip {
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.22);
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 13px;
    font-weight: 900;
}
.ret-totals-note { font-size: 11px; font-weight: 700; opacity: .88; max-width: 240px; line-height: 1.4; }

.fi-modal-content .fi-fo-textarea textarea {
    border-radius: 12px !important;
    border-color: #e2e8f0 !important;
    font-weight: 600 !important;
}
.ret-note-label {
    display: block;
    font-size: 12px;
    font-weight: 800;
    color: #475569;
    margin-bottom: 2px;
}
</style>

<div class="ret-shell" wire:ignore.self>
    <div class="ret-banner">
        <div class="ret-banner-card ret-b-info">
            <div class="k">الخطوة 1</div>
            <div class="v">حدّد كمية المرتجع لكل بند</div>
        </div>
        <div class="ret-banner-card ret-b-warn">
            <div class="k">الخطوة 2</div>
            <div class="v">النقاط تُخصم من المستلم</div>
        </div>
        <div class="ret-banner-card ret-b-ok">
            <div class="k">الخطوة 3</div>
            <div class="v">تُعاد للمورّد ويُحدَّث الصافي</div>
        </div>
    </div>
    <div class="ret-hint">اترك الكمية 0 للبنود التي لا تريد إرجاعها. الإجمالي يتحدّث تلقائياً أثناء الإدخال.</div>
</div>
