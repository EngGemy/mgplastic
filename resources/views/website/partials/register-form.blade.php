<div style="margin-bottom:20px">
    <div class="fm" style="font-size:9px;color:var(--blue);letter-spacing:.15em;text-transform:uppercase;margin-bottom:4px" id="fp-eyebrow">Step 01 — Account Type</div>
    <h3 style="font-family:'Amiri',serif;font-size:1.6rem;color:var(--ink)" id="fp-title">انضم للشبكة</h3>
    <p style="font-size:12px;color:var(--muted);margin-top:2px" id="fp-sub">اختر نوع حسابك للبدء</p>
</div>

<div style="display:flex;align-items:center;margin-bottom:20px">
    <div class="step-circle sc-active" id="fsc1">١</div>
    <div class="step-line" id="fsl1"></div>
    <div class="step-circle sc-idle" id="fsc2">٢</div>
    <div class="step-line" id="fsl2"></div>
    <div class="step-circle sc-idle" id="fsc3">٣</div>
</div>

<div id="fp1">
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
        <button type="button" class="role-pill" data-role="wholesale_distributor" onclick="fSelectRole(this)">
            <div style="width:36px;height:36px;background:#dbeafe;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="ti ti-truck" style="font-size:18px;color:var(--blue)"></i>
            </div>
            <div style="flex:1;text-align:right">
                <div style="font-weight:700;color:var(--ink);font-size:13px">موزع الجملة</div>
                <div class="fm" style="font-size:9px;color:var(--hint);letter-spacing:.06em">WHOLESALE DISTRIBUTOR</div>
            </div>
            <i class="ti ti-check rp-check"></i>
        </button>
        <button type="button" class="role-pill" data-role="retail_trader" onclick="fSelectRole(this)">
            <div style="width:36px;height:36px;background:#d1fae5;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="ti ti-building-store" style="font-size:18px;color:#059669"></i>
            </div>
            <div style="flex:1;text-align:right">
                <div style="font-weight:700;color:var(--ink);font-size:13px">تاجر القطاعي</div>
                <div class="fm" style="font-size:9px;color:var(--hint);letter-spacing:.06em">RETAIL TRADER</div>
            </div>
            <i class="ti ti-check rp-check"></i>
        </button>
        <button type="button" class="role-pill" data-role="plumber" onclick="fSelectRole(this)">
            <div style="width:36px;height:36px;background:#fde68a;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="ti ti-tool" style="font-size:18px;color:#d97706"></i>
            </div>
            <div style="flex:1;text-align:right">
                <div style="font-weight:700;color:var(--ink);font-size:13px">سباك</div>
                <div class="fm" style="font-size:9px;color:var(--hint);letter-spacing:.06em">PLUMBER</div>
            </div>
            <i class="ti ti-check rp-check"></i>
        </button>
    </div>
    <button type="button" class="btn-primary" id="fp1-btn" onclick="fGo(2)" disabled style="width:100%;justify-content:center">
        التالي <i class="ti ti-arrow-left"></i>
    </button>
</div>

<div id="fp2" style="display:none">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <div style="grid-column:1/-1">
            <label class="form-label">الاسم الكامل *</label>
            <input class="form-input" type="text" id="fp-name" placeholder="الاسم الثلاثي">
        </div>
        <div>
            <label class="form-label">رقم الهاتف *</label>
            <input class="form-input" type="tel" id="fp-phone" placeholder="09XXXXXXXX" dir="ltr" style="text-align:right">
        </div>
        <div>
            <label class="form-label">المدينة *</label>
            <select class="form-input" id="fp-city">
                <option value="">— اختر —</option>
                @foreach($cities as $city)
                    <option value="{{ $city->id }}">{{ $city->name_ar ?? $city->name_en }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">كلمة المرور *</label>
            <div class="pass-wrap">
                <input class="form-input" type="password" id="fp-pass" placeholder="٦+ أحرف" style="padding-left:34px">
                <i class="ti ti-eye pass-eye" id="fpe1" onclick="fTogglePass('fp-pass','fpe1')"></i>
            </div>
        </div>
        <div>
            <label class="form-label">تأكيد المرور *</label>
            <div class="pass-wrap">
                <input class="form-input" type="password" id="fp-pass2" placeholder="أعد الكتابة" style="padding-left:34px">
                <i class="ti ti-eye pass-eye" id="fpe2" onclick="fTogglePass('fp-pass2','fpe2')"></i>
            </div>
        </div>
    </div>
    <div class="err-msg" id="fp-err2"></div>
    <div style="display:flex;gap:8px;margin-top:8px">
        <button type="button" class="btn-out" style="flex:1;justify-content:center" onclick="fGo(1)">
            <i class="ti ti-arrow-right"></i>
        </button>
        <button type="button" class="btn-primary" style="flex:2;justify-content:center" onclick="fValidate2()">
            التالي <i class="ti ti-arrow-left"></i>
        </button>
    </div>
</div>

<div id="fp3" style="display:none">
    <div style="margin-bottom:12px" id="fp3-biz">
        <label class="form-label">اسم الشركة / المتجر</label>
        <input class="form-input" type="text" id="fp-biz" placeholder="اختياري">
    </div>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:11px 14px;margin-bottom:12px;font-size:12px;color:#92400e;line-height:1.65;display:none" id="fp3-plumb">
        <i class="ti ti-info-circle" style="color:#d97706"></i>
        ستُضاف لأقرب تاجر قطاعي في منطقتك عند الموافقة.
    </div>
    <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:12px;cursor:pointer">
        <input type="checkbox" id="fp-terms" style="margin-top:3px;accent-color:var(--blue);width:14px;height:14px;flex-shrink:0">
        <span style="font-size:11px;color:var(--muted);line-height:1.65">أوافق على <a href="{{ $termsUrl }}" style="color:var(--blue);font-weight:700;text-decoration:none">شروط الاستخدام</a> وأفهم أن الحساب يحتاج موافقة الإدارة</span>
    </label>
    <div class="err-msg" id="fp-err3"></div>
    <div style="display:flex;gap:8px">
        <button type="button" class="btn-out" style="flex:1;justify-content:center" onclick="fGo(2)">
            <i class="ti ti-arrow-right"></i>
        </button>
        <button type="button" class="btn-primary" style="flex:2;justify-content:center" onclick="fSubmit()">
            <i class="ti ti-send"></i> إرسال الطلب
        </button>
    </div>
</div>

<div id="fp-success" style="display:none;text-align:center;padding:8px 0">
    <div style="width:64px;height:64px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px" class="pop-in">
        <i class="ti ti-check" style="font-size:28px;color:#16a34a"></i>
    </div>
    <div style="font-family:'Amiri',serif;font-size:1.6rem;color:var(--ink);margin-bottom:4px">تم إرسال طلبك!</div>
    <div class="fm" style="font-size:10px;color:#16a34a;letter-spacing:.1em;margin-bottom:16px">// pending_review</div>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:right;font-size:12px;color:var(--muted);line-height:1.8;margin-bottom:16px">
        ✓ مراجعة طلبك خلال ٢٤–٤٨ ساعة<br>
        ✓ SMS على رقمك عند الموافقة<br>
        <span id="fps-msg">✓ دخول لوحة التحكم الخاصة بك</span>
    </div>
    <a id="fps-link" href="{{ route('portal') }}" class="btn-primary" style="justify-content:center;width:100%;text-decoration:none">
        دخول بوابتي <i class="ti ti-arrow-left"></i>
    </a>
</div>
